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
import {
  Button,
  Card,
  StatusChip,
  colors,
  layout,
  spacing,
  typography,
} from '../designSystem';
import Header from '../components/Header';
import { useAuth } from '../hooks/useAuth';
import { getRelationshipLabel } from '../utils/registrationRequirements';
import {
  ACCEPTED_GOVERNMENT_IDS,
  GOVERNMENT_ID_KEY,
  countUploadedVerificationDocuments,
  getRelationshipSectionSubtitle,
  getRelationshipVerificationDocs,
  getTotalVerificationDocumentCount,
  isDocumentUploaded,
} from '../utils/visitorVerificationDocuments';

const VERIFICATION_TIPS = [
  'Use clear photos',
  'Avoid glare',
  'Ensure all text is readable',
  'Upload complete documents',
  'Submit valid government-issued IDs',
];

function buildInitialDocuments(relationshipId) {
  const docs = {
    [GOVERNMENT_ID_KEY]: { uri: null, status: 'pending' },
  };
  getRelationshipVerificationDocs(relationshipId).forEach((doc) => {
    docs[doc.key] = { uri: null, status: 'pending' };
  });
  return docs;
}

function getDocumentStatusKey(entry) {
  if (!entry?.uri) return 'document_pending';
  if (entry.status === 'under_review') return 'document_under_review';
  if (entry.status === 'verified') return 'document_verified';
  return 'document_uploaded';
}

function UploadActionCard({ icon, label, onPress, accessibilityLabel }) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.actionCard, pressed && styles.actionCardPressed]}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
    >
      <View style={styles.actionIconCircle}>
        <Ionicons name={icon} size={22} color={colors.primaryTeal} />
      </View>
      <Text style={styles.actionLabel}>{label}</Text>
    </Pressable>
  );
}

function DocumentVerificationCard({
  title,
  docKey,
  entry,
  onTakePhoto,
  onChooseGallery,
  error,
}) {
  const statusKey = getDocumentStatusKey(entry);

  return (
    <Card style={styles.docCard}>
      <View style={styles.docCardHeader}>
        <Text style={styles.docCardTitle}>{title}</Text>
        <StatusChip status={statusKey} />
      </View>

      {entry?.uri ? (
        <View style={styles.thumbnailRow}>
          <Image
            source={{ uri: entry.uri }}
            style={styles.thumbnail}
            resizeMode="cover"
            accessibilityLabel={`Preview of ${title}`}
          />
          <View style={styles.thumbnailMeta}>
            <Ionicons name="checkmark-circle" size={18} color={colors.success} />
            <Text style={styles.thumbnailText} numberOfLines={2}>
              {entry.fileName ?? 'Photo attached'}
            </Text>
          </View>
        </View>
      ) : null}

      <View style={styles.actionRow}>
        <UploadActionCard
          icon="camera-outline"
          label="Take Photo"
          onPress={() => onTakePhoto(docKey)}
          accessibilityLabel={`Take photo for ${title}`}
        />
        <UploadActionCard
          icon="images-outline"
          label="Choose From Gallery"
          onPress={() => onChooseGallery(docKey)}
          accessibilityLabel={`Choose ${title} from gallery`}
        />
      </View>

      {error ? (
        <Text style={styles.fieldError} accessibilityRole="alert">
          {error}
        </Text>
      ) : null}
    </Card>
  );
}

/**
 * Visitor verification documents — government ID + relationship-based uploads (v2.1).
 * Mock submit only; preserves image picker flow from prior Upload ID screen.
 */
export default function UploadIDScreen({ navigation, route }) {
  const { registrationSummary } = useAuth();
  const relationshipId =
    route.params?.relationshipId ??
    registrationSummary?.relationship ??
    'spouse';

  const relationshipDocs = useMemo(
    () => getRelationshipVerificationDocs(relationshipId),
    [relationshipId],
  );
  const totalRequired = useMemo(
    () => getTotalVerificationDocumentCount(relationshipId),
    [relationshipId],
  );

  const [documents, setDocuments] = useState(() => buildInitialDocuments(relationshipId));
  const [errors, setErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);

  const uploadedCount = useMemo(
    () => countUploadedVerificationDocuments(documents, relationshipId),
    [documents, relationshipId],
  );

  const progressRatio = totalRequired > 0 ? uploadedCount / totalRequired : 0;

  const pickForDocument = useCallback(async (docKey, useCamera) => {
    const options = { mediaType: 'photo', saveToPhotos: false };
    const result = useCamera
      ? await launchCamera(options)
      : await launchImageLibrary(options);
    const asset = result.assets?.[0];
    if (asset?.uri) {
      setDocuments((prev) => ({
        ...prev,
        [docKey]: {
          ...prev[docKey],
          uri: asset.uri,
          fileName: asset.fileName ?? 'Photo attached',
          status: 'uploaded',
        },
      }));
      setErrors((e) => {
        const next = { ...e };
        delete next[docKey];
        return next;
      });
    } else if (result.errorMessage) {
      Alert.alert('Could not open image', result.errorMessage);
    }
  }, []);

  const validate = useCallback(() => {
    const next = {};
    if (!isDocumentUploaded(documents[GOVERNMENT_ID_KEY])) {
      next[GOVERNMENT_ID_KEY] = 'Upload your government-issued ID';
    }
    relationshipDocs.forEach((doc) => {
      if (!isDocumentUploaded(documents[doc.key])) {
        next[doc.key] = `Upload ${doc.label}`;
      }
    });
    setErrors(next);
    return Object.keys(next).length === 0;
  }, [documents, relationshipDocs]);

  const onSubmit = useCallback(async () => {
    if (submitting) return;
    if (!validate()) return;

    setSubmitting(true);
    try {
      await new Promise((r) => setTimeout(r, 800));
      setDocuments((prev) => {
        const next = { ...prev };
        Object.keys(next).forEach((key) => {
          if (next[key]?.uri) {
            next[key] = { ...next[key], status: 'under_review' };
          }
        });
        return next;
      });
      Alert.alert(
        'Documents submitted',
        'Your verification documents were received. Review may take up to one business day.',
        [{ text: 'OK', onPress: () => navigation.goBack() }],
      );
    } catch (e) {
      const msg =
        typeof e?.message === 'string' && e.message.trim()
          ? e.message
          : 'We could not submit your documents. Check your connection and try again.';
      Alert.alert('Submission failed', msg);
    } finally {
      setSubmitting(false);
    }
  }, [submitting, validate, navigation]);

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <Header
        title="Visitor Verification"
        showBackButton
        onBackPress={() => navigation.goBack()}
      />
      <ScrollView
        contentContainerStyle={styles.scroll}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        <Text style={styles.subtitle}>
          Complete the required documents for visitor verification.
        </Text>

        <Card style={styles.progressCard}>
          <Text style={styles.progressTitle}>Verification Progress</Text>
          <Text style={styles.progressMeta}>
            {uploadedCount} of {totalRequired} document{totalRequired === 1 ? '' : 's'} uploaded
          </Text>
          <View style={styles.progressTrack}>
            <View
              style={[styles.progressFill, { width: `${Math.round(progressRatio * 100)}%` }]}
            />
          </View>
        </Card>

        <Text style={styles.sectionHeading}>Government-Issued ID</Text>
        <Card style={styles.infoCard}>
          <Text style={styles.infoTitle}>Accepted IDs</Text>
          {ACCEPTED_GOVERNMENT_IDS.map((id) => (
            <View key={id} style={styles.infoRow}>
              <Ionicons name="checkmark-circle" size={16} color={colors.success} />
              <Text style={styles.infoItem}>{id}</Text>
            </View>
          ))}
        </Card>

        <DocumentVerificationCard
          title="Government ID"
          docKey={GOVERNMENT_ID_KEY}
          entry={documents[GOVERNMENT_ID_KEY]}
          onTakePhoto={(key) => pickForDocument(key, true)}
          onChooseGallery={(key) => pickForDocument(key, false)}
          error={errors[GOVERNMENT_ID_KEY]}
        />

        <Text style={styles.sectionHeading}>Relationship Documents</Text>
        <Text style={styles.sectionSub}>
          {getRelationshipSectionSubtitle(relationshipId)}
        </Text>
        {relationshipDocs.length === 0 ? (
          <Card style={styles.infoCard}>
            <Text style={styles.infoBody}>
              As a {getRelationshipLabel(relationshipId)}, no additional relationship documents
              are required beyond your government-issued ID.
            </Text>
          </Card>
        ) : (
          relationshipDocs.map((doc) => (
            <DocumentVerificationCard
              key={doc.key}
              title={doc.label}
              docKey={doc.key}
              entry={documents[doc.key]}
              onTakePhoto={(key) => pickForDocument(key, true)}
              onChooseGallery={(key) => pickForDocument(key, false)}
              error={errors[doc.key]}
            />
          ))
        )}

        <Card style={styles.tipsCard}>
          <Text style={styles.tipsTitle}>Verification Tips</Text>
          {VERIFICATION_TIPS.map((tip) => (
            <View key={tip} style={styles.tipRow}>
              <Ionicons name="checkmark" size={16} color={colors.success} />
              <Text style={styles.tipText}>{tip}</Text>
            </View>
          ))}
        </Card>

        <Button
          title="Submit Verification Documents"
          onPress={onSubmit}
          loading={submitting}
          disabled={submitting}
          accessibilityLabel="Submit verification documents"
        />
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  scroll: {
    paddingHorizontal: spacing[20],
    paddingBottom: spacing[24],
  },
  subtitle: {
    ...typography.body,
    color: colors.textSecondary,
    marginBottom: spacing[16],
    lineHeight: 22,
  },
  progressCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.sectionGap,
  },
  progressTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing[4],
  },
  progressMeta: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[12],
  },
  progressTrack: {
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.border,
    overflow: 'hidden',
  },
  progressFill: {
    height: '100%',
    backgroundColor: colors.primaryTeal,
    borderRadius: 4,
  },
  sectionHeading: {
    ...typography.sectionTitle,
    color: colors.primaryNavy,
    marginBottom: spacing[8],
  },
  sectionSub: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[12],
    lineHeight: 18,
  },
  infoCard: {
    borderRadius: layout.cardRadius,
    marginBottom: spacing[12],
  },
  infoTitle: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing[12],
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  infoRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[8],
    marginBottom: spacing[8],
  },
  infoItem: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
  },
  infoBody: {
    ...typography.body,
    color: colors.textSecondary,
    lineHeight: 22,
  },
  docCard: {
    borderRadius: layout.cardRadius,
    marginBottom: spacing[12],
  },
  docCardHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing[12],
    gap: spacing[8],
  },
  docCardTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    flex: 1,
  },
  thumbnailRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[12],
    marginBottom: spacing[12],
    padding: spacing[8],
    backgroundColor: colors.background,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.border,
  },
  thumbnail: {
    width: 72,
    height: 72,
    borderRadius: 8,
    backgroundColor: colors.border,
  },
  thumbnailMeta: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[8],
  },
  thumbnailText: {
    ...typography.caption,
    color: colors.textPrimary,
    flex: 1,
  },
  actionRow: {
    flexDirection: 'row',
    gap: spacing[12],
  },
  actionCard: {
    flex: 1,
    alignItems: 'center',
    paddingVertical: spacing[12],
    paddingHorizontal: spacing[8],
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
  },
  actionCardPressed: {
    opacity: 0.92,
    backgroundColor: 'rgba(13, 165, 138, 0.06)',
  },
  actionIconCircle: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing[8],
  },
  actionLabel: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.primaryNavy,
    textAlign: 'center',
  },
  fieldError: {
    ...typography.caption,
    color: colors.danger,
    marginTop: spacing[8],
  },
  tipsCard: {
    borderRadius: layout.cardRadius,
    marginTop: spacing[4],
    marginBottom: spacing[16],
    backgroundColor: 'rgba(22, 163, 74, 0.08)',
    borderWidth: 1,
    borderColor: 'rgba(22, 163, 74, 0.22)',
  },
  tipsTitle: {
    ...typography.cardTitle,
    color: colors.primaryNavy,
    marginBottom: spacing[12],
  },
  tipRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing[8],
    marginBottom: spacing[8],
  },
  tipText: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
    lineHeight: 20,
  },
});
