import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Button, colors, layout, spacing, typography } from '../designSystem';
import { VERIFICATION_STATUS_COLORS } from '../utils/verificationDocumentUi';

/** My PDLs status → the same colours the document statuses use. */
const PDL_STATUS_COLORS = {
  approved: VERIFICATION_STATUS_COLORS.verified,
  pending: VERIFICATION_STATUS_COLORS.underReview,
  rejected: VERIFICATION_STATUS_COLORS.rejected,
};

/**
 * "My PDLs": the visitor's "Add Another PDL" request (Pending Review or Not
 * Accepted) and every PDL relationship on the account with its status.
 * Shown on Profile → My PDLs (`variant="screen"`, with Request / Linked PDLs
 * labels) and, for visitors without the Profile tab (pending or returned
 * accounts), inline in Verification Documents (`variant="inline"`, one
 * "My PDLs" label). New requests start only from the (+) on Home → Visitation
 * Calendar.
 * @param {object} props
 * @param {'screen' | 'inline'} [props.variant]
 * @param {ReturnType<typeof import('../utils/relationshipRequirementDocuments').buildPdlRelationshipList>} props.pdls
 * @param {import('../utils/pdlLinkRequest').PdlRequestStatus | null} props.pdlRequest
 * @param {() => void} props.onCancelRequest
 * @param {boolean} props.cancelling
 * @param {(() => void) | null} props.onRequestAgain — rejected request, when a new one is allowed
 * @param {Set<string>} [props.docsNeeded] — relationship ids with a document to upload or replace
 * @param {(() => void) | null} [props.onOpenDocuments]
 * @param {((pdl: any) => void) | null} [props.onRequestAgainForPdl] — rejected relationship, when a new request is allowed
 */
export default function MyPdlsList({
  variant = 'screen',
  pdls,
  pdlRequest,
  onCancelRequest,
  cancelling,
  onRequestAgain,
  docsNeeded,
  onOpenDocuments = null,
  onRequestAgainForPdl = null,
}) {
  const onScreen = variant === 'screen';

  return (
    <>
      {!onScreen ? <Text style={styles.eyebrow}>My PDLs</Text> : null}
      {pdlRequest ? (
        <>
          {onScreen ? <Text style={styles.eyebrow}>Request</Text> : null}
          <PdlRequestCard
            request={pdlRequest}
            onCancel={onCancelRequest}
            cancelling={cancelling}
            onRequestAgain={onRequestAgain}
          />
        </>
      ) : null}
      {pdls.length > 0 ? (
        <>
          {onScreen ? <Text style={styles.eyebrow}>Linked PDLs</Text> : null}
          <View style={[styles.list, styles.block]}>
            {pdls.map((pdl, index) => {
              const needsDocs =
                pdl.status === 'pending' && Boolean(onOpenDocuments) && Boolean(docsNeeded?.has(pdl.id));
              // Only while a new request is allowed (approved account, none
              // open): the handler is null otherwise (useMyPdlActions).
              const canRequestAgain = pdl.status === 'rejected' && Boolean(onRequestAgainForPdl);
              return (
                <View
                  key={pdl.id}
                  style={[styles.row, index < pdls.length - 1 && styles.rowBorder]}
                >
                  <View
                    style={styles.rowMain}
                    accessible
                    accessibilityLabel={`${pdl.pdlName}${
                      pdl.relationshipLabel ? `, ${pdl.relationshipLabel}` : ''
                    }, ${pdl.statusLabel}`}
                  >
                    <View style={styles.rowText}>
                      <Text style={styles.rowLabel} numberOfLines={2}>
                        {pdl.pdlName}
                      </Text>
                      {pdl.relationshipLabel ? (
                        <Text style={styles.meta}>{pdl.relationshipLabel}</Text>
                      ) : null}
                    </View>
                    <Text style={[styles.status, { color: PDL_STATUS_COLORS[pdl.status] }]}>
                      {pdl.statusLabel}
                    </Text>
                  </View>
                  {pdl.statusHint && !needsDocs ? (
                    <Text style={[styles.meta, styles.hint]}>{pdl.statusHint}</Text>
                  ) : null}
                  {canRequestAgain ? (
                    <View style={styles.rowButton}>
                      <Button
                        title="Request Again"
                        variant="secondary"
                        onPress={() => onRequestAgainForPdl(pdl)}
                        accessibilityLabel={`Send a new request to add ${pdl.pdlName} with corrected documents`}
                      />
                    </View>
                  ) : null}
                  {needsDocs ? (
                    <Pressable
                      onPress={onOpenDocuments}
                      hitSlop={8}
                      style={({ pressed }) => [styles.link, pressed && styles.pressed]}
                      accessibilityRole="button"
                      accessibilityLabel={`Upload the documents needed for ${pdl.pdlName}`}
                    >
                      <Text style={styles.linkText}>Documents needed</Text>
                      <Ionicons name="chevron-forward" size={14} color={colors.primaryTeal} />
                    </Pressable>
                  ) : null}
                </View>
              );
            })}
          </View>
        </>
      ) : null}
    </>
  );
}

/**
 * The visitor's "Add Another PDL" request — open (Pending Review, can be
 * cancelled) or rejected (Not Accepted, with the staff reason and Request
 * Again when a new request is allowed).
 * @param {object} props
 * @param {import('../utils/pdlLinkRequest').PdlRequestStatus} props.request
 * @param {() => void} props.onCancel
 * @param {boolean} props.cancelling
 * @param {(() => void) | null} props.onRequestAgain
 */
function PdlRequestCard({ request, onCancel, cancelling, onRequestAgain }) {
  const open = request.status === 'open';
  const color = open ? VERIFICATION_STATUS_COLORS.underReview : VERIFICATION_STATUS_COLORS.rejected;

  return (
    <View style={[styles.list, styles.block, styles.requestCard]}>
      <View style={styles.rowMain}>
        <View style={styles.rowText}>
          <Text style={styles.rowLabel} numberOfLines={2}>
            {request.pdlName}
          </Text>
          <Text style={styles.meta}>{request.relationshipLabel} · requested PDL</Text>
        </View>
        <Text style={[styles.status, { color }]}>{request.statusLabel}</Text>
      </View>
      {open ? (
        <>
          <Text style={styles.meta}>
            Your request is waiting for review. Staff will look for this PDL in their records and
            notify you when they review it.
          </Text>
          {request.documents.length > 0 ? (
            <Text style={styles.meta}>
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
          {/* Same small reason notice as the Phase 4 correction screens. */}
          {request.rejectionReason ? (
            <View style={styles.reasonNotice} accessibilityRole="text">
              <Ionicons name="alert-circle-outline" size={16} color={colors.danger} />
              <Text style={styles.reasonNoticeText}>
                <Text style={styles.reasonNoticeLabel}>Reason: </Text>
                {request.rejectionReason}
              </Text>
            </View>
          ) : (
            <Text style={styles.meta}>Facility staff did not accept this request.</Text>
          )}
          <Text style={styles.meta}>Please submit a new request with corrected documents.</Text>
          {onRequestAgain ? (
            <Button
              title="Request Again"
              variant="secondary"
              onPress={onRequestAgain}
              accessibilityLabel={`Correct and send the request to add ${request.pdlName} again`}
            />
          ) : null}
        </>
      )}
    </View>
  );
}

// Same list look as the document rows in Verification Documents.
const styles = StyleSheet.create({
  eyebrow: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  list: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    overflow: 'hidden',
  },
  block: {
    marginBottom: spacing.lg,
  },
  row: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    backgroundColor: colors.card,
    minHeight: 42,
  },
  rowBorder: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  rowMain: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  rowText: {
    flex: 1,
  },
  rowLabel: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  status: {
    ...typography.metadata,
    fontWeight: '600',
    flexShrink: 0,
  },
  meta: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  link: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 2,
    marginTop: spacing.xs,
    paddingVertical: 2,
  },
  linkText: {
    ...typography.metadata,
    fontWeight: '700',
    color: colors.primaryTeal,
  },
  pressed: {
    opacity: 0.7,
  },
  hint: {
    marginTop: spacing.xs,
  },
  rowButton: {
    marginTop: spacing.sm,
  },
  requestCard: {
    padding: spacing.md,
    gap: spacing.sm,
  },
  // Small inline notice: the staff reason (same as AddPdlRequest / UploadID).
  reasonNotice: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.xs,
    padding: spacing.sm,
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
});
