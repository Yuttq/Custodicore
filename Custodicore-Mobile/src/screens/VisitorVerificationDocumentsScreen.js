import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Image, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Button,
  StackScreenHeader,
  colors,
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { EmptyState, LoadingSpinner } from '../components';
import MyPdlsList from '../components/MyPdlsList';
import RegistrationPhotoCamera from '../components/RegistrationPhotoCamera';
import VerificationProgressCard from '../components/VerificationProgressCard';
import useMyPdlActions from '../hooks/useMyPdlActions';
import useVisitorVerification from '../hooks/useVisitorVerification';
import { replaceRegistrationPhoto } from '../repositories/verificationRepository';
import { BASE_URL, getStoredToken } from '../services/api';
import { summarizeMyPdls } from '../utils/myPdls';
import { getDocumentStatusDisplay } from '../utils/verificationDocumentUi';
import {
  getAccountStatusMessage,
  getPhotoAction,
  groupVerificationDocuments,
} from '../utils/visitorVerificationDocuments';

/**
 * @param {object} props
 * @param {import('../repositories/verificationRepository').VerificationDocument} props.document
 * @param {boolean} props.isLast
 * @param {() => void} props.onPress
 */
function DocumentCompactRow({ document: doc, isLast, onPress }) {
  const status = getDocumentStatusDisplay(doc.uploadStatus);

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [
        styles.docRow,
        !isLast && styles.docRowBorder,
        pressed && styles.docRowPressed,
      ]}
      accessibilityRole="button"
      accessibilityLabel={`${doc.label}, ${status.label}.${
        doc.uploadStatus === 'rejected' && doc.rejectionReason ? ` Reason: ${doc.rejectionReason}.` : ''
      } Tap for details.`}
    >
      <View style={styles.pdlText}>
        <Text style={styles.docRowLabel} numberOfLines={2}>
          {doc.label}
        </Text>
        {doc.uploadStatus === 'rejected' && doc.rejectionReason ? (
          <Text style={styles.docRowReason} numberOfLines={3}>
            Reason: {doc.rejectionReason}
          </Text>
        ) : null}
        {doc.uploadStatus === 'rejected' && doc.canUpload ? (
          <Text style={styles.docRowAction}>Tap to replace</Text>
        ) : null}
      </View>
      <Text style={[styles.docStatusLabel, { color: status.color }]}>{status.label}</Text>
    </Pressable>
  );
}

/**
 * The visitor's own photo, retakeable only while staff have returned the
 * account for correction, or added when there is none yet (front
 * camera only, same component and checks as registration). Loaded from
 * GET /api/me/photo with the visitor's token.
 * @param {object} props
 * @param {boolean} props.hasPhoto
 * @param {string | null} props.photoUploadedAt — also busts the image cache after a retake
 * @param {() => void} props.onSaved
 */
function RegistrationPhotoSection({ hasPhoto, photoUploadedAt, onSaved }) {
  const [token, setToken] = useState(/** @type {string | null} */ (null));
  const [imageFailed, setImageFailed] = useState(false);
  const [cameraOpen, setCameraOpen] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    getStoredToken()
      .then((t) => {
        if (!cancelled) setToken(t);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    setImageFailed(false);
  }, [photoUploadedAt]);

  const savePhoto = useCallback(
    async (photo) => {
      setSaving(true);
      try {
        await replaceRegistrationPhoto(photo);
        Alert.alert(
          'Photo saved',
          'Your new photo was sent. Facility staff will review your account again. It stays locked while it is being reviewed.',
        );
        onSaved();
      } catch (e) {
        Alert.alert(
          'Could not save photo',
          e instanceof Error && e.message.trim() ? e.message : 'Please try again.',
        );
      } finally {
        setSaving(false);
      }
    },
    [onSaved],
  );

  const showImage = hasPhoto && token && BASE_URL && !imageFailed;

  return (
    <>
      <Text style={styles.sectionEyebrow}>Your Photo</Text>
      <View style={[styles.docList, styles.photoCard]}>
        {showImage ? (
          <Image
            source={{
              uri: `${BASE_URL}/me/photo?v=${encodeURIComponent(photoUploadedAt ?? '')}`,
              headers: { Authorization: `Bearer ${token}` },
            }}
            style={styles.photoPreview}
            resizeMode="cover"
            onError={() => setImageFailed(true)}
            accessibilityLabel="Your current photo"
          />
        ) : (
          <Text style={styles.requestText}>
            {hasPhoto ? 'Your photo could not be shown right now.' : 'No photo saved yet.'}
          </Text>
        )}
        <Text style={styles.requestText}>
          {hasPhoto
            ? 'Staff returned your account for correction. Retake your photo with the front camera if they asked for a new one.'
            : 'Take a photo of yourself with the front camera. Staff compare it with your government ID.'}
        </Text>
        <Button
          title={hasPhoto ? 'Retake Photo' : 'Take Photo'}
          variant="secondary"
          onPress={() => setCameraOpen(true)}
          loading={saving}
          disabled={saving}
          accessibilityLabel={hasPhoto ? 'Retake your photo' : 'Take your photo'}
        />
      </View>
      <RegistrationPhotoCamera
        visible={cameraOpen}
        onClose={() => setCameraOpen(false)}
        onConfirm={savePhoto}
      />
    </>
  );
}

/**
 * Visitor verification documents — real statuses from GET /api/documents (v2.1 / BJMP).
 */
export default function VisitorVerificationDocumentsScreen({ navigation }) {
  const { verification, loading, error, reload } = useVisitorVerification();
  const documents = useMemo(() => verification?.documents ?? [], [verification]);

  const openDocument = useCallback(
    (doc) => {
      navigation.navigate('VerificationDocumentDetail', { documentKey: doc.key });
    },
    [navigation],
  );

  const pdls = useMemo(() => verification?.pdlRelationships ?? [], [verification]);
  const pdlRequest = verification?.pdlRequest ?? null;
  const hasPdlInfo = pdls.length > 0 || Boolean(pdlRequest);
  const pdlSummary = useMemo(() => summarizeMyPdls(pdls, pdlRequest), [pdls, pdlRequest]);
  const sections = useMemo(() => groupVerificationDocuments(documents, pdls), [documents, pdls]);
  const initialDocuments = useMemo(() => {
    const initial = sections
      .filter((s) => s.kind !== 'additional_pdl')
      .flatMap((s) => s.documents);
    return initial.length ? initial : documents;
  }, [sections, documents]);
  const accountMessage = getAccountStatusMessage(verification?.backendStatus);
  const { cancelling, cancelRequest, requestAgain, requestAgainForRelationship } = useMyPdlActions({
    navigation,
    verification,
    reload,
  });

  // Approved visitors have Profile → My PDLs, so this screen links there.
  // Pending / returned visitors (review stack, no Profile tab) keep the list here.
  const hasMyPdlsScreen = Boolean(navigation.getState?.()?.routeNames?.includes('MyPdls'));
  const openMyPdls = useCallback(() => navigation.navigate('MyPdls'), [navigation]);

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Verification Documents" navigation={navigation} />

      <ScrollView
        contentContainerStyle={commonStyles.scrollContent}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        {loading && !verification ? (
          <LoadingSpinner message="Loading verification status…" compact />
        ) : error && !verification ? (
          <EmptyState
            title="Couldn't load documents"
            message={error}
            iconName="cloud-offline-outline"
            iconColor={colors.danger}
            emphasis="error"
            style={styles.documentsEmpty}
          >
            <Button title="Retry" onPress={reload} accessibilityLabel="Retry loading documents" />
          </EmptyState>
        ) : documents.length === 0 ? (
          <EmptyState
            title="No Documents Required"
            message="There are no verification documents to show right now."
            iconName="document-text-outline"
            iconColor={colors.primaryTeal}
            style={styles.documentsEmpty}
          />
        ) : (
          <>
            {verification.rejectionReason ? (
              <View style={styles.reasonBox}>
                <Text style={styles.reasonLabel}>Reason</Text>
                <Text style={styles.reasonText}>{verification.rejectionReason}</Text>
              </View>
            ) : null}

            {/* Progress of the account's own verification; additional PDLs
                have their own sections and status under My PDLs. */}
            <VerificationProgressCard
              documents={initialDocuments}
              overallStatus={verification.verificationStatus}
            />

            {accountMessage ? <Text style={styles.statusMessage}>{accountMessage}</Text> : null}

            {documents.every((doc) => doc.uploadStatus === 'pending') ? (
              <Text style={styles.noneSubmitted}>
                No documents submitted yet. Upload them below to begin verification.
              </Text>
            ) : null}

            {verification.awaitingRelationshipLink ? (
              <Text style={styles.noneSubmitted}>
                Facility staff review the relationship documents you sent with your registration
                after they link your account to the PDL you are visiting. You only need to upload
                a document again if staff reject it or ask for another one.
              </Text>
            ) : null}

            {hasPdlInfo && hasMyPdlsScreen ? (
              <Pressable
                onPress={openMyPdls}
                style={({ pressed }) => [
                  styles.docList,
                  styles.docRow,
                  styles.myPdlsLink,
                  pressed && styles.docRowPressed,
                ]}
                accessibilityRole="button"
                accessibilityLabel={`My PDLs${pdlSummary.full ? `, ${pdlSummary.full}` : ''}. Opens My PDLs.`}
              >
                <View style={styles.pdlText}>
                  <Text style={styles.docRowLabel}>My PDLs</Text>
                  {pdlSummary.full ? (
                    <Text style={styles.docRowMeta}>{pdlSummary.full}</Text>
                  ) : null}
                </View>
                <Ionicons name="chevron-forward" size={18} color={colors.textSecondary} />
              </Pressable>
            ) : hasPdlInfo ? (
              <MyPdlsList
                variant="inline"
                pdls={pdls}
                pdlRequest={pdlRequest}
                onCancelRequest={cancelRequest}
                cancelling={cancelling}
                onRequestAgain={requestAgain}
                onRequestAgainForPdl={requestAgainForRelationship}
              />
            ) : null}
            {/* Only while the backend allows it (returned account or no photo yet). */}
            {getPhotoAction(verification) ? (
              <RegistrationPhotoSection
                hasPhoto={verification.hasPhoto}
                photoUploadedAt={verification.photoUploadedAt}
                onSaved={reload}
              />
            ) : null}
            {/* Account verification, then the first PDL, then each additional PDL. */}
            {sections.map((section) => (
              <View key={section.key} style={styles.section}>
                <Text style={styles.sectionEyebrow}>{section.title}</Text>
                <View style={styles.docList}>
                  {section.documents.map((doc, index) => (
                    <DocumentCompactRow
                      key={doc.key}
                      document={doc}
                      isLast={index === section.documents.length - 1}
                      onPress={() => openDocument(doc)}
                    />
                  ))}
                </View>
              </View>
            ))}
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  // Same look as the reason on VerificationReviewScreen.
  reasonBox: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
    padding: spacing.md,
    marginBottom: spacing.md,
  },
  reasonLabel: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.xs,
  },
  reasonText: {
    ...typography.body,
    color: colors.textPrimary,
  },
  sectionEyebrow: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  noneSubmitted: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.md,
  },
  statusMessage: {
    ...typography.body,
    color: colors.textPrimary,
    marginBottom: spacing.md,
  },
  section: {
    marginBottom: spacing.lg,
  },
  documentsEmpty: {
    paddingVertical: spacing.md,
  },
  docList: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    overflow: 'hidden',
  },
  docRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    gap: spacing.sm,
    backgroundColor: colors.card,
    minHeight: 42,
  },
  docRowBorder: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  docRowPressed: {
    backgroundColor: colors.background,
  },
  docRowLabel: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  docStatusLabel: {
    ...typography.metadata,
    fontWeight: '600',
    flexShrink: 0,
  },
  docRowReason: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.xs,
  },
  docRowAction: {
    ...typography.statusLabel,
    color: colors.primaryTealDark,
    marginTop: 2,
  },
  photoCard: {
    padding: spacing.md,
    gap: spacing.sm,
    marginBottom: spacing.lg,
    alignItems: 'stretch',
  },
  photoPreview: {
    width: 120,
    height: 150,
    borderRadius: layout.cardRadius,
    alignSelf: 'center',
    backgroundColor: colors.background,
  },
  // One compact row linking to Profile → My PDLs (approved visitors).
  myPdlsLink: {
    marginBottom: spacing.lg,
  },
  docRowMeta: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  requestText: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  pdlText: {
    flex: 1,
  },
});
