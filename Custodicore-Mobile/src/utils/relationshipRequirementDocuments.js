/**
 * Relationship documents for the Verification Documents screens, built from
 * the per-relationship `requirements` checklist in GET /api/documents
 * (App\Services\RelationshipRequirements on the backend). The backend decides
 * which documents a relationship needs; nothing here guesses or hard-codes
 * that list. Pure — no React Native imports — so it runs under `node --test`.
 *
 * Only `kind: 'document'` items are visitor uploads. The government ID has
 * its own entry, and officer confirmations / details are staff-side.
 */

const KEY_PREFIX = 'relationship';

/**
 * Stable document key for navigation params: one entry per relationship and
 * requirement, so two linked PDLs never share a key.
 * @param {string | number} relationshipId
 * @param {string} requirementKey
 */
export function toRelationshipDocumentKey(relationshipId, requirementKey) {
  return `${KEY_PREFIX}:${relationshipId}:${requirementKey}`;
}

/**
 * Checklist status → app upload status.
 * @param {string | null | undefined} status — met | pending_review | rejected | missing | blocked
 * @returns {'pending' | 'under_review' | 'verified' | 'rejected'}
 */
export function toRequirementUploadStatus(status) {
  switch (status) {
    case 'met':
      return 'verified';
    case 'pending_review':
      return 'under_review';
    case 'rejected':
      return 'rejected';
    default:
      return 'pending';
  }
}

/**
 * Upload entries for every requirement document of the visitor's linked
 * relationships. Rejected relationships are skipped (nothing to upload for
 * them); verified ones are listed read-only.
 * @param {unknown} relationships — GET /api/documents `relationships`
 */
export function buildRelationshipRequirementDocuments(relationships) {
  const list = Array.isArray(relationships) ? relationships : [];

  return list
    .filter((r) => r && typeof r === 'object' && r.id != null && r.status !== 'rejected')
    .flatMap((r) => {
      const requirements = Array.isArray(r.requirements) ? r.requirements : [];
      const relationshipVerified = r.status === 'verified';
      // A file sent by older app versions to the single-file endpoint. Staff
      // can still open it, but it is not counted on the checklist.
      const legacyNote = r.hasSupportingDocument
        ? 'An earlier upload is on file for this relationship. Facility staff may ask you to upload this document here.'
        : null;

      return requirements
        .filter((item) => item && item.kind === 'document' && typeof item.key === 'string')
        .map((item) => {
          const uploadStatus = toRequirementUploadStatus(item.status);
          return {
            key: toRelationshipDocumentKey(r.id, item.key),
            label: String(item.label ?? item.key),
            uploadStatus,
            uploadedAt: item.uploadedAt ?? null,
            verifiedAt: uploadStatus === 'verified' ? (item.reviewedAt ?? null) : null,
            rejectionReason: uploadStatus === 'rejected' ? (item.rejectionReason ?? null) : null,
            reviewNote: uploadStatus === 'pending' ? legacyNote : null,
            canUpload: Boolean(item.canUpload) && !relationshipVerified,
            unavailableReason: null,
            detail: r.pdlName ? `For ${r.pdlName}` : null,
            relationshipRecordId: String(r.id),
            requirementKey: item.key,
          };
        });
    });
}

/**
 * Whether any relationship-side upload exists (checklist file or a legacy
 * single-file upload).
 * @param {unknown} relationships
 */
export function hasAnyRelationshipUpload(relationships) {
  const list = Array.isArray(relationships) ? relationships : [];
  return list.some(
    (r) =>
      r?.hasSupportingDocument ||
      (Array.isArray(r?.requirements) &&
        r.requirements.some(
          (item) => item?.kind === 'document' && item.status && item.status !== 'missing',
        )),
  );
}

/**
 * True when the visitor has no relationship staff could attach documents to
 * yet (none linked, or every link was rejected).
 * @param {unknown} relationships
 */
export function isAwaitingRelationshipLink(relationships) {
  const list = Array.isArray(relationships) ? relationships : [];
  return !list.some((r) => r && r.status !== 'rejected');
}

/**
 * Read-only entries for relationship documents sent with the registration
 * that are not on a relationship checklist yet (GET /api/documents
 * `registrationDocuments`). Staff review them once they link the PDL, so the
 * visitor cannot replace them here; they move onto the relationship's
 * checklist entries after the link.
 * @param {unknown} registrationDocuments
 */
export function buildRegistrationDocuments(registrationDocuments) {
  const list = Array.isArray(registrationDocuments) ? registrationDocuments : [];

  return list
    .filter((d) => d && typeof d.key === 'string')
    .map((d) => {
      const uploadStatus =
        d.status === 'verified' ? 'verified' : d.status === 'rejected' ? 'rejected' : 'under_review';
      return {
        key: `registration:${d.key}`,
        label: String(d.label ?? d.key),
        uploadStatus,
        uploadedAt: d.uploadedAt ?? null,
        verifiedAt: null,
        rejectionReason: null,
        reviewNote: null,
        canUpload: false,
        unavailableReason:
          'Sent with your registration. Facility staff review it after they link your account to the PDL you are visiting.',
        detail: 'Sent with registration',
        relationshipRecordId: null,
        requirementKey: d.key,
      };
    });
}
