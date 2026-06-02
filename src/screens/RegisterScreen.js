import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useMemo, useState } from 'react';
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
import { launchImageLibrary } from 'react-native-image-picker';
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
  return (
    <View style={stepperStyles.wrap}>
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
      <Text style={stepperStyles.progressLabel}>
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
 * 4-step visitor registration wizard (v2.1).
 */
export default function RegisterScreen({ navigation }) {
  const { register } = useAuth();
  const [step, setStep] = useState(1);
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState({});

  const [fullName, setFullName] = useState('');
  const [birthdate, setBirthdate] = useState('');
  const [gender, setGender] = useState('');
  const [address, setAddress] = useState('');
  const [contactNumber, setContactNumber] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);

  const [relationship, setRelationship] = useState(null);
  const [documents, setDocuments] = useState({});
  const [certified, setCertified] = useState(false);

  const [genderModalVisible, setGenderModalVisible] = useState(false);
  const [idTypeModal, setIdTypeModal] = useState({ visible: false, docKey: null });

  const requiredDocs = useMemo(
    () => (relationship ? getRequiredDocuments(relationship) : []),
    [relationship],
  );

  const goBack = useCallback(() => {
    if (step > 1) {
      setStep((s) => s - 1);
      setErrors({});
      return;
    }
    navigation.navigate('Login');
  }, [step, navigation]);

  const validateStep1 = useCallback(() => {
    const next = {};
    if (!validateRequired(fullName)) next.fullName = 'Full name is required';
    if (!validateRequired(birthdate)) next.birthdate = 'Birthdate is required';
    if (!validateRequired(gender)) next.gender = 'Gender is required';
    if (!validateRequired(address)) next.address = 'Address is required';
    if (!validateRequired(contactNumber))
      next.contactNumber = 'Contact number is required';
    if (!validateRequired(email)) next.email = 'Email is required';
    else if (!validateEmail(email.trim())) next.email = 'Enter a valid email';
    if (!validateRequired(password)) next.password = 'Password is required';
    else if (!validatePassword(password))
      next.password = 'Password must be at least 6 characters';
    setErrors(next);
    return Object.keys(next).length === 0;
  }, [fullName, birthdate, gender, address, contactNumber, email, password]);

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
    const result = await launchImageLibrary({
      mediaType: 'photo',
      selectionLimit: 1,
    });
    const asset = result.assets?.[0];
    if (asset?.uri) {
      setDocuments((prev) => ({
        ...prev,
        [docKey]: {
          ...prev[docKey],
          uri: asset.uri,
          fileName: asset.fileName ?? 'document.jpg',
        },
      }));
      setErrors((e) => {
        const next = { ...e };
        delete next[docKey];
        return next;
      });
    } else if (result.errorMessage) {
      Alert.alert('Upload failed', result.errorMessage);
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

      await register({
        fullName: fullName.trim(),
        birthdate: birthdate.trim(),
        gender,
        address: address.trim(),
        contactNumber: contactNumber.trim(),
        email: email.trim(),
        password,
        relationship,
        relationshipLabel: getRelationshipLabel(relationship),
        documents,
        documentsSummary,
        certified,
      });
    } catch (e) {
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
    fullName,
    birthdate,
    gender,
    address,
    contactNumber,
    email,
    password,
    relationship,
    documents,
    certified,
    requiredDocs,
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
      <WizardField
        label="Email Address"
        value={email}
        onChangeText={setEmail}
        placeholder="you@example.com"
        keyboardType="email-address"
        error={errors.email}
        autoCapitalize="none"
      />
      <View style={fieldStyles.wrap}>
        <Text style={fieldStyles.label}>Password</Text>
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
      </View>
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
        {renderReviewRow('Email', email.trim())}
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

const fieldStyles = StyleSheet.create({
  wrap: { marginBottom: spacing.sm },
  label: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  input: {
    height: 48,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 12,
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
    height: 48,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 12,
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
    width: 44,
    height: 44,
    borderRadius: 22,
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
    borderRadius: 12,
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
    borderRadius: 12,
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
    borderRadius: 8,
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
    height: 44,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 12,
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
    marginTop: 2,
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
