import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Image,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { launchCamera, launchImageLibrary } from 'react-native-image-picker';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, colors, layout, spacing, typography } from '../designSystem';
import { useAuth } from '../hooks/useAuth';
import { getMockVisitorVerification } from '../mock/visitorVerificationDocuments.mock';
import {
  canModifyDocument,
  getDocumentStatusDisplay,
} from '../utils/verificationDocumentUi';
import {
  ACCEPTED_GOVERNMENT_IDS,
  GOVERNMENT_ID_KEY,
  getRequiredVerificationDocuments,
  isDocumentUploaded,
} from '../utils/visitorVerificationDocuments';

const VERIFICATION_TIPS = [
  'Use clear photos',
  'Avoid glare',
  'Ensure text is readable',
  'Upload complete documents',
  'Use valid government-issued IDs',
];

function UploadActionRow({ icon, label, onPress, accessibilityLabel }) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.actionRow, pressed && styles.actionRowPressed]}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
    >
      <Ionicons name={icon} size={20} color={colors.primaryTeal} />
      <Text style={styles.actionLabel}>{label}</Text>
    </Pressable>
  );
}

/**
 * @param {object} props
 * @param {{ key: string; label: string; uploadStatus?: string }} props.doc
 * @param {boolean} props.isLast
 * @param {() => void} props.onPress
 */
function DocumentPickerRow({ doc, isLast, onPress }) {
  const status = doc.uploadStatus ? getDocumentStatusDisplay(doc.uploadStatus) : null;

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [
        styles.pickerRow,
        !isLast && styles.pickerRowBorder,
        pressed && styles.pickerRowPressed,
      ]}
      accessibilityRole="button"
      accessibilityLabel={`Upload ${doc.label}`}
    >
      <Text style={styles.pickerLabel}>{doc.label}</Text>
      {status ? (
        <Text style={[styles.pickerStatus, { color: status.color }]}>{status.label}</Text>
      ) : (
        <Ionicons name="chevron-forward" size={18} color={colors.textSecondary} />
      )}
    </Pressable>
  );
}

/**
 * Focused document upload — one document at a time (v2.1 / BJMP).
 * Verified documents cannot be selected or replaced.
 */
export default function UploadIDScreen({ navigation, route }) {
  const { registrationSummary } = useAuth();
  const relationshipId =
    route.params?.relationshipId ?? registrationSummary?.relationship ?? 'spouse';
  const initialDocumentKey = route.params?.documentKey ?? null;

  const verification = useMemo(
    () => getMockVisitorVerification(relationshipId),
    [relationshipId],
  );

  const uploadableDocs = useMemo(() => {
    const required = getRequiredVerificationDocuments(relationshipId);
    return required
      .map((doc) => {
        const mockDoc = verification.documents.find((d) => d.key === doc.key);
        return {
          ...doc,
          uploadStatus: mockDoc?.uploadStatus ?? 'pending',
        };
      })
      .filter((doc) => canModifyDocument(doc.uploadStatus));
  }, [relationshipId, verification.documents]);

  const [activeDocKey, setActiveDocKey] = useState(() => {
    if (!initialDocumentKey) return null;
    const mockDoc = verification.documents.find((d) => d.key === initialDocumentKey);
    if (mockDoc && !canModifyDocument(mockDoc.uploadStatus)) return null;
    return initialDocumentKey;
  });

  const [entry, setEntry] = useState({ uri: null, status: 'pending', fileName: null });
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!initialDocumentKey) return;
    const mockDoc = verification.documents.find((d) => d.key === initialDocumentKey);
    if (mockDoc && !canModifyDocument(mockDoc.uploadStatus)) {
      Alert.alert(
        'Document locked',
        'This document is verified and cannot be replaced.',
        [{ text: 'OK', onPress: () => navigation.goBack() }],
      );
    }
  }, [initialDocumentKey, verification.documents, navigation]);

  const activeDoc = useMemo(
    () => uploadableDocs.find((doc) => doc.key === activeDocKey) ?? null,
    [uploadableDocs, activeDocKey],
  );

  const pickPhoto = useCallback(async (useCamera) => {
    if (!activeDocKey) return;
    const options = { mediaType: 'photo', saveToPhotos: false };
    const result = useCamera
      ? await launchCamera(options)
      : await launchImageLibrary(options);
    const asset = result.assets?.[0];
    if (asset?.uri) {
      setEntry({
        uri: asset.uri,
        fileName: asset.fileName ?? 'Photo attached',
        status: 'uploaded',
      });
      setError('');
    } else if (result.errorMessage) {
      Alert.alert('Could not open image', result.errorMessage);
    }
  }, [activeDocKey]);

  const onSubmit = useCallback(async () => {
    if (submitting || !activeDocKey) return;
    if (!isDocumentUploaded(entry)) {
      setError('Please add a photo before submitting.');
      return;
    }

    setSubmitting(true);
    try {
      await new Promise((r) => setTimeout(r, 600));
      Alert.alert(
        'Document submitted',
        `${activeDoc?.label ?? 'Document'} received for review.`,
        [{ text: 'OK', onPress: () => navigation.goBack() }],
      );
    } catch {
      Alert.alert('Submission failed', 'Please try again.');
    } finally {
      setSubmitting(false);
    }
  }, [submitting, activeDocKey, entry, activeDoc, navigation]);

  const screenTitle = activeDoc ? `Upload ${activeDoc.label}` : 'Upload Document';

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <View style={styles.topBar}>
        <Pressable
          onPress={() => {
            if (activeDocKey && !initialDocumentKey) {
              setActiveDocKey(null);
              setEntry({ uri: null, status: 'pending', fileName: null });
              setError('');
            } else {
              navigation.goBack();
            }
          }}
          style={styles.backButton}
          accessibilityRole="button"
          accessibilityLabel="Go back"
        >
          <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
        </Pressable>
        <Text style={styles.screenTitle} numberOfLines={1}>
          {screenTitle}
        </Text>
        <View style={styles.backPlaceholder} />
      </View>

      <ScrollView
        contentContainerStyle={styles.scroll}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        {!activeDocKey ? (
          <>
            {uploadableDocs.length === 0 ? (
              <Text style={styles.emptyHint}>
                All required documents are verified. No uploads are needed.
              </Text>
            ) : (
              <>
                <Text style={styles.pickerHint}>Select a document to upload.</Text>
                <View style={styles.pickerList}>
                  {uploadableDocs.map((doc, index) => (
                    <DocumentPickerRow
                      key={doc.key}
                      doc={doc}
                      isLast={index === uploadableDocs.length - 1}
                      onPress={() => {
                        setActiveDocKey(doc.key);
                        setEntry({ uri: null, status: 'pending', fileName: null });
                        setError('');
                      }}
                    />
                  ))}
                </View>
              </>
            )}
          </>
        ) : (
          <>
            <Text style={styles.docHeading}>{activeDoc?.label}</Text>

            {activeDocKey === GOVERNMENT_ID_KEY ? (
              <View style={styles.section}>
                <Text style={styles.sectionTitle}>Accepted IDs</Text>
                {ACCEPTED_GOVERNMENT_IDS.map((id) => (
                  <Text key={id} style={styles.listItem}>
                    {id}
                  </Text>
                ))}
              </View>
            ) : null}

            {entry.uri ? (
              <View style={styles.previewRow}>
                <Image
                  source={{ uri: entry.uri }}
                  style={styles.preview}
                  resizeMode="cover"
                  accessibilityLabel={`Preview of ${activeDoc?.label}`}
                />
                <Text style={styles.previewName} numberOfLines={2}>
                  {entry.fileName ?? 'Photo attached'}
                </Text>
              </View>
            ) : null}

            <UploadActionRow
              icon="camera-outline"
              label="Take Photo"
              onPress={() => pickPhoto(true)}
              accessibilityLabel={`Take photo for ${activeDoc?.label}`}
            />
            <UploadActionRow
              icon="images-outline"
              label="Choose From Gallery"
              onPress={() => pickPhoto(false)}
              accessibilityLabel={`Choose ${activeDoc?.label} from gallery`}
            />

            {error ? (
              <Text style={styles.fieldError} accessibilityRole="alert">
                {error}
              </Text>
            ) : null}

            <View style={styles.section}>
              <Text style={styles.sectionTitle}>Verification Tips</Text>
              {VERIFICATION_TIPS.map((tip) => (
                <Text key={tip} style={styles.listItem}>
                  {tip}
                </Text>
              ))}
            </View>

            <Button
              title="Submit Document"
              onPress={onSubmit}
              loading={submitting}
              disabled={submitting}
              accessibilityLabel={`Submit ${activeDoc?.label}`}
            />
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  topBar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: layout.screenPadding,
    paddingVertical: spacing.sm,
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
  },
  backPlaceholder: { width: 44 },
  screenTitle: {
    ...typography.pageTitle,
    fontSize: 20,
    lineHeight: 24,
    color: colors.textPrimary,
    flex: 1,
    textAlign: 'center',
    marginHorizontal: spacing.sm,
  },
  scroll: {
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.xl,
  },
  pickerHint: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.md,
  },
  emptyHint: {
    ...typography.body,
    color: colors.textSecondary,
    lineHeight: 22,
  },
  pickerList: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 8,
    overflow: 'hidden',
  },
  pickerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.sm,
    backgroundColor: colors.card,
    gap: spacing.sm,
  },
  pickerRowBorder: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  pickerRowPressed: {
    backgroundColor: colors.background,
  },
  pickerLabel: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  pickerStatus: {
    ...typography.metadata,
    fontWeight: '600',
  },
  docHeading: {
    ...typography.cardTitle,
    color: colors.primaryNavy,
    marginBottom: spacing.md,
  },
  section: {
    marginBottom: spacing.md,
  },
  sectionTitle: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  listItem: {
    ...typography.metadata,
    color: colors.textPrimary,
    marginBottom: spacing.xs,
  },
  previewRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginBottom: spacing.sm,
  },
  preview: {
    width: 56,
    height: 56,
    borderRadius: 6,
    backgroundColor: colors.border,
  },
  previewName: {
    ...typography.metadata,
    color: colors.textPrimary,
    flex: 1,
  },
  actionRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.sm,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  actionRowPressed: {
    opacity: 0.88,
  },
  actionLabel: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  fieldError: {
    ...typography.metadata,
    color: colors.danger,
    marginVertical: spacing.sm,
  },
});
