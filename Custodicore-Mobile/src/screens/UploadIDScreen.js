import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Image,
  LayoutAnimation,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  UIManager,
  useWindowDimensions,
  View,
} from 'react-native';
import { pickPhotoFromCamera, pickPhotoFromGallery } from '../services/imagePickerService';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Button,
  Card,
  StackScreenHeader,
  colors,
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { LoadingSpinner } from '../components';
import useVisitorVerification from '../hooks/useVisitorVerification';
import {
  GOVERNMENT_ID_TYPES,
  uploadGovernmentId,
  uploadSupportingDocument,
} from '../repositories/verificationRepository';
import { getDocumentStatusDisplay } from '../utils/verificationDocumentUi';
import { goBackOr } from '../utils/safeNavigation';
import { GOVERNMENT_ID_KEY, isDocumentUploaded } from '../utils/visitorVerificationDocuments';

if (Platform.OS === 'android' && UIManager.setLayoutAnimationEnabledExperimental) {
  UIManager.setLayoutAnimationEnabledExperimental(true);
}

const VERIFICATION_TIPS = [
  { icon: 'sunny-outline', text: 'Use clear, well-lit photos' },
  { icon: 'flash-off-outline', text: 'Avoid glare and reflections' },
  { icon: 'text-outline', text: 'Ensure all text is readable' },
  { icon: 'scan-outline', text: 'Capture the full document' },
  { icon: 'shield-checkmark-outline', text: 'Use a valid government-issued ID' },
];

/**
 * @param {object} props
 * @param {string} props.title
 * @param {string} [props.subtitle]
 */
function ScreenIntro({ title, subtitle }) {
  return (
    <View style={commonStyles.pageIntro}>
      <Text style={commonStyles.pageTitle}>{title}</Text>
      {subtitle ? <Text style={commonStyles.pageSubtitle}>{subtitle}</Text> : null}
    </View>
  );
}

/**
 * @param {object} props
 * @param {{ key: string; label: string; uploadStatus?: string }} props.doc
 * @param {() => void} props.onPress
 */
function DocumentSelectCard({ doc, onPress }) {
  const status = doc.uploadStatus ? getDocumentStatusDisplay(doc.uploadStatus) : null;

  return (
    <Card onPress={onPress} accessibilityLabel={`Upload ${doc.label}`} style={styles.docSelectCard}>
      <View style={styles.docSelectRow}>
        <View style={styles.docSelectIconWrap}>
          <Ionicons name="document-text-outline" size={22} color={colors.primaryTeal} />
        </View>
        <View style={styles.docSelectText}>
          <Text style={styles.docSelectLabel}>{doc.label}</Text>
          {status ? (
            <Text style={[styles.docSelectStatus, { color: status.color }]}>{status.label}</Text>
          ) : (
            <Text style={styles.docSelectHint}>Tap to upload</Text>
          )}
        </View>
        <Ionicons name="chevron-forward" size={20} color={colors.textSecondary} />
      </View>
    </Card>
  );
}

/**
 * @param {object} props
 * @param {string} props.label
 */
function DocTypeBadge({ label }) {
  return (
    <View style={styles.docBadge}>
      <Ionicons name="document-attach-outline" size={16} color={colors.primaryTeal} />
      <Text style={styles.docBadgeText}>{label}</Text>
    </View>
  );
}

/**
 * @param {object} props
 * @param {string} props.id
 * @param {boolean} props.selected
 * @param {() => void} props.onPress
 */
function AcceptedIdChip({ id, selected, onPress }) {
  return (
    <Pressable
      onPress={onPress}
      style={[styles.idChip, selected && styles.idChipSelected]}
      accessibilityRole="radio"
      accessibilityState={{ selected }}
      accessibilityLabel={`${id}${selected ? ', selected' : ''}`}
    >
      <Ionicons
        name={selected ? 'checkmark-circle' : 'card-outline'}
        size={14}
        color={selected ? colors.primaryTeal : colors.primaryNavy}
      />
      <Text style={styles.idChipText}>{id}</Text>
    </Pressable>
  );
}

/**
 * @param {object} props
 * @param {string} props.icon
 * @param {string} props.label
 * @param {() => void} props.onPress
 * @param {string} props.accessibilityLabel
 * @param {'primary' | 'secondary'} [props.variant]
 */
function UploadActionButton({ icon, label, onPress, accessibilityLabel, variant = 'primary' }) {
  const isPrimary = variant === 'primary';
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [
        styles.uploadActionBtn,
        isPrimary ? styles.uploadActionPrimary : styles.uploadActionSecondary,
        pressed && styles.uploadActionPressed,
      ]}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
    >
      <Ionicons
        name={icon}
        size={20}
        color={isPrimary ? colors.white : colors.primaryTeal}
      />
      <Text
        style={[
          styles.uploadActionLabel,
          isPrimary ? styles.uploadActionLabelPrimary : styles.uploadActionLabelSecondary,
        ]}
      >
        {label}
      </Text>
    </Pressable>
  );
}

/** Responsive preview heights — ~25% taller than prior 160 / 200 px baselines. */
function useUploadPreviewMetrics() {
  const { height: windowHeight } = useWindowDimensions();
  const emptyMinHeight = Math.round(Math.min(Math.max(windowHeight * 0.24, 200), 272));
  const filledMinHeight = Math.round(emptyMinHeight * 1.28);
  const imageHeight = Math.round(filledMinHeight - 44);
  return { emptyMinHeight, filledMinHeight, imageHeight };
}

function SecurityTrustMessage() {
  return (
    <View style={styles.trustRow} accessibilityRole="text">
      <Ionicons name="shield-checkmark-outline" size={15} color={colors.textSecondary} />
      <Text style={styles.trustText}>
        Your ID is used only for visitor verification and is securely stored.
      </Text>
    </View>
  );
}

function CollapsibleVerificationTips() {
  const [expanded, setExpanded] = useState(false);

  const toggle = () => {
    LayoutAnimation.configureNext(LayoutAnimation.Presets.easeInEaseOut);
    setExpanded((v) => !v);
  };

  return (
    <Card style={styles.tipsCard}>
      <Pressable
        onPress={toggle}
        style={({ pressed }) => [styles.tipsHeader, pressed && styles.tipsHeaderPressed]}
        accessibilityRole="button"
        accessibilityState={{ expanded }}
        accessibilityLabel={expanded ? 'Collapse verification tips' : 'Expand verification tips'}
      >
        <View style={styles.tipsHeaderLeft}>
          <View style={styles.tipsIconWrap}>
            <Ionicons name="bulb-outline" size={18} color={colors.primaryTeal} />
          </View>
          <Text style={styles.tipsHeaderTitle}>Verification tips</Text>
        </View>
        <Ionicons
          name={expanded ? 'chevron-up' : 'chevron-down'}
          size={20}
          color={colors.textSecondary}
        />
      </Pressable>
      {expanded ? (
        <View style={styles.tipsBody}>
          {VERIFICATION_TIPS.map((tip) => (
            <View key={tip.text} style={styles.tipRow}>
              <Ionicons name={tip.icon} size={16} color={colors.primaryTeal} />
              <Text style={styles.tipText}>{tip.text}</Text>
            </View>
          ))}
        </View>
      ) : null}
    </Card>
  );
}

/**
 * @param {object} props
 * @param {string | null} props.uri
 * @param {string | null} props.fileName
 * @param {string} props.docLabel
 * @param {() => void} props.onTakePhoto
 * @param {() => void} props.onChooseGallery
 */
function UploadDocumentCard({ uri, fileName, docLabel, onTakePhoto, onChooseGallery }) {
  const hasPhoto = Boolean(uri);
  const { emptyMinHeight, filledMinHeight, imageHeight } = useUploadPreviewMetrics();

  return (
    <Card style={styles.uploadCard}>
      <Text style={styles.uploadCardTitle}>Document photo</Text>
      <Text style={styles.uploadCardHint}>
        {hasPhoto
          ? 'Review your photo below, then submit when ready.'
          : 'Add a clear photo of your government-issued ID.'}
      </Text>

      <View
        style={[
          styles.uploadPreviewZone,
          { minHeight: hasPhoto ? filledMinHeight : emptyMinHeight },
          hasPhoto && styles.uploadPreviewZoneFilled,
        ]}
      >
        {hasPhoto ? (
          <>
            <Image
              source={{ uri }}
              style={[styles.uploadPreviewImage, { height: imageHeight }]}
              resizeMode="contain"
              accessibilityLabel={`Preview of ${docLabel}`}
            />
            <View style={styles.uploadPreviewBadge}>
              <Ionicons name="checkmark-circle" size={16} color={colors.success} />
              <Text style={styles.uploadPreviewBadgeText} numberOfLines={1}>
                {fileName ?? 'Photo attached'}
              </Text>
            </View>
          </>
        ) : (
          <View style={[styles.uploadEmpty, { minHeight: emptyMinHeight }]}>
            <View style={styles.uploadEmptyIcon}>
              <Ionicons name="cloud-upload-outline" size={36} color={colors.primaryTeal} />
            </View>
            <Text style={styles.uploadEmptyTitle}>No photo yet</Text>
            <Text style={styles.uploadEmptyHint}>Take a photo or choose from your gallery</Text>
          </View>
        )}
      </View>

      <SecurityTrustMessage />

      <View style={styles.uploadActions}>
        {hasPhoto ? (
          <>
            <UploadActionButton
              icon="camera-outline"
              label="Retake Photo"
              onPress={onTakePhoto}
              accessibilityLabel={`Retake photo for ${docLabel}`}
            />
            <UploadActionButton
              icon="images-outline"
              label="Change Photo"
              variant="secondary"
              onPress={onChooseGallery}
              accessibilityLabel={`Change ${docLabel} from gallery`}
            />
          </>
        ) : (
          <>
            <UploadActionButton
              icon="camera-outline"
              label="Take Photo"
              onPress={onTakePhoto}
              accessibilityLabel={`Take photo for ${docLabel}`}
            />
            <UploadActionButton
              icon="images-outline"
              label="Gallery"
              variant="secondary"
              onPress={onChooseGallery}
              accessibilityLabel={`Choose ${docLabel} from gallery`}
            />
          </>
        )}
      </View>
    </Card>
  );
}

/**
 * Focused document upload — one document at a time (v2.1 / BJMP).
 * Verified documents cannot be selected or replaced.
 */
export default function UploadIDScreen({ navigation, route }) {
  const initialDocumentKey = route.params?.documentKey ?? null;
  const {
    verification,
    loading: loadingVerification,
    error: verificationError,
    reload,
  } = useVisitorVerification();

  const documents = useMemo(() => verification?.documents ?? [], [verification]);
  // Verified documents are locked; supporting documents need a staff-linked relationship.
  const uploadableDocs = useMemo(() => documents.filter((doc) => doc.canUpload), [documents]);
  const allVerified = documents.length > 0 && documents.every((d) => d.uploadStatus === 'verified');

  const [activeDocKey, setActiveDocKey] = useState(initialDocumentKey);
  const [entry, setEntry] = useState({ uri: null, status: 'pending', fileName: null });
  const [idType, setIdType] = useState(/** @type {string | null} */ (null));
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!initialDocumentKey || !verification) return;
    const doc = verification.documents.find((d) => d.key === initialDocumentKey);
    if (doc && !doc.canUpload) {
      const locked = doc.uploadStatus === 'verified';
      Alert.alert(
        locked ? 'Document locked' : 'Not available yet',
        locked
          ? 'This document is verified and cannot be replaced.'
          : (doc.unavailableReason ?? 'This document cannot be uploaded right now.'),
        [{ text: 'OK', onPress: () => goBackOr(navigation) }],
      );
    }
  }, [initialDocumentKey, verification, navigation]);

  const activeDoc = useMemo(
    () => uploadableDocs.find((doc) => doc.key === activeDocKey) ?? null,
    [uploadableDocs, activeDocKey],
  );

  const pickPhoto = useCallback(
    async (useCamera) => {
      if (!activeDocKey) return;
      const picked = useCamera
        ? await pickPhotoFromCamera({ forUpload: true })
        : await pickPhotoFromGallery({ forUpload: true });
      if (picked?.uri) {
        LayoutAnimation.configureNext(LayoutAnimation.Presets.easeInEaseOut);
        setEntry({
          uri: picked.uri,
          fileName: picked.fileName ?? null,
          status: 'uploaded',
        });
        setError('');
      }
    },
    [activeDocKey],
  );

  const onSubmit = useCallback(async () => {
    if (submitting || !activeDoc) return;
    if (!isDocumentUploaded(entry)) {
      setError('Please add a photo before submitting.');
      return;
    }
    const isGovernmentId = activeDoc.key === GOVERNMENT_ID_KEY;
    if (isGovernmentId && !idType) {
      setError('Select which government ID you are uploading.');
      return;
    }

    setSubmitting(true);
    setError('');
    try {
      if (isGovernmentId) {
        await uploadGovernmentId({ uri: entry.uri, fileName: entry.fileName, documentType: idType });
      } else {
        await uploadSupportingDocument({
          relationshipRecordId: activeDoc.relationshipRecordId,
          uri: entry.uri,
          fileName: entry.fileName,
        });
      }
      reload();
      Alert.alert(
        'Document submitted',
        `${activeDoc.label} was uploaded and is now pending staff review.`,
        [{ text: 'OK', onPress: () => goBackOr(navigation) }],
      );
    } catch (e) {
      // 401 is handled globally (session cleared); 409/422/network/server
      // messages come from toRequestError in services/api.js.
      setError(
        e instanceof Error && e.message.trim() ? e.message : 'Upload failed. Please try again.',
      );
    } finally {
      setSubmitting(false);
    }
  }, [submitting, activeDoc, entry, idType, reload, navigation]);

  const onBack = useCallback(() => {
    if (activeDocKey && !initialDocumentKey) {
      setActiveDocKey(null);
      setEntry({ uri: null, status: 'pending', fileName: null });
      setIdType(null);
      setError('');
    } else {
      goBackOr(navigation);
    }
  }, [activeDocKey, initialDocumentKey, navigation]);

  const screenTitle = activeDoc ? activeDoc.label : 'Upload Document';
  const screenSubtitle = activeDoc
    ? 'Position your ID clearly in the frame, then submit for BJMP verification.'
    : 'Select a document to upload for visitor verification.';

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader
        title={activeDoc ? 'Upload' : 'Documents'}
        onBack={onBack}
      />

      <ScrollView
        style={styles.scroll}
        contentContainerStyle={[
          commonStyles.scrollContent,
          activeDocKey ? styles.scrollContentWithFooter : null,
        ]}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        <ScreenIntro title={screenTitle} subtitle={screenSubtitle} />

        {loadingVerification && !verification ? (
          <LoadingSpinner message="Loading documents…" compact />
        ) : verificationError && !verification ? (
          <Card style={styles.emptyCard}>
            <Text style={styles.emptyTitle}>{"Couldn't load documents"}</Text>
            <Text style={styles.emptyMessage}>{verificationError}</Text>
            <Button title="Retry" onPress={reload} accessibilityLabel="Retry loading documents" />
          </Card>
        ) : !activeDocKey ? (
          <>
            {uploadableDocs.length === 0 ? (
              <Card style={styles.emptyCard}>
                <View style={styles.emptyIconWrap}>
                  <Ionicons name="checkmark-done-outline" size={28} color={colors.success} />
                </View>
                <Text style={styles.emptyTitle}>{allVerified ? 'All set' : 'Nothing to upload yet'}</Text>
                <Text style={styles.emptyMessage}>
                  {allVerified
                    ? 'All required documents are verified. No uploads are needed.'
                    : 'Your submitted documents are under review. Remaining documents can be uploaded once facility staff link your account to a PDL.'}
                </Text>
              </Card>
            ) : (
              <View style={styles.docList}>
                {uploadableDocs.map((doc) => (
                  <DocumentSelectCard
                    key={doc.key}
                    doc={doc}
                    onPress={() => {
                      setActiveDocKey(doc.key);
                      setEntry({ uri: null, status: 'pending', fileName: null });
                      setIdType(null);
                      setError('');
                    }}
                  />
                ))}
              </View>
            )}
          </>
        ) : (
          <>
            <DocTypeBadge label={activeDoc?.label ?? 'Document'} />

            <UploadDocumentCard
              uri={entry.uri}
              fileName={entry.fileName}
              docLabel={activeDoc?.label ?? 'document'}
              onTakePhoto={() => pickPhoto(true)}
              onChooseGallery={() => pickPhoto(false)}
            />

            {activeDocKey === GOVERNMENT_ID_KEY ? (
              <View style={styles.acceptedIdsSection}>
                <Text style={styles.acceptedIdsLabel}>Select your ID type</Text>
                <View style={styles.chipGrid} accessibilityRole="radiogroup">
                  {GOVERNMENT_ID_TYPES.map((type) => (
                    <AcceptedIdChip
                      key={type.key}
                      id={type.label}
                      selected={idType === type.key}
                      onPress={() => {
                        setIdType(type.key);
                        setError('');
                      }}
                    />
                  ))}
                </View>
              </View>
            ) : null}

            <CollapsibleVerificationTips />
          </>
        )}
      </ScrollView>

      {activeDoc ? (
        <View style={styles.footer}>
          {error ? (
            <Text style={commonStyles.fieldError} accessibilityRole="alert">
              {error}
            </Text>
          ) : null}
          <Button
            title="Submit Document"
            onPress={onSubmit}
            loading={submitting}
            disabled={submitting}
            accessibilityLabel={submitting ? 'Uploading document' : `Submit ${activeDoc?.label}`}
          />
        </View>
      ) : null}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  scroll: { flex: 1 },
  scrollContentWithFooter: {
    paddingBottom: spacing.md,
  },
  docList: {
    gap: spacing.sm,
  },
  docSelectCard: {
    marginBottom: 0,
  },
  docSelectRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  docSelectIconWrap: {
    width: layout.iconButtonSize,
    height: layout.iconButtonSize,
    borderRadius: layout.buttonRadius,
    backgroundColor: 'rgba(13, 165, 138, 0.1)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  docSelectText: {
    flex: 1,
    minWidth: 0,
  },
  docSelectLabel: {
    ...typography.cardTitle,
    color: colors.textPrimary,
  },
  docSelectHint: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.xs,
  },
  docSelectStatus: {
    ...typography.metadata,
    fontWeight: '600',
    marginTop: spacing.xs,
  },
  emptyCard: {
    alignItems: 'center',
    paddingVertical: spacing.lg,
  },
  emptyIconWrap: {
    width: layout.iconButtonSize + spacing.sm,
    height: layout.iconButtonSize + spacing.sm,
    borderRadius: (layout.iconButtonSize + spacing.sm) / 2,
    backgroundColor: 'rgba(22, 163, 74, 0.12)',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.md,
  },
  emptyTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.xs,
  },
  emptyMessage: {
    ...typography.body,
    color: colors.textSecondary,
    textAlign: 'center',
    lineHeight: 24,
  },
  docBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: spacing.xs,
    backgroundColor: 'rgba(13, 165, 138, 0.1)',
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
    borderRadius: layout.chipRadius,
    marginBottom: spacing.sm,
  },
  docBadgeText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.primaryNavy,
  },
  acceptedIdsSection: {
    marginBottom: spacing.sm,
  },
  acceptedIdsLabel: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.xs,
  },
  chipGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.xs,
    rowGap: spacing.xs,
  },
  idChipSelected: {
    borderColor: colors.primaryTeal,
    backgroundColor: 'rgba(13, 165, 138, 0.1)',
  },
  idChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
    borderRadius: layout.borderRadiusSm,
    maxWidth: '100%',
  },
  idChipText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  uploadCard: {
    marginBottom: spacing.sm,
    paddingVertical: spacing.md,
  },
  uploadCardTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.xs,
  },
  uploadCardHint: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
    lineHeight: 20,
  },
  uploadPreviewZone: {
    borderRadius: layout.cardRadius,
    borderWidth: 2,
    borderStyle: 'dashed',
    borderColor: colors.border,
    backgroundColor: colors.background,
    overflow: 'hidden',
    marginBottom: spacing.sm,
  },
  uploadPreviewZoneFilled: {
    borderStyle: 'solid',
    borderColor: colors.primaryTeal,
    backgroundColor: colors.card,
  },
  uploadPreviewImage: {
    width: '100%',
    backgroundColor: colors.background,
  },
  trustRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    marginBottom: spacing.md,
    paddingHorizontal: spacing.xs,
  },
  trustText: {
    ...typography.metadata,
    color: colors.textSecondary,
    flex: 1,
    lineHeight: 18,
  },
  uploadPreviewBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.sm,
    backgroundColor: colors.card,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
  },
  uploadPreviewBadgeText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  uploadEmpty: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.lg,
  },
  uploadEmptyIcon: {
    width: 72,
    height: 72,
    borderRadius: 36,
    backgroundColor: 'rgba(13, 165, 138, 0.12)',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.sm,
  },
  uploadEmptyTitle: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing.xs,
  },
  uploadEmptyHint: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  uploadActions: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  uploadActionBtn: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.xs,
    height: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
  },
  uploadActionPrimary: {
    backgroundColor: colors.primaryTeal,
  },
  uploadActionSecondary: {
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  uploadActionPressed: {
    opacity: 0.88,
  },
  uploadActionLabel: {
    ...typography.body,
    fontWeight: '600',
  },
  uploadActionLabelPrimary: {
    color: colors.white,
  },
  uploadActionLabelSecondary: {
    color: colors.primaryTeal,
  },
  tipsCard: {
    marginBottom: spacing.sm,
  },
  tipsHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  tipsHeaderPressed: {
    opacity: 0.9,
  },
  tipsHeaderLeft: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    flex: 1,
  },
  tipsIconWrap: {
    width: layout.iconButtonSize - spacing.md,
    height: layout.iconButtonSize - spacing.md,
    borderRadius: (layout.iconButtonSize - spacing.md) / 2,
    backgroundColor: 'rgba(13, 165, 138, 0.1)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  tipsHeaderTitle: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  tipsBody: {
    marginTop: spacing.md,
    paddingTop: spacing.md,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
    gap: spacing.sm,
  },
  tipRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  tipText: {
    ...typography.metadata,
    color: colors.textPrimary,
    flex: 1,
    lineHeight: 20,
  },
  footer: {
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.sm,
    paddingBottom: spacing.md,
    backgroundColor: colors.background,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
    gap: spacing.sm,
  },
});
