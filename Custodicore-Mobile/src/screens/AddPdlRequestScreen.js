import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Alert,
  BackHandler,
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Button,
  Card,
  StackScreenHeader,
  colors,
  commonStyles,
  formStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { useAuth } from '../hooks/useAuth';
import { submitPdlRequest } from '../repositories/verificationRepository';
import { pickPhotoFromCamera, pickPhotoFromGallery } from '../services/imagePickerService';
import { toDocumentImageUpload, validateDocumentImage } from '../utils/documentImageRules';
import {
  INTENDED_PDL_NAME_LIMITS,
  INTENDED_PDL_NAME_PLACEHOLDER,
  filterIntendedPdlName,
} from '../utils/intendedPdlName';
import {
  PDL_REQUEST_STEPS,
  firstInvalidPdlRequestStep,
  getPdlRequestDocuments,
  getPdlRequestStepErrors,
  mapPdlRequestServerErrors,
  pdlRequestRefusalTitle,
} from '../utils/pdlLinkRequest';
import {
  ADULT_AGE,
  REGISTRATION_RELATIONSHIPS,
  asksChildrenTogether,
  getRelationshipLabel,
  isRegistrationRelationship,
} from '../utils/registrationRequirements';
import { ageFromBirthdate, normalizeName } from '../utils/registrationValidation';

const TOTAL_STEPS = PDL_REQUEST_STEPS.length;

const CHILDREN_OPTIONS = [
  { value: true, label: 'Yes, we have children together' },
  { value: false, label: 'No children together' },
];

/**
 * "Request Again" details (route param `prefill`, from pdlRequestPrefill()),
 * checked again here since route params are untyped. Null for a new request.
 * @param {unknown} raw
 * @returns {{ pdlName: string; relationship: string | null; hasChildrenTogether: boolean | null; rejectionReason: string | null } | null}
 */
function readPrefill(raw) {
  if (!raw || typeof raw !== 'object') return null;
  const p = /** @type {Record<string, unknown>} */ (raw);
  const relationship = isRegistrationRelationship(p.relationship) ? String(p.relationship) : null;
  return {
    pdlName: typeof p.pdlName === 'string' ? filterIntendedPdlName(p.pdlName) : '',
    relationship,
    hasChildrenTogether:
      relationship && asksChildrenTogether(relationship) && typeof p.hasChildrenTogether === 'boolean'
        ? p.hasChildrenTogether
        : null,
    rejectionReason:
      typeof p.rejectionReason === 'string' && p.rejectionReason.trim() ? p.rejectionReason.trim() : null,
  };
}

/**
 * Phase 5B: an approved visitor asks facility staff to add another PDL
 * (the (+) beside the PDL chips on Home → Visitation Calendar). Same order
 * and documents as registration — PDL name, relationship, the relationship's
 * documents, review — without the account details, photo or Government ID.
 * Sends POST /api/pdl-requests; staff then identify the PDL and link it.
 *
 * Phase 4 "Request Again" (My PDLs or a rejected-request notification) opens
 * the same form with the rejected request's name and relationship filled in
 * and its staff reason shown; the visitor edits them, attaches the documents
 * again and sends a new request, which staff review like any other.
 */
export default function AddPdlRequestScreen({ navigation, route }) {
  const { user } = useAuth();
  const age = ageFromBirthdate(user?.dateOfBirth ?? '');
  const isMinor = age !== null && age < ADULT_AGE;
  // Read once: later param changes must not overwrite what the visitor typed.
  const [prefill] = useState(() => readPrefill(route?.params?.prefill));

  const [step, setStep] = useState(1);
  const [pdlName, setPdlName] = useState(prefill?.pdlName ?? '');
  const [relationship, setRelationship] = useState(
    /** @type {string | null} */ (prefill?.relationship ?? null),
  );
  const [hasChildrenTogether, setHasChildrenTogether] = useState(
    /** @type {boolean | null} */ (prefill?.hasChildrenTogether ?? null),
  );
  const [files, setFiles] = useState(/** @type {Record<string, any>} */ ({}));
  const [chooserKey, setChooserKey] = useState(/** @type {string | null} */ (null));
  const [errors, setErrors] = useState(/** @type {Record<string, string>} */ ({}));
  const [submitting, setSubmitting] = useState(false);
  const scrollRef = useRef(/** @type {ScrollView | null} */ (null));

  const requiredDocs = useMemo(
    () => getPdlRequestDocuments(relationship, { isMinor, hasChildrenTogether }),
    [relationship, isMinor, hasChildrenTogether],
  );
  const formState = { pdlName, relationship, hasChildrenTogether, isMinor, files };

  const clearError = useCallback((key) => {
    setErrors((e) => {
      if (!(key in e)) return e;
      const next = { ...e };
      delete next[key];
      return next;
    });
  }, []);

  const goToStep = useCallback((next) => {
    setStep(next);
    setChooserKey(null);
    scrollRef.current?.scrollTo?.({ y: 0, animated: false });
  }, []);

  const goBack = useCallback(() => {
    if (submitting) return;
    if (step > 1) {
      setErrors({});
      goToStep(step - 1);
      return;
    }
    navigation.goBack();
  }, [step, submitting, goToStep, navigation]);

  // Android hardware back steps back through the form like the header button.
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      goBack();
      return true;
    });
    return () => sub.remove();
  }, [goBack]);

  const onContinue = useCallback(() => {
    const stepErrors = getPdlRequestStepErrors(step, formState);
    setErrors(stepErrors);
    if (Object.keys(stepErrors).length === 0) goToStep(step + 1);
    // formState is rebuilt every render from the values below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step, pdlName, relationship, hasChildrenTogether, isMinor, files, goToStep]);

  /** Camera or gallery pick; cancelling or an invalid image keeps the current file. */
  const pickDocument = useCallback(async (docKey, useCamera) => {
    setChooserKey(null);
    let picked;
    try {
      picked = useCamera
        ? await pickPhotoFromCamera({ forUpload: true })
        : await pickPhotoFromGallery({ forUpload: true });
    } catch {
      setErrors((e) => ({
        ...e,
        [docKey]: useCamera
          ? 'Could not open the camera. Please try again.'
          : 'Could not open your photos. Please try again.',
      }));
      return;
    }
    if (!picked?.uri) return;

    const invalid = validateDocumentImage(picked);
    if (invalid) {
      setErrors((e) => ({ ...e, [docKey]: invalid }));
      return;
    }
    const fileName = toDocumentImageUpload(picked)?.name ?? picked.fileName ?? 'document.jpg';
    setFiles((prev) => ({
      ...prev,
      [docKey]: {
        uri: picked.uri,
        fileName,
        mimeType: picked.mimeType ?? null,
        fileSize: picked.fileSize ?? null,
      },
    }));
    clearError(docKey);
    clearError('documents');
  }, [clearError]);

  const onSubmit = useCallback(async () => {
    if (submitting) return;
    const invalidStep = firstInvalidPdlRequestStep(formState);
    if (invalidStep !== null) {
      setErrors(getPdlRequestStepErrors(invalidStep, formState));
      goToStep(invalidStep);
      return;
    }

    setSubmitting(true);
    setErrors({});
    try {
      await submitPdlRequest({
        pdlName: normalizeName(pdlName),
        relationship: /** @type {string} */ (relationship),
        hasChildrenTogether,
        files: requiredDocs.map((doc) => [doc.requirementKey, files[doc.key]]),
      });
      Alert.alert(
        'Request sent',
        'Facility staff will find the PDL in their records and review your documents. You can follow the request under Profile → My PDLs.',
        [{ text: 'OK', onPress: () => navigation.goBack() }],
      );
    } catch (e) {
      const err = /** @type {Error & { status?: number, code?: string, errors?: Record<string, string[]>, timedOut?: boolean }} */ (e);
      const mapped = err?.status === 422 ? mapPdlRequestServerErrors(err.errors) : null;
      if (mapped) {
        setErrors(mapped.errors);
        goToStep(mapped.step);
      } else if (err?.status === 409) {
        // Request limit, same PDL already requested, or PDL already on the
        // account: the backend's message says which.
        Alert.alert(pdlRequestRefusalTitle(err.code), err.message, [
          { text: 'OK', onPress: () => navigation.goBack() },
        ]);
      } else if (err?.status === 403) {
        Alert.alert('Not available', err.message, [{ text: 'OK', onPress: () => navigation.goBack() }]);
      } else if (err?.timedOut) {
        Alert.alert(
          'Taking too long',
          'The request took too long to send. Check Profile → My PDLs before sending it again: it may already be waiting for staff.',
        );
      } else {
        Alert.alert('Could not send request', err?.message || 'Please try again.');
      }
    } finally {
      setSubmitting(false);
    }
    // formState is rebuilt every render from the values below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [submitting, pdlName, relationship, hasChildrenTogether, isMinor, files, requiredDocs, goToStep, navigation]);

  // ----------------------------------------------------------------- steps

  // Previous request's staff reason, next to the details it is about.
  const renderReasonNotice = () =>
    prefill?.rejectionReason ? (
      <View style={styles.reasonNotice} accessibilityRole="text">
        <Ionicons name="alert-circle-outline" size={16} color={colors.danger} />
        <Text style={styles.reasonNoticeText}>
          <Text style={styles.reasonNoticeLabel}>Previous request not accepted: </Text>
          {prefill.rejectionReason}
        </Text>
      </View>
    ) : null;

  const renderNameStep = () => (
    <Card style={styles.card}>
      <Text style={styles.sectionTitle}>PDL You Want To Visit</Text>
      {renderReasonNotice()}
      <View style={formStyles.field}>
        <Text style={formStyles.label}>PDL Name</Text>
        <TextInput
          value={pdlName}
          onChangeText={(v) => {
            setPdlName(filterIntendedPdlName(v));
            clearError('pdlName');
          }}
          placeholder={INTENDED_PDL_NAME_PLACEHOLDER}
          placeholderTextColor={colors.textSecondary}
          style={[formStyles.input, errors.pdlName && formStyles.inputError]}
          autoCapitalize="words"
          autoCorrect={false}
          maxLength={INTENDED_PDL_NAME_LIMITS.max}
          accessibilityLabel="PDL Name"
        />
        {errors.pdlName ? <Text style={formStyles.error}>{errors.pdlName}</Text> : null}
      </View>
      <Text style={styles.hint}>
        Facility staff use this name to find the PDL in their records and link them to your account.
        This does not give visit access by itself.
      </Text>
    </Card>
  );

  const renderOption = (key, label, selected, onPress) => (
    <Pressable
      key={key}
      onPress={onPress}
      style={[styles.optionRow, selected && styles.optionRowSelected]}
      accessibilityRole="radio"
      accessibilityState={{ selected }}
    >
      <View style={[styles.radio, selected && styles.radioSelected]}>
        {selected ? <View style={styles.radioDot} /> : null}
      </View>
      <Text style={[styles.optionLabel, selected && styles.optionLabelSelected]}>{label}</Text>
    </Pressable>
  );

  const renderRelationshipStep = () => (
    <>
      <Card style={styles.card}>
        <Text style={styles.sectionTitle}>Your Relationship To The PDL</Text>
        {errors.relationship ? <Text style={styles.stepError}>{errors.relationship}</Text> : null}
        {REGISTRATION_RELATIONSHIPS.map((item) =>
          renderOption(item.id, item.label, relationship === item.id, () => {
            // Only a different relationship changes the required documents.
            if (item.id !== relationship) {
              setFiles({});
              setHasChildrenTogether(null);
            }
            setRelationship(item.id);
            clearError('relationship');
          }),
        )}
      </Card>
      {asksChildrenTogether(relationship) ? (
        <Card style={[styles.card, styles.cardStacked]}>
          <Text style={styles.docTitle}>Do you and the PDL have children together?</Text>
          <Text style={styles.hint}>
            With children, also upload a child&apos;s PSA birth certificate.
          </Text>
          {errors.children_together ? (
            <Text style={styles.stepError}>{errors.children_together}</Text>
          ) : null}
          {CHILDREN_OPTIONS.map((option) =>
            renderOption(String(option.value), option.label, hasChildrenTogether === option.value, () => {
              if (option.value !== hasChildrenTogether) setFiles({});
              setHasChildrenTogether(option.value);
              clearError('children_together');
            }),
          )}
        </Card>
      ) : null}
    </>
  );

  const renderDocumentCard = (doc) => {
    const entry = files[doc.key];
    const uploaded = Boolean(entry?.uri);
    const chooserOpen = chooserKey === doc.key;
    const action = uploaded ? 'Replace' : 'Upload';

    return (
      <View key={doc.key} style={styles.docCard}>
        <View style={styles.docHeader}>
          <Text style={styles.docTitle}>{doc.label}</Text>
          <Pressable
            onPress={() => setChooserKey(chooserOpen ? null : doc.key)}
            style={styles.uploadBtn}
            accessibilityRole="button"
            accessibilityLabel={`${action} ${doc.label}`}
            accessibilityState={{ expanded: chooserOpen }}
          >
            <Text style={styles.uploadBtnText}>{action}</Text>
          </Pressable>
        </View>
        {chooserOpen ? (
          <View style={styles.sourceRow}>
            <Pressable
              onPress={() => pickDocument(doc.key, true)}
              style={styles.sourceBtn}
              accessibilityRole="button"
              accessibilityLabel={`Take a photo of your ${doc.label}`}
            >
              <Ionicons name="camera-outline" size={18} color={colors.primaryTeal} />
              <Text style={styles.sourceBtnText}>Take Photo</Text>
            </Pressable>
            <Pressable
              onPress={() => pickDocument(doc.key, false)}
              style={styles.sourceBtn}
              accessibilityRole="button"
              accessibilityLabel={`Choose your ${doc.label} from the gallery`}
            >
              <Ionicons name="images-outline" size={18} color={colors.primaryTeal} />
              <Text style={styles.sourceBtnText}>Gallery</Text>
            </Pressable>
          </View>
        ) : null}
        <Text style={styles.hint}>JPG, PNG, or WEBP image, up to 10 MB.</Text>
        {uploaded ? (
          <View style={styles.uploadedRow}>
            <Image
              source={{ uri: entry.uri }}
              style={styles.uploadedThumb}
              resizeMode="cover"
              accessibilityLabel={`Preview of ${doc.label}`}
            />
            <Text style={styles.uploadedName} numberOfLines={1}>
              {entry.fileName ?? 'Document attached'}
            </Text>
          </View>
        ) : null}
        {errors[doc.key] ? <Text style={formStyles.error}>{errors[doc.key]}</Text> : null}
      </View>
    );
  };

  const renderDocumentsStep = () => (
    <>
      {errors.documents ? <Text style={styles.stepError}>{errors.documents}</Text> : null}
      <Card style={styles.card}>
        <Text style={styles.sectionTitle}>Documents for {getRelationshipLabel(relationship)}</Text>
        {prefill ? (
          <Text style={styles.hint}>Attach the documents again for this new request.</Text>
        ) : null}
        {requiredDocs.map(renderDocumentCard)}
      </Card>
      <View style={styles.noticeBox}>
        <Ionicons name="information-circle-outline" size={18} color={colors.primaryNavy} />
        <Text style={styles.noticeText}>
          Upload only the documents listed for this relationship. Facility staff review each one after
          they find the PDL and link it to your account.
        </Text>
      </View>
    </>
  );

  const renderReviewRow = (label, value) => (
    <View style={styles.reviewRow} key={label}>
      <Text style={styles.reviewLabel}>{label}</Text>
      <Text style={styles.reviewValue}>{value || '—'}</Text>
    </View>
  );

  const renderReviewStep = () => (
    <Card style={styles.card}>
      <Text style={styles.sectionTitle}>Review Your Request</Text>
      {renderReviewRow('PDL Name', normalizeName(pdlName))}
      {renderReviewRow('Relationship', getRelationshipLabel(relationship))}
      {asksChildrenTogether(relationship)
        ? renderReviewRow('Children Together', hasChildrenTogether ? 'Yes' : 'No')
        : null}
      <Text style={styles.reviewLabel}>Documents</Text>
      {requiredDocs.map((doc) => (
        <View key={doc.key} style={styles.uploadedRow}>
          {files[doc.key]?.uri ? (
            <Image source={{ uri: files[doc.key].uri }} style={styles.uploadedThumb} resizeMode="cover" />
          ) : null}
          <Text style={styles.reviewValue}>{doc.label}</Text>
        </View>
      ))}
      {errors.form ? <Text style={[formStyles.error, styles.formError]}>{errors.form}</Text> : null}
      <Text style={[styles.hint, styles.reviewHint]}>
        You can have one request waiting at a time. You can cancel it under Profile → My PDLs while staff
        have not reviewed it yet.
      </Text>
    </Card>
  );

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Add Another PDL" onBack={goBack} />
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView
          ref={scrollRef}
          contentContainerStyle={commonStyles.scrollContent}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          <Text
            style={styles.stepLabel}
            accessibilityRole="progressbar"
            accessibilityValue={{ min: 1, max: TOTAL_STEPS, now: step }}
          >
            Step {step} of {TOTAL_STEPS} · {PDL_REQUEST_STEPS[step - 1]}
          </Text>

          {step === 1 && renderNameStep()}
          {step === 2 && renderRelationshipStep()}
          {step === 3 && renderDocumentsStep()}
          {step === 4 && renderReviewStep()}

          <View style={styles.actions}>
            {step < TOTAL_STEPS ? (
              <Button title="Continue" onPress={onContinue} accessibilityLabel="Continue to the next step" />
            ) : (
              <Button
                title="Send Request"
                onPress={onSubmit}
                loading={submitting}
                disabled={submitting}
                accessibilityLabel="Send the request to facility staff"
              />
            )}
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

// Same look as the registration wizard's cards, options and document rows.
const styles = StyleSheet.create({
  flex: { flex: 1 },
  stepLabel: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  card: { marginBottom: spacing.sm },
  cardStacked: { marginTop: spacing.xs },
  sectionTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  hint: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  stepError: {
    ...typography.metadata,
    color: colors.danger,
    marginBottom: spacing.sm,
  },
  formError: { marginBottom: spacing.sm },
  // Small inline notice: the staff reason a resubmission should correct.
  reasonNotice: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.xs,
    padding: spacing.sm,
    marginBottom: spacing.sm,
    borderRadius: layout.borderRadiusSm,
    backgroundColor: 'rgba(239, 68, 68, 0.06)',
  },
  reasonNoticeText: {
    ...typography.metadata,
    color: colors.textPrimary,
    flex: 1,
  },
  reasonNoticeLabel: {
    fontWeight: '600',
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
  sourceRow: {
    flexDirection: 'row',
    gap: spacing.sm,
    marginBottom: spacing.sm,
  },
  sourceBtn: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.xs,
    minHeight: layout.iconButtonSize,
    paddingHorizontal: spacing.sm,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.primaryTeal,
  },
  sourceBtnText: {
    ...typography.statusLabel,
    color: colors.primaryTeal,
    fontWeight: '600',
  },
  uploadedRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.xs,
    marginBottom: spacing.xs,
  },
  uploadedThumb: {
    width: 56,
    height: 56,
    borderRadius: layout.borderRadiusSm,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
  },
  uploadedName: {
    ...typography.metadata,
    color: colors.success,
    fontWeight: '600',
    flex: 1,
  },
  noticeBox: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    marginTop: layout.cardGap,
    padding: spacing.sm,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
  },
  noticeText: {
    ...typography.metadata,
    color: colors.textPrimary,
    flex: 1,
    lineHeight: 18,
  },
  reviewRow: { marginBottom: spacing.sm },
  reviewLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.xs,
  },
  reviewValue: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
  },
  reviewHint: { marginTop: spacing.sm, marginBottom: 0 },
  actions: { marginTop: spacing.md },
});
