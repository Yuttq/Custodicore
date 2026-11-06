import React, { useCallback, useEffect, useState } from 'react';
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
import RegistrationPhotoCamera from '../components/RegistrationPhotoCamera';
import VerificationProgressCard from '../components/VerificationProgressCard';
import useVisitorVerification from '../hooks/useVisitorVerification';
import { cancelPdlRequest, replaceRegistrationPhoto } from '../repositories/verificationRepository';
import { BASE_URL, getStoredToken } from '../services/api';
import { canAddAnotherPdl } from '../utils/pdlLinkRequest';
import {
  VERIFICATION_STATUS_COLORS,
  getDocumentStatusDisplay,
} from '../utils/verificationDocumentUi';

/** My PDLs status → the same colours the document statuses use. */
const PDL_STATUS_COLORS = {
  approved: VERIFICATION_STATUS_COLORS.verified,
  pending: VERIFICATION_STATUS_COLORS.underReview,
  rejected: VERIFICATION_STATUS_COLORS.rejected,
};

/**
 * "My PDLs": each PDL relationship on the account and its status (staff
 * link, approve or reject PDLs), plus the visitor's own "Add Another PDL"
 * request and the button to send one.
 * @param {object} props
 * @param {ReturnType<typeof import('../utils/relationshipRequirementDocuments').buildPdlRelationshipList>} props.pdls
 * @param {import('../utils/pdlLinkRequest').PdlRequestStatus | null} props.pdlRequest — open or rejected request, else null
 * @param {boolean} props.canAdd — show "Add Another PDL"
 * @param {() => void} props.onAdd
 * @param {() => void} props.onCancelRequest
 * @param {boolean} props.cancelling
 */
function MyPdlsSection({ pdls, pdlRequest, canAdd, onAdd, onCancelRequest, cancelling }) {
  return (
    <>
      <Text style={styles.sectionEyebrow}>My PDLs</Text>
      {pdlRequest ? (
        <PdlRequestCard request={pdlRequest} onCancel={onCancelRequest} cancelling={cancelling} />
      ) : null}
      {pdls.length > 0 ? (
        <View style={[styles.docList, styles.pdlList]}>
          {pdls.map((pdl, index) => (
            <View
              key={pdl.id}
              style={[styles.docRow, index < pdls.length - 1 && styles.docRowBorder]}
              accessible
              accessibilityLabel={`${pdl.pdlName}${pdl.relationshipLabel ? `, ${pdl.relationshipLabel}` : ''}, ${pdl.statusLabel}`}
            >
              <View style={styles.pdlText}>
                <Text style={styles.docRowLabel} numberOfLines={2}>
                  {pdl.pdlName}
                </Text>
                {pdl.relationshipLabel ? (
                  <Text style={styles.pdlMeta}>{pdl.relationshipLabel}</Text>
                ) : null}
              </View>
              <Text style={[styles.docStatusLabel, { color: PDL_STATUS_COLORS[pdl.status] }]}>
                {pdl.statusLabel}
              </Text>
            </View>
          ))}
        </View>
      ) : null}
      {canAdd ? (
        <View style={styles.addPdl}>
          <Button
            title="Add Another PDL"
            variant="secondary"
            onPress={onAdd}
            accessibilityLabel="Ask facility staff to add another PDL to your account"
          />
        </View>
      ) : null}
    </>
  );
}

/**
 * Phase 5B: the visitor's "Add Another PDL" request — open (Pending Review,
 * can be cancelled) or rejected (Not Accepted, with the staff reason).
 * @param {object} props
 * @param {import('../utils/pdlLinkRequest').PdlRequestStatus} props.request
 * @param {() => void} props.onCancel
 * @param {boolean} props.cancelling
 */
function PdlRequestCard({ request, onCancel, cancelling }) {
  const open = request.status === 'open';
  const color = open ? VERIFICATION_STATUS_COLORS.underReview : VERIFICATION_STATUS_COLORS.rejected;

  return (
    <View style={[styles.docList, styles.pdlList, styles.requestCard]}>
      <View style={styles.requestHead}>
        <View style={styles.pdlText}>
          <Text style={styles.docRowLabel} numberOfLines={2}>
            {request.pdlName}
          </Text>
          <Text style={styles.pdlMeta}>{request.relationshipLabel} · requested PDL</Text>
        </View>
        <Text style={[styles.docStatusLabel, { color }]}>{request.statusLabel}</Text>
      </View>
      {open ? (
        <>
          <Text style={styles.requestText}>
            Facility staff must find this PDL in their records before the relationship can be added to
            your account. You will be notified when they review it.
          </Text>
          {request.documents.length > 0 ? (
            <Text style={styles.requestText}>
              Documents sent: {request.documents.map((d) => d.label).join(', ')}
            </Text>
          ) : null}
          <Button
            title="Cancel Request"
            variant="destructive"
            onPress={onCancel}
            loading={cancelling}
            disabled={cancelling}
            accessibilityLabel={`Cancel the request to add ${request.pdlName}`}
          />
        </>
      ) : (
        <>
          {request.rejectionReason ? (
            <View style={styles.requestReason}>
              <Text style={styles.reasonLabel}>Reason</Text>
              <Text style={styles.reasonText}>{request.rejectionReason}</Text>
            </View>
          ) : null}
          <Text style={styles.requestText}>
            Facility staff did not accept this request. You can send a new one with the correct details.
          </Text>
        </>
      )}
    </View>
  );
}

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
      </View>
      <Text style={[styles.docStatusLabel, { color: status.color }]}>{status.label}</Text>
    </Pressable>
  );
}

/**
 * The visitor's own photo, retakeable until the account is approved (front
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
          'Your new photo was sent. Facility staff will review your account again.',
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
          Facility staff compare this photo with your government ID. Retake it if staff asked you
          to, using the front camera.
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
  const documents = verification?.documents ?? [];

  const [cancelling, setCancelling] = useState(false);

  const openDocument = useCallback(
    (doc) => {
      navigation.navigate('VerificationDocumentDetail', { documentKey: doc.key });
    },
    [navigation],
  );

  const pdlRequest = verification?.pdlRequest ?? null;
  // The add screen is only registered for approved visitors (AppNavigator).
  const canAdd =
    canAddAnotherPdl(verification?.backendStatus, pdlRequest) &&
    Boolean(navigation.getState?.()?.routeNames?.includes('AddPdlRequest'));

  const openAddPdl = useCallback(() => navigation.navigate('AddPdlRequest'), [navigation]);

  // Cancelling works whatever the account status (the backend allows it).
  const cancelRequest = useCallback(() => {
    if (!pdlRequest || cancelling) return;
    Alert.alert(
      'Cancel request?',
      `Your request to add ${pdlRequest.pdlName} and the documents you sent with it will be removed.`,
      [
        { text: 'Keep Request', style: 'cancel' },
        {
          text: 'Cancel Request',
          style: 'destructive',
          onPress: async () => {
            setCancelling(true);
            try {
              await cancelPdlRequest(pdlRequest.id);
            } catch (e) {
              // e.g. 409: staff already linked or reviewed it — the refresh shows the outcome.
              Alert.alert(
                'Could not cancel',
                e instanceof Error && e.message ? e.message : 'Please try again.',
              );
            } finally {
              setCancelling(false);
              reload();
            }
          },
        },
      ],
    );
  }, [pdlRequest, cancelling, reload]);

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

            <VerificationProgressCard
              documents={documents}
              overallStatus={verification.verificationStatus}
            />

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

            {verification.pdlRelationships?.length || pdlRequest || canAdd ? (
              <MyPdlsSection
                pdls={verification.pdlRelationships ?? []}
                pdlRequest={pdlRequest}
                canAdd={canAdd}
                onAdd={openAddPdl}
                onCancelRequest={cancelRequest}
                cancelling={cancelling}
              />
            ) : null}
            {verification.canReplacePhoto ? (
              <RegistrationPhotoSection
                hasPhoto={verification.hasPhoto}
                photoUploadedAt={verification.photoUploadedAt}
                onSaved={reload}
              />
            ) : null}
            <Text style={styles.sectionEyebrow}>Required Documents</Text>
            <View style={styles.docList}>
              {documents.map((doc, index) => (
                <DocumentCompactRow
                  key={doc.key}
                  document={doc}
                  isLast={index === documents.length - 1}
                  onPress={() => openDocument(doc)}
                />
              ))}
            </View>
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
  pdlList: {
    marginBottom: spacing.lg,
  },
  addPdl: {
    marginTop: -spacing.sm,
    marginBottom: spacing.lg,
  },
  requestCard: {
    padding: spacing.md,
    gap: spacing.sm,
  },
  requestHead: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  requestText: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  requestReason: {
    borderRadius: layout.cardRadius,
    backgroundColor: colors.background,
    padding: spacing.sm,
  },
  pdlText: {
    flex: 1,
  },
  pdlMeta: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
});
