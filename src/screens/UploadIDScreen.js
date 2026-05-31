import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useMemo, useState } from 'react';
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

function UploadActionRow({ icon, label, onPress, accessibilityLabel, isLast }) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [
        styles.actionRow,
        !isLast && styles.actionRowBorder,
        pressed && styles.actionRowPressed,
      ]}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
    >
      <Ionicons name={icon} size={20} color={colors.primaryTeal} />
      <Text style={styles.actionLabel}>{label}</Text>
      <Ionicons name="chevron-forward" size={16} color={colors.textSecondary} />
    </Pressable>
  );
}

/**
 * @param {object} props
 * @param {{ key: string; label: string }} props.doc
 * @param {boolean} props.isLast
 * @param {() => void} props.onPress
 */
function DocumentPickerRow({ doc, isLast, onPress }) {
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
      <Ionicons name="chevron-forward" size={18} color={colors.textSecondary} />
    </Pressable>
  );
}

/**
 * Focused document upload — one document at a time with tips (v2.1 / BJMP).
 * Replaces the former multi-document "Visitor Verification" hub screen.
 */
export default function UploadIDScreen({ navigation, route }) {
  const { registrationSummary } = useAuth();
  const relationshipId =
    route.params?.relationshipId ?? registrationSummary?.relationship ?? 'spouse';
  const initialDocumentKey = route.params?.documentKey ?? null;

  const requiredDocs = useMemo(
    () => getRequiredVerificationDocuments(relationshipId),
    [relationshipId],
  );

  const [activeDocKey, setActiveDocKey] = useState(initialDocumentKey);
  const [entry, setEntry] = useState({ uri: null, status: 'pending', fileName: null });
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const activeDoc = useMemo(
    () => requiredDocs.find((doc) => doc.key === activeDocKey) ?? null,
    [requiredDocs, activeDocKey],
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
            <Text style={styles.pickerHint}>Select a document to upload or replace.</Text>
            <View style={styles.pickerList}>
              {requiredDocs.map((doc, index) => (
                <DocumentPickerRow
                  key={doc.key}
                  doc={doc}
                  isLast={index === requiredDocs.length - 1}
                  onPress={() => {
                    setActiveDocKey(doc.key);
                    setEntry({ uri: null, status: 'pending', fileName: null });
                    setError('');
                  }}
                />
              ))}
            </View>
          </>
        ) : (
          <>
            <Text style={styles.docHeading}>{activeDoc?.label}</Text>

            {activeDocKey === GOVERNMENT_ID_KEY ? (
              <View style={styles.acceptedBlock}>
                <Text style={styles.acceptedTitle}>Accepted IDs</Text>
                {ACCEPTED_GOVERNMENT_IDS.map((id) => (
                  <Text key={id} style={styles.acceptedItem}>
                    · {id}
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

            <View style={styles.actionsBlock}>
              <UploadActionRow
                icon="camera-outline"
                label="Take Photo"
                onPress={() => pickPhoto(true)}
                accessibilityLabel={`Take photo for ${activeDoc?.label}`}
                isLast={false}
              />
              <UploadActionRow
                icon="images-outline"
                label="Choose From Gallery"
                onPress={() => pickPhoto(false)}
                accessibilityLabel={`Choose ${activeDoc?.label} from gallery`}
                isLast
              />
            </View>

            {error ? (
              <Text style={styles.fieldError} accessibilityRole="alert">
                {error}
              </Text>
            ) : null}

            <View style={styles.tipsBlock}>
              <Text style={styles.tipsTitle}>Verification Tips</Text>
              {VERIFICATION_TIPS.map((tip) => (
                <Text key={tip} style={styles.tipItem}>
                  · {tip}
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
    paddingHorizontal: spacing[20],
    paddingVertical: spacing[8],
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
    ...typography.sectionTitle,
    color: colors.textPrimary,
    flex: 1,
    textAlign: 'center',
    marginHorizontal: spacing[8],
  },
  scroll: {
    paddingHorizontal: spacing[20],
    paddingBottom: spacing[28],
  },
  pickerHint: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[10],
  },
  pickerList: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    overflow: 'hidden',
  },
  pickerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing[14],
    paddingVertical: spacing[12],
    backgroundColor: colors.card,
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
  docHeading: {
    ...typography.sectionTitle,
    color: colors.primaryNavy,
    marginBottom: spacing[12],
  },
  acceptedBlock: {
    marginBottom: spacing[12],
    paddingVertical: spacing[8],
  },
  acceptedTitle: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textSecondary,
    marginBottom: spacing[6],
  },
  acceptedItem: {
    ...typography.caption,
    color: colors.textPrimary,
    lineHeight: 20,
  },
  previewRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[10],
    marginBottom: spacing[12],
    padding: spacing[8],
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
  },
  preview: {
    width: 64,
    height: 64,
    borderRadius: 6,
    backgroundColor: colors.border,
  },
  previewName: {
    ...typography.caption,
    color: colors.textPrimary,
    flex: 1,
  },
  actionsBlock: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    overflow: 'hidden',
    marginBottom: spacing[12],
  },
  actionRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[10],
    paddingHorizontal: spacing[14],
    paddingVertical: spacing[12],
    backgroundColor: colors.card,
  },
  actionRowBorder: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  actionRowPressed: {
    backgroundColor: colors.background,
  },
  actionLabel: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  fieldError: {
    ...typography.caption,
    color: colors.danger,
    marginBottom: spacing[10],
  },
  tipsBlock: {
    marginBottom: spacing[14],
    paddingVertical: spacing[4],
  },
  tipsTitle: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textSecondary,
    marginBottom: spacing[6],
  },
  tipItem: {
    ...typography.caption,
    color: colors.textSecondary,
    lineHeight: 18,
    marginBottom: spacing[2],
  },
});
