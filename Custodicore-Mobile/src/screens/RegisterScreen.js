import Ionicons from '@expo/vector-icons/Ionicons';
import GoogleGLogo from '../components/GoogleGLogo';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { pickPhotoFromGallery } from '../services/imagePickerService';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  BirthdateField,
  Button,
  Card,
  colors,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { formatBirthdateDisplay } from '../utils/formatDate';
import { useAuth } from '../hooks/useAuth';
import { getLegal } from '../services/api';
import {
  ACCEPTED_ID_TYPES,
  GENDER_OPTIONS,
  RELATIONSHIPS,
  getRelationshipLabel,
  getRequiredDocuments,
} from '../utils/registrationRequirements';
import { validateEmail, validatePassword, validateRequired } from '../utils';

const TOTAL_STEPS = 4;

const STEP_TITLES = {
  1: 'Account Information',
  2: 'Relationship To PDL',
  3: 'Visitor Verification Documents',
  4: 'Review & Submit',
};

function ProgressStepper({ step }) {
  const stepLabel = STEP_TITLES[step] ?? `Step ${step}`;
  return (
    <View
      style={stepperStyles.wrap}
      accessibilityRole="progressbar"
      accessibilityLabel={`Registration progress, step ${step} of ${TOTAL_STEPS}: ${stepLabel}`}
      accessibilityValue={{ min: 1, max: TOTAL_STEPS, now: step }}
    >
      <View style={stepperStyles.row}>
        {[1, 2, 3, 4].map((n, index) => {
          const done = n < step;
          const active = n === step;
          return (
            <React.Fragment key={n}>
              <View
                style={[
                  stepperStyles.circle,
                  done && stepperStyles.circleDone,
                  active && stepperStyles.circleActive,
                ]}
              >
                {done ? (
                  <Ionicons name="checkmark" size={16} color={colors.white} />
                ) : (
                  <Text
                    style={[
                      stepperStyles.circleText,
                      (active || done) && stepperStyles.circleTextActive,
                    ]}
                  >
                    {n}
                  </Text>
                )}
              </View>
              {index < 3 ? (
                <View
                  style={[
                    stepperStyles.line,
                    n < step && stepperStyles.lineDone,
                  ]}
                />
              ) : null}
            </React.Fragment>
          );
        })}
      </View>
      <Text style={stepperStyles.progressLabel} accessibilityElementsHidden importantForAccessibility="no">
        {step} of {TOTAL_STEPS}
      </Text>
    </View>
  );
}

function WizardField({
  label,
  value,
  onChangeText,
  error,
  placeholder,
  secureTextEntry,
  keyboardType,
  multiline,
  editable = true,
  onPress,
  autoCapitalize,
}) {
  const input = (
    <TextInput
      value={value}
      onChangeText={onChangeText}
      placeholder={placeholder}
      placeholderTextColor={colors.textSecondary}
      secureTextEntry={secureTextEntry}
      keyboardType={keyboardType}
      multiline={multiline}
      editable={editable && !onPress}
      autoCapitalize={autoCapitalize}
      style={[
        fieldStyles.input,
        multiline && fieldStyles.inputMultiline,
        error ? fieldStyles.inputError : null,
      ]}
    />
  );

  return (
    <View style={fieldStyles.wrap}>
      <Text style={fieldStyles.label}>{label}</Text>
      {onPress ? (
        <Pressable onPress={onPress}>{input}</Pressable>
      ) : (
        input
      )}
      {error ? <Text style={fieldStyles.error}>{error}</Text> : null}
    </View>
  );
}

/**
 * Google registration failures that end the Google flow (useAuth has already
 * dropped the held Google token): alert, then back to Login.
 */
const GOOGLE_RESTART_MESSAGES = {
  google_registration_expired:
    'Your Google sign-in has expired. Please sign in with Google again.',
  google_session_expired: 'Your Google sign-in has expired. Please sign in with Google again.',
  invalid_google_token: 'Your Google sign-in has expired. Please sign in with Google again.',
  google_email_mismatch:
    'The Google account on this device has changed. Please sign in with Google again.',
  google_account_mismatch:
    'This Google account is already registered. Please use Sign in with Google.',
  link_required:
    'A CustodiCore account already uses this email. Sign in with Google again to link it using your CustodiCore password.',
  not_visitor_account: 'This Google account cannot be used to register a visitor account.',
  account_inactive: 'This account is not active. Contact facility staff.',
};

/** Backend validation field → step-1 form field (Google registration). */
const GOOGLE_FIELD_ERRORS = {
  fullName: 'fullName',
  dateOfBirth: 'birthdate',
  gender: 'gender',
  address: 'address',
  contactNumber: 'contactNumber',
  password: 'password',
  password_confirmation: 'passwordConfirmation',
  email: 'email',
};

/**
 * 4-step visitor registration wizard (v2.1).
 *
 * Google mode (route param `googleProfile` from a `registration_required`
 * Google Sign-In): email is the verified Google email and read-only, the
 * name is prefilled but editable, and the visitor still sets a confirmed
 * CustodiCore password. Consent, relationship, documents and review are the
 * same as normal registration. The Google ID token never reaches this
 * screen — useAuth holds it and refreshes it on submit.
 */
export default function RegisterScreen({ navigation, route }) {
  const {
    register,
    registerWithGoogle,
    cancelGoogleRegistration,
    hasPendingGoogleRegistration,
  } = useAuth();
  const googleProfile = route?.params?.googleProfile ?? null;
  const isGoogle = Boolean(googleProfile?.email);
  const [step, setStep] = useState(1);
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState({});

  const [fullName, setFullName] = useState(() => googleProfile?.fullName ?? '');
  const [birthdate, setBirthdate] = useState('');
  const [gender, setGender] = useState('');
  const [address, setAddress] = useState('');
  const [contactNumber, setContactNumber] = useState('');
  const [email, setEmail] = useState(() => googleProfile?.email ?? '');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [showPassword, setShowPassword] = useState(false);

  const [relationship, setRelationship] = useState(null);
  const [documents, setDocuments] = useState({});
  const [certified, setCertified] = useState(false);

  const [genderModalVisible, setGenderModalVisible] = useState(false);
  const [idTypeModal, setIdTypeModal] = useState({ visible: false, docKey: null });

  // Consent comes BEFORE any details are collected: the wizard below is not
  // shown until the visitor accepts both the Terms & Conditions and the
  // Privacy Policy (same text as the web /terms and /privacy pages).
  const [consentDone, setConsentDone] = useState(false);
  const [acceptTerms, setAcceptTerms] = useState(false);
  const [acceptPrivacy, setAcceptPrivacy] = useState(false);
  const [legal, setLegal] = useState(null);
  const [legalError, setLegalError] = useState(null);
  const [legalDoc, setLegalDoc] = useState(null); // 'terms' | 'privacy' | null (modal)

  const loadLegal = useCallback(async () => {
    setLegalError(null);
    try {
      setLegal(await getLegal());
    } catch (e) {
      setLegalError(
        typeof e?.message === 'string' && e.message.trim()
          ? e.message
          : 'Could not load the document. Check your connection and try again.',
      );
    }
  }, []);

  useEffect(() => {
    loadLegal();
  }, [loadLegal]);

  const requiredDocs = useMemo(
    () => (relationship ? getRequiredDocuments(relationship) : []),
    [relationship],
  );

  // Google mode: leaving this screen in any way (back, hardware back, failure)
  // ends the Google flow. No-op after a successful registration, which
  // already cleared it. Normal registration never touches Google state.
  useEffect(() => {
    if (!isGoogle) return undefined;
    return () => cancelGoogleRegistration();
  }, [isGoogle, cancelGoogleRegistration]);

  /** Ends the Google flow and returns to Login (pops this screen). */
  const exitGoogleRegistration = useCallback(() => {
    cancelGoogleRegistration();
    navigation.popTo('Login');
  }, [cancelGoogleRegistration, navigation]);

  // Google mode without a held Google token (e.g. restored screen): restart.
  useEffect(() => {
    if (isGoogle && !hasPendingGoogleRegistration()) {
      Alert.alert(
        'Sign up with Google',
        GOOGLE_RESTART_MESSAGES.google_registration_expired,
      );
      exitGoogleRegistration();
    }
    // Mount-only check: after a successful registration the token is
    // cleared and the auth stack unmounts.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const goBack = useCallback(() => {
    if (step > 1) {
      setStep((s) => s - 1);
      setErrors({});
      return;
    }
    if (consentDone) {
      // Back from the first details step returns to the consent screen.
      setConsentDone(false);
      setErrors({});
      return;
    }
    if (isGoogle) {
      exitGoogleRegistration();
      return;
    }
    navigation.navigate('Login');
  }, [step, consentDone, isGoogle, exitGoogleRegistration, navigation]);

  const onConsentContinue = useCallback(() => {
    if (!acceptTerms || !acceptPrivacy) {
      setErrors({
        consent: 'Please accept both the Terms and Conditions and the Privacy Policy to continue.',
      });
      return;
    }
    setErrors({});
    setConsentDone(true);
  }, [acceptTerms, acceptPrivacy]);

  const validateStep1 = useCallback(() => {
    const next = {};
    if (!validateRequired(fullName)) next.fullName = 'Full name is required';
    if (!validateRequired(birthdate)) next.birthdate = 'Birthdate is required';
    if (!validateRequired(gender)) next.gender = 'Gender is required';
    if (!validateRequired(address)) next.address = 'Address is required';
    if (!validateRequired(contactNumber))
      next.contactNumber = 'Contact number is required';
    // Google mode: email is the verified Google email (read-only).
    if (!isGoogle) {
      if (!validateRequired(email)) next.email = 'Email address is required';
      else if (!validateEmail(email.trim())) next.email = 'Enter a valid email address';
    }
    if (!validateRequired(password)) next.password = 'Password is required';
    else if (!validatePassword(password))
      next.password = 'Password must be at least 6 characters';
    if (isGoogle) {
      if (!validateRequired(passwordConfirmation))
        next.passwordConfirmation = 'Please confirm your password';
      else if (passwordConfirmation !== password)
        next.passwordConfirmation = 'Passwords do not match';
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  }, [
    fullName,
    birthdate,
    gender,
    address,
    contactNumber,
    email,
    password,
    passwordConfirmation,
    isGoogle,
  ]);

  const validateStep2 = useCallback(() => {
    if (!relationship) {
      setErrors({ relationship: 'Select your relationship to the PDL' });
      return false;
    }
    setErrors({});
    return true;
  }, [relationship]);

  const validateStep3 = useCallback(() => {
    const next = {};
    for (const doc of requiredDocs) {
      if (doc.isText) {
        const text = String(documents[doc.key]?.text ?? '').trim();
        if (!text) next[doc.key] = `${doc.label} is required`;
        continue;
      }
      const entry = documents[doc.key];
      if (!entry?.uri) next[doc.key] = `Upload ${doc.label}`;
      if (doc.requiresIdType && !entry?.idType) {
        next[`${doc.key}_type`] = 'Select ID type';
      }
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  }, [requiredDocs, documents]);

  const validateStep4 = useCallback(() => {
    if (!certified) {
      setErrors({ certified: 'You must certify the information is true and correct' });
      return false;
    }
    setErrors({});
    return true;
  }, [certified]);

  const onContinue = useCallback(() => {
    if (step === 1 && validateStep1()) setStep(2);
    else if (step === 2 && validateStep2()) setStep(3);
    else if (step === 3 && validateStep3()) setStep(4);
  }, [step, validateStep1, validateStep2, validateStep3]);

  const pickDocument = useCallback(async (docKey) => {
    const picked = await pickPhotoFromGallery();
    if (picked?.uri) {
      setDocuments((prev) => ({
        ...prev,
        [docKey]: {
          ...prev[docKey],
          uri: picked.uri,
          fileName: picked.fileName ?? 'document.jpg',
        },
      }));
      setErrors((e) => {
        const next = { ...e };
        delete next[docKey];
        return next;
      });
    }
  }, []);

  const setGuardianText = useCallback((text) => {
    setDocuments((prev) => ({
      ...prev,
      guardian_information: { ...prev.guardian_information, text },
    }));
    setErrors((e) => {
      const next = { ...e };
      delete next.guardian_information;
      return next;
    });
  }, []);

  /** Google registration failure → visitor-facing outcome. */
  const handleGoogleRegistrationError = useCallback(
    (e) => {
      const title = 'Sign up with Google';
      if (e?.code && GOOGLE_RESTART_MESSAGES[e.code]) {
        // useAuth already dropped the Google token for these.
        Alert.alert(title, GOOGLE_RESTART_MESSAGES[e.code]);
        exitGoogleRegistration();
        return;
      }
      if (e?.status === 429) {
        Alert.alert(title, 'Too many attempts. Please wait a minute and try again.');
        return;
      }
      if (e?.code === 'google_unavailable') {
        Alert.alert(
          title,
          'Google Sign-In is temporarily unavailable. Please try again in a moment.',
        );
        return;
      }
      const message =
        typeof e?.message === 'string' && e.message.trim()
          ? e.message
          : 'Something went wrong. Please try again.';
      if (e?.status === 422 && e.errors && typeof e.errors === 'object') {
        // Show field errors inline on the details step; the form is kept.
        const fieldErrors = {};
        Object.entries(e.errors).forEach(([field, messages]) => {
          const key = GOOGLE_FIELD_ERRORS[field];
          const text = Array.isArray(messages) ? messages[0] : messages;
          if (key && typeof text === 'string') fieldErrors[key] = text;
        });
        if (Object.keys(fieldErrors).length) {
          setErrors(fieldErrors);
          setStep(1);
        }
      }
      Alert.alert('Registration failed', message);
    },
    [exitGoogleRegistration],
  );

  const onSubmit = useCallback(async () => {
    if (submitting || !validateStep4()) return;

    setSubmitting(true);
    try {
      const documentsSummary = requiredDocs.map((doc) => {
        const entry = documents[doc.key];
        if (doc.isText) {
          return {
            label: doc.label,
            detail: entry?.text?.trim() || 'Submitted',
          };
        }
        const detail = entry?.idType
          ? `${entry.idType}${entry.fileName ? ` · ${entry.fileName}` : ''}`
          : entry?.fileName || 'Uploaded';
        return { label: doc.label, detail };
      });

      const fields = {
        fullName: fullName.trim(),
        birthdate: birthdate.trim(),
        gender,
        address: address.trim(),
        contactNumber: contactNumber.trim(),
        password,
        relationship,
        relationshipLabel: getRelationshipLabel(relationship),
        documents,
        documentsSummary,
        certified,
        acceptedTerms: acceptTerms,
        acceptedPrivacy: acceptPrivacy,
        consentVersion: legal?.version,
      };

      if (isGoogle) {
        // No email: the backend takes it from the verified Google token.
        await registerWithGoogle({ ...fields, password_confirmation: passwordConfirmation });
      } else {
        await register({ ...fields, email: email.trim() });
      }
    } catch (e) {
      if (isGoogle) {
        handleGoogleRegistrationError(e);
        return;
      }
      const message =
        typeof e?.message === 'string' && e.message.trim()
          ? e.message
          : 'Something went wrong. Please try again.';
      Alert.alert('Registration failed', message);
    } finally {
      setSubmitting(false);
    }
  }, [
    submitting,
    validateStep4,
    register,
    registerWithGoogle,
    isGoogle,
    handleGoogleRegistrationError,
    fullName,
    birthdate,
    gender,
    address,
    contactNumber,
    email,
    password,
    passwordConfirmation,
    relationship,
    documents,
    certified,
    requiredDocs,
    acceptTerms,
    acceptPrivacy,
    legal,
  ]);

  const renderStep1 = () => (
    <Card style={styles.card}>
      <WizardField
        label="Full Name"
        value={fullName}
        onChangeText={setFullName}
        placeholder="Enter full name"
        error={errors.fullName}
        autoCapitalize="words"
      />
      <BirthdateField
        label="Birthdate"
        value={birthdate}
        onChange={(iso) => {
          setBirthdate(iso);
          setErrors((e) => {
            const next = { ...e };
            delete next.birthdate;
            return next;
          });
        }}
        error={errors.birthdate}
        placeholder="Select birthdate"
      />
      <WizardField
        label="Gender"
        value={gender}
        onChangeText={() => {}}
        placeholder="Select gender"
        error={errors.gender}
        editable={false}
        onPress={() => setGenderModalVisible(true)}
      />
      <WizardField
        label="Address"
        value={address}
        onChangeText={setAddress}
        placeholder="Enter complete address"
        error={errors.address}
        multiline
      />
      <WizardField
        label="Contact Number"
        value={contactNumber}
        onChangeText={setContactNumber}
        placeholder="09XX XXX XXXX"
        keyboardType="phone-pad"
        error={errors.contactNumber}
      />
      {isGoogle ? (
        <View style={fieldStyles.wrap}>
          <Text style={fieldStyles.label}>Email Address</Text>
          <View
            style={[fieldStyles.readOnlyRow, errors.email && fieldStyles.inputError]}
            accessible
            accessibilityLabel={`Email address ${email}, from your Google account, cannot be changed`}
          >
            <GoogleGLogo size={18} />
            <Text style={fieldStyles.readOnlyText} numberOfLines={1}>
              {email}
            </Text>
            <Ionicons name="lock-closed-outline" size={16} color={colors.textSecondary} />
          </View>
          <Text style={fieldStyles.hint}>
            Your Google account email is used for this account.
          </Text>
          {errors.email ? <Text style={fieldStyles.error}>{errors.email}</Text> : null}
        </View>
      ) : (
        <WizardField
          label="Email Address"
          value={email}
          onChangeText={setEmail}
          placeholder="Enter your email"
          keyboardType="email-address"
          error={errors.email}
          autoCapitalize="none"
        />
      )}
      <View style={fieldStyles.wrap}>
        <Text style={fieldStyles.label}>{isGoogle ? 'Create Password' : 'Password'}</Text>
        <View style={[fieldStyles.passwordRow, errors.password && fieldStyles.inputError]}>
          <TextInput
            value={password}
            onChangeText={setPassword}
            placeholder="••••••••"
            placeholderTextColor={colors.textSecondary}
            secureTextEntry={!showPassword}
            style={fieldStyles.passwordInput}
          />
          <Pressable
            onPress={() => setShowPassword((v) => !v)}
            accessibilityRole="button"
            accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
            hitSlop={10}
            style={fieldStyles.eye}
          >
            <Ionicons
              name={showPassword ? 'eye-off-outline' : 'eye-outline'}
              size={20}
              color={colors.textSecondary}
            />
          </Pressable>
        </View>
        {errors.password ? <Text style={fieldStyles.error}>{errors.password}</Text> : null}
        {isGoogle ? (
          <Text style={fieldStyles.hint}>
            You can also sign in with this email and password.
          </Text>
        ) : null}
      </View>
      {isGoogle ? (
        <View style={fieldStyles.wrap}>
          <Text style={fieldStyles.label}>Confirm Password</Text>
          <View
            style={[
              fieldStyles.passwordRow,
              errors.passwordConfirmation && fieldStyles.inputError,
            ]}
          >
            <TextInput
              value={passwordConfirmation}
              onChangeText={setPasswordConfirmation}
              placeholder="••••••••"
              placeholderTextColor={colors.textSecondary}
              secureTextEntry={!showPassword}
              accessibilityLabel="Confirm password"
              style={fieldStyles.passwordInput}
            />
          </View>
          {errors.passwordConfirmation ? (
            <Text style={fieldStyles.error}>{errors.passwordConfirmation}</Text>
          ) : null}
        </View>
      ) : null}
    </Card>
  );

  const renderStep2 = () => (
    <Card style={styles.card}>
      {errors.relationship ? (
        <Text style={styles.stepError}>{errors.relationship}</Text>
      ) : null}
      {RELATIONSHIPS.map((item) => {
        const selected = relationship === item.id;
        return (
          <Pressable
            key={item.id}
            onPress={() => {
              setRelationship(item.id);
              setDocuments({});
              setErrors((e) => {
                const next = { ...e };
                delete next.relationship;
                return next;
              });
            }}
            style={[styles.optionRow, selected && styles.optionRowSelected]}
            accessibilityRole="radio"
            accessibilityState={{ selected }}
          >
            <View style={[styles.radio, selected && styles.radioSelected]}>
              {selected ? <View style={styles.radioDot} /> : null}
            </View>
            <Text style={[styles.optionLabel, selected && styles.optionLabelSelected]}>
              {item.label}
            </Text>
          </Pressable>
        );
      })}
    </Card>
  );

  const renderDocumentCard = (doc) => {
    const entry = documents[doc.key];
    const uploaded = doc.isText
      ? Boolean(String(entry?.text ?? '').trim())
      : Boolean(entry?.uri);

    return (
      <View key={doc.key} style={styles.docCard}>
        <View style={styles.docHeader}>
          <Text style={styles.docTitle}>{doc.label}</Text>
          {!doc.isText ? (
            <Pressable
              onPress={() => pickDocument(doc.key)}
              style={styles.uploadBtn}
              accessibilityRole="button"
              accessibilityLabel={`Upload ${doc.label}`}
            >
              <Text style={styles.uploadBtnText}>Upload</Text>
            </Pressable>
          ) : null}
        </View>

        {doc.requiresIdType ? (
          <Pressable
            onPress={() => setIdTypeModal({ visible: true, docKey: doc.key })}
            style={[
              styles.idTypePicker,
              errors[`${doc.key}_type`] && fieldStyles.inputError,
            ]}
          >
            <Text
              style={entry?.idType ? styles.idTypeValue : styles.idTypePlaceholder}
            >
              {entry?.idType ?? 'Select ID type'}
            </Text>
            <Ionicons name="chevron-down" size={18} color={colors.textSecondary} />
          </Pressable>
        ) : null}
        {errors[`${doc.key}_type`] ? (
          <Text style={fieldStyles.error}>{errors[`${doc.key}_type`]}</Text>
        ) : null}

        {doc.isText ? (
          <TextInput
            value={entry?.text ?? ''}
            onChangeText={setGuardianText}
            placeholder="Guardian full name, relationship, contact number"
            placeholderTextColor={colors.textSecondary}
            multiline
            style={[
              fieldStyles.input,
              fieldStyles.inputMultiline,
              errors[doc.key] && fieldStyles.inputError,
            ]}
          />
        ) : uploaded ? (
          <View style={styles.uploadedRow}>
            <Ionicons name="document-attach-outline" size={18} color={colors.success} />
            <Text style={styles.uploadedName} numberOfLines={1}>
              {entry?.fileName ?? 'Document attached'}
            </Text>
          </View>
        ) : null}

        {errors[doc.key] ? <Text style={fieldStyles.error}>{errors[doc.key]}</Text> : null}
      </View>
    );
  };

  const renderStep3 = () => (
    <>
      <Text style={styles.acceptedIds}>
        Accepted IDs: {ACCEPTED_ID_TYPES.join(' · ')}
      </Text>
      <Card style={styles.card}>
        {requiredDocs.map(renderDocumentCard)}
      </Card>
    </>
  );

  const renderReviewRow = (label, value) => (
    <View style={styles.reviewRow} key={label}>
      <Text style={styles.reviewLabel}>{label}</Text>
      <Text style={styles.reviewValue}>{value || '—'}</Text>
    </View>
  );

  const renderStep4 = () => (
    <>
      <Card style={styles.card}>
        <Text style={styles.reviewSectionTitle}>Personal Information</Text>
        {renderReviewRow('Full Name', fullName.trim())}
        {renderReviewRow('Birthdate', formatBirthdateDisplay(birthdate) || '—')}
        {renderReviewRow('Gender', gender)}
        {renderReviewRow('Address', address.trim())}
        {renderReviewRow('Contact Number', contactNumber.trim())}
        {renderReviewRow(isGoogle ? 'Email (Google account)' : 'Email', email.trim())}
      </Card>

      <Card style={[styles.card, styles.cardSpaced]}>
        <Text style={styles.reviewSectionTitle}>Relationship</Text>
        {renderReviewRow('Relationship To PDL', getRelationshipLabel(relationship))}
      </Card>

      <Card style={[styles.card, styles.cardSpaced]}>
        <Text style={styles.reviewSectionTitle}>Uploaded Documents</Text>
        {requiredDocs.map((doc) => {
          const entry = documents[doc.key];
          let value = '—';
          if (doc.isText) value = entry?.text?.trim() || '—';
          else if (entry?.uri) {
            value = entry.idType
              ? `${doc.label} (${entry.idType}) — ${entry.fileName ?? 'attached'}`
              : entry.fileName ?? 'Attached';
          }
          return renderReviewRow(doc.label, value);
        })}
      </Card>

      <Pressable
        onPress={() => {
          setCertified((c) => !c);
          setErrors((e) => {
            const next = { ...e };
            delete next.certified;
            return next;
          });
        }}
        style={styles.certifyRow}
        accessibilityRole="checkbox"
        accessibilityState={{ checked: certified }}
      >
        <View style={[styles.checkbox, certified && styles.checkboxChecked]}>
          {certified ? <Ionicons name="checkmark" size={14} color={colors.white} /> : null}
        </View>
        <Text style={styles.certifyText}>
          I certify all information is true and correct.
        </Text>
      </Pressable>
      {errors.certified ? <Text style={styles.stepError}>{errors.certified}</Text> : null}
    </>
  );

  const renderGoogleBadge = () =>
    isGoogle ? (
      <View style={styles.googleBadge} accessibilityLabel="Signing up with Google">
        <GoogleGLogo size={14} />
        <Text style={styles.googleBadgeText}>Signing up with Google</Text>
      </View>
    ) : null;

  // ── Consent screen (shown first, before any details are collected) ──────
  const renderLegalModal = () => {
    const doc = legalDoc && legal ? legal[legalDoc] : null;
    const fallbackTitle = legalDoc === 'privacy' ? 'Privacy Policy' : 'Terms and Conditions';
    return (
      <Modal
        visible={legalDoc !== null}
        animationType="slide"
        onRequestClose={() => setLegalDoc(null)}
      >
        <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
          <View style={legalStyles.header}>
            <Text style={legalStyles.headerTitle}>{doc?.title ?? fallbackTitle}</Text>
            <Pressable
              onPress={() => setLegalDoc(null)}
              accessibilityRole="button"
              accessibilityLabel="Close"
              hitSlop={10}
            >
              <Ionicons name="close" size={24} color={colors.textPrimary} />
            </Pressable>
          </View>
          <ScrollView contentContainerStyle={legalStyles.scroll}>
            {doc ? (
              <>
                <Text style={legalStyles.meta}>
                  Version {legal.version} · Effective {legal.effectiveDate}
                </Text>
                {legal.draft ? (
                  <Text style={legalStyles.draft}>
                    Draft — pending legal review by the facility.
                  </Text>
                ) : null}
                <Text style={legalStyles.body}>{doc.intro}</Text>
                {doc.sections.map((section) => (
                  <View key={section.heading}>
                    <Text style={legalStyles.heading}>{section.heading}</Text>
                    {section.body.map((paragraph, i) => (
                      <Text key={i} style={legalStyles.body}>
                        {paragraph}
                      </Text>
                    ))}
                  </View>
                ))}
              </>
            ) : legalError ? (
              <>
                <Text style={styles.stepError}>{legalError}</Text>
                <Button title="Try again" onPress={loadLegal} accessibilityLabel="Try again" />
              </>
            ) : (
              <Text style={legalStyles.body}>Loading…</Text>
            )}
          </ScrollView>
          <View style={legalStyles.footer}>
            <Button
              title="Close"
              onPress={() => setLegalDoc(null)}
              accessibilityLabel="Close document"
            />
          </View>
        </SafeAreaView>
      </Modal>
    );
  };

  const renderConsentRow = (checked, toggle, linkLabel, docKey, suffix = '') => (
    <Pressable
      onPress={() => {
        toggle((v) => !v);
        setErrors({});
      }}
      style={styles.consentRow}
      accessibilityRole="checkbox"
      accessibilityState={{ checked }}
    >
      <View style={[styles.checkbox, checked && styles.checkboxChecked]}>
        {checked ? <Ionicons name="checkmark" size={14} color={colors.white} /> : null}
      </View>
      <Text style={styles.certifyText}>
        I have read and accept the{' '}
        <Text
          style={styles.linkText}
          onPress={() => setLegalDoc(docKey)}
          accessibilityRole="link"
        >
          {linkLabel}
        </Text>
        {suffix}.
      </Text>
    </Pressable>
  );

  const renderConsent = () => (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.scroll}>
        <Pressable
          onPress={goBack}
          accessibilityRole="button"
          accessibilityLabel="Go back"
          hitSlop={10}
          style={styles.backButton}
        >
          <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
        </Pressable>

        <Text style={styles.screenTitle}>Before you begin</Text>
        {renderGoogleBadge()}
        <Text style={styles.stepSubtitle}>
          Please review and accept our Terms and Conditions and Privacy Policy before you
          provide any of your details. Tap a document name to read it.
        </Text>

        <Card style={styles.card}>
          {renderConsentRow(acceptTerms, setAcceptTerms, 'Terms and Conditions', 'terms')}
          {renderConsentRow(
            acceptPrivacy,
            setAcceptPrivacy,
            'Privacy Policy',
            'privacy',
            ' (Data Privacy Act of 2012)',
          )}
        </Card>
        {errors.consent ? <Text style={styles.stepError}>{errors.consent}</Text> : null}
        {legal?.version ? (
          <Text style={styles.acceptedIds}>Version {legal.version}</Text>
        ) : null}

        <View style={styles.footerBtn}>
          <Button
            title="Continue"
            onPress={onConsentContinue}
            disabled={!acceptTerms || !acceptPrivacy}
            accessibilityLabel="Continue to registration"
          />
        </View>
      </ScrollView>
      {renderLegalModal()}
    </SafeAreaView>
  );

  if (!consentDone) return renderConsent();

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView
          keyboardShouldPersistTaps="handled"
          contentContainerStyle={styles.scroll}
        >
          <Pressable
            onPress={goBack}
            accessibilityRole="button"
            accessibilityLabel="Go back"
            hitSlop={10}
            style={styles.backButton}
          >
            <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
          </Pressable>

          <Text style={styles.screenTitle}>{STEP_TITLES[step]}</Text>
          {renderGoogleBadge()}
          {step === 1 ? (
            <Text style={styles.stepSubtitle}>
              {isGoogle
                ? 'Complete your visitor account. Enter your full legal name as it appears on your ID, and create a CustodiCore password.'
                : 'Create your visitor account with your email address and password.'}
            </Text>
          ) : null}
          <ProgressStepper step={step} />

          {step === 1 && renderStep1()}
          {step === 2 && renderStep2()}
          {step === 3 && renderStep3()}
          {step === 4 && renderStep4()}

          <View style={styles.footerBtn}>
            {step < 4 ? (
              <Button title="Continue" onPress={onContinue} accessibilityLabel="Continue" />
            ) : (
              <Button
                title="Submit Registration"
                onPress={onSubmit}
                loading={submitting}
                disabled={submitting}
                accessibilityLabel="Submit registration"
              />
            )}
          </View>
        </ScrollView>
      </KeyboardAvoidingView>

      <Modal visible={genderModalVisible} transparent animationType="fade">
        <Pressable style={styles.modalOverlay} onPress={() => setGenderModalVisible(false)}>
          <View style={styles.modalSheet}>
            <Text style={styles.modalTitle}>Select gender</Text>
            {GENDER_OPTIONS.map((g) => (
              <Pressable
                key={g}
                style={styles.modalOption}
                onPress={() => {
                  setGender(g);
                  setGenderModalVisible(false);
                  setErrors((e) => {
                    const next = { ...e };
                    delete next.gender;
                    return next;
                  });
                }}
              >
                <Text style={styles.modalOptionText}>{g}</Text>
              </Pressable>
            ))}
          </View>
        </Pressable>
      </Modal>

      <Modal visible={idTypeModal.visible} transparent animationType="fade">
        <Pressable
          style={styles.modalOverlay}
          onPress={() => setIdTypeModal({ visible: false, docKey: null })}
        >
          <View style={styles.modalSheet}>
            <Text style={styles.modalTitle}>Select ID type</Text>
            {ACCEPTED_ID_TYPES.map((type) => (
              <Pressable
                key={type}
                style={styles.modalOption}
                onPress={() => {
                  const key = idTypeModal.docKey;
                  if (key) {
                    setDocuments((prev) => ({
                      ...prev,
                      [key]: { ...prev[key], idType: type },
                    }));
                    setErrors((e) => {
                      const next = { ...e };
                      delete next[`${key}_type`];
                      return next;
                    });
                  }
                  setIdTypeModal({ visible: false, docKey: null });
                }}
              >
                <Text style={styles.modalOptionText}>{type}</Text>
              </Pressable>
            ))}
          </View>
        </Pressable>
      </Modal>
    </SafeAreaView>
  );
}

const stepperStyles = StyleSheet.create({
  wrap: { marginBottom: spacing.md },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
  },
  circle: {
    width: 32,
    height: 32,
    borderRadius: 16,
    borderWidth: 2,
    borderColor: colors.border,
    backgroundColor: colors.white,
    alignItems: 'center',
    justifyContent: 'center',
  },
  circleActive: {
    borderColor: colors.primaryTeal,
    backgroundColor: colors.primaryTeal,
  },
  circleDone: {
    borderColor: colors.primaryTeal,
    backgroundColor: colors.primaryTeal,
  },
  circleText: {
    ...typography.statusLabel,
    color: colors.textSecondary,
  },
  circleTextActive: { color: colors.white },
  line: {
    flex: 1,
    height: 2,
    backgroundColor: colors.border,
    marginHorizontal: spacing.xs,
    maxWidth: 48,
  },
  lineDone: { backgroundColor: colors.primaryTeal },
  progressLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
    marginTop: spacing.sm,
  },
});

const legalStyles = StyleSheet.create({
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: layout.screenPadding,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    backgroundColor: colors.white,
  },
  headerTitle: { ...typography.cardTitle, color: colors.textPrimary, flex: 1 },
  scroll: { padding: layout.screenPadding, paddingBottom: spacing.xl },
  meta: { ...typography.metadata, color: colors.textSecondary, marginBottom: spacing.sm },
  draft: {
    ...typography.metadata,
    color: colors.textPrimary,
    backgroundColor: '#FFFBEB',
    borderWidth: 1,
    borderColor: colors.warning,
    borderRadius: layout.buttonRadius,
    padding: spacing.sm,
    marginBottom: spacing.sm,
  },
  heading: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginTop: spacing.md,
    marginBottom: spacing.xs,
  },
  body: {
    ...typography.body,
    color: colors.textPrimary,
    lineHeight: 22,
    marginBottom: spacing.sm,
  },
  footer: {
    padding: layout.screenPadding,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    backgroundColor: colors.white,
  },
});

const fieldStyles = StyleSheet.create({
  wrap: { marginBottom: spacing.sm },
  label: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  input: {
    height: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingHorizontal: spacing.sm,
    backgroundColor: colors.white,
    color: colors.textPrimary,
    ...typography.body,
  },
  inputMultiline: {
    height: 88,
    paddingTop: spacing.sm,
    textAlignVertical: 'top',
  },
  passwordRow: {
    height: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingLeft: spacing.sm,
    paddingRight: spacing.sm,
    backgroundColor: colors.white,
    flexDirection: 'row',
    alignItems: 'center',
  },
  passwordInput: {
    flex: 1,
    color: colors.textPrimary,
    ...typography.body,
  },
  eye: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
  },
  readOnlyRow: {
    height: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingHorizontal: spacing.sm,
    backgroundColor: colors.background,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  readOnlyText: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
  },
  hint: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.xs,
  },
  inputError: { borderColor: colors.danger },
  error: {
    ...typography.metadata,
    color: colors.danger,
    marginTop: spacing.sm,
  },
});

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  flex: { flex: 1 },
  scroll: {
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.xl,
  },
  backButton: {
    width: layout.iconButtonSize,
    height: layout.iconButtonSize,
    borderRadius: layout.iconButtonSize / 2,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    marginBottom: spacing.sm,
  },
  screenTitle: {
    ...typography.pageTitle,
    fontSize: 24,
    lineHeight: 30,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  googleBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: spacing.xs,
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
    marginBottom: spacing.sm,
  },
  googleBadgeText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.primaryNavy,
  },
  stepSubtitle: {
    ...typography.body,
    color: colors.textSecondary,
    lineHeight: 22,
    marginBottom: layout.pageTitleGap,
  },
  card: {
    borderRadius: layout.cardRadius,
  },
  cardSpaced: { marginTop: layout.cardGap },
  stepError: {
    ...typography.metadata,
    color: colors.danger,
    marginBottom: spacing.sm,
  },
  optionRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.sm,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.border,
    marginBottom: spacing.sm,
    backgroundColor: colors.white,
  },
  optionRowSelected: {
    borderColor: colors.primaryTeal,
    backgroundColor: 'rgba(13, 165, 138, 0.06)',
  },
  radio: {
    width: 22,
    height: 22,
    borderRadius: 11,
    borderWidth: 2,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing.sm,
  },
  radioSelected: { borderColor: colors.primaryTeal },
  radioDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
    backgroundColor: colors.primaryTeal,
  },
  optionLabel: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
  },
  optionLabelSelected: { fontWeight: '600', color: colors.primaryNavy },
  acceptedIds: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  docCard: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    padding: spacing.sm,
    marginBottom: spacing.sm,
    backgroundColor: colors.white,
  },
  docHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.sm,
  },
  docTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    flex: 1,
  },
  uploadBtn: {
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.sm,
    borderRadius: layout.borderRadiusSm,
    backgroundColor: colors.primaryTeal,
  },
  uploadBtnText: {
    ...typography.statusLabel,
    color: colors.white,
    fontWeight: '600',
  },
  idTypePicker: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    height: layout.iconButtonSize,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingHorizontal: spacing.sm,
    marginBottom: spacing.sm,
    backgroundColor: colors.background,
  },
  idTypePlaceholder: { ...typography.body, color: colors.textSecondary },
  idTypeValue: { ...typography.body, color: colors.textPrimary },
  uploadedRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.xs,
  },
  uploadedName: {
    ...typography.metadata,
    color: colors.success,
    fontWeight: '600',
    flex: 1,
  },
  reviewSectionTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  reviewRow: {
    marginBottom: spacing.sm,
  },
  reviewLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.xs,
  },
  reviewValue: {
    ...typography.body,
    color: colors.textPrimary,
  },
  certifyRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    marginTop: spacing.md,
    gap: spacing.sm,
  },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: 6,
    borderWidth: 2,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: spacing.xs,
  },
  checkboxChecked: {
    backgroundColor: colors.primaryTeal,
    borderColor: colors.primaryTeal,
  },
  certifyText: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
  },
  footerBtn: { marginTop: spacing.md },
  consentRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    paddingVertical: spacing.sm,
  },
  linkText: {
    color: colors.primaryTeal,
    fontWeight: '600',
    textDecorationLine: 'underline',
  },
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(15, 61, 122, 0.45)',
    justifyContent: 'flex-end',
  },
  modalSheet: {
    backgroundColor: colors.white,
    borderTopLeftRadius: 16,
    borderTopRightRadius: 16,
    padding: spacing.md,
    paddingBottom: spacing.xl,
  },
  modalTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  modalOption: {
    paddingVertical: spacing.sm,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  modalOptionText: {
    ...typography.body,
    color: colors.textPrimary,
  },
});
