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

const REGISTRATION_DOCUMENT_PREFIX = 'registration:';

/**
 * True for an entry built by buildRegistrationDocuments (a file sent with the
 * registration); its replacement goes to POST /api/registration-documents/{key}.
 * @param {string | null | undefined} documentKey
 */
export function isRegistrationDocumentKey(documentKey) {
  return typeof documentKey === 'string' && documentKey.startsWith(REGISTRATION_DOCUMENT_PREFIX);
}

/**
 * Entries for relationship documents sent with the registration that are not
 * on a relationship checklist yet (GET /api/documents `registrationDocuments`).
 * Staff review them with the account; they move onto the relationship's
 * checklist when staff link the PDL. A file staff rejected shows their reason
 * and can be replaced while the account is not approved (backend `canReplace`);
 * otherwise the entry is read-only.
 * @param {unknown} registrationDocuments
 */
export function buildRegistrationDocuments(registrationDocuments) {
  const list = Array.isArray(registrationDocuments) ? registrationDocuments : [];

  return list
    .filter((d) => d && typeof d.key === 'string')
    .map((d) => {
      const uploadStatus =
        d.status === 'verified' ? 'verified' : d.status === 'rejected' ? 'rejected' : 'under_review';
      const canUpload = uploadStatus === 'rejected' && d.canReplace === true;
      const reason =
        uploadStatus === 'rejected' && typeof d.rejectionReason === 'string' && d.rejectionReason.trim()
          ? d.rejectionReason.trim()
          : null;
      return {
        key: `${REGISTRATION_DOCUMENT_PREFIX}${d.key}`,
        label: String(d.label ?? d.key),
        uploadStatus,
        uploadedAt: d.uploadedAt ?? null,
        verifiedAt: null,
        rejectionReason: reason,
        reviewNote: null,
        canUpload,
        unavailableReason: canUpload
          ? null
          : 'Sent with your registration. Facility staff review it after they link your account to the PDL you are visiting.',
        detail: 'Sent with registration',
        relationshipRecordId: null,
        requirementKey: d.key,
      };
    });
}

/** Backend relationship status → My PDLs label (pending / verified / rejected). */
const PDL_RELATIONSHIP_STATUS = {
  pending: { status: 'pending', label: 'Pending' },
  verified: { status: 'approved', label: 'Approved' },
  rejected: { status: 'rejected', label: 'Rejected' },
};

/**
 * "My PDLs": every PDL relationship on the visitor's account, read-only,
 * from GET /api/documents `relationships` (which includes rejected ones).
 * Approved first, then pending, then rejected; the API's newest-first order
 * is kept within each group. An unknown status shows as Pending.
 * @param {unknown} relationships — GET /api/documents `relationships`
 * @returns {{ id: string; pdlName: string; relationshipLabel: string | null; status: 'approved' | 'pending' | 'rejected'; statusLabel: string }[]}
 */
export function buildPdlRelationshipList(relationships) {
  const list = Array.isArray(relationships) ? relationships : [];
  const order = { approved: 0, pending: 1, rejected: 2 };
  return list
    .filter((r) => r && typeof r === 'object' && r.id != null)
    .map((r) => {
      const state = PDL_RELATIONSHIP_STATUS[r.status] ?? PDL_RELATIONSHIP_STATUS.pending;
      return {
        id: String(r.id),
        pdlName: typeof r.pdlName === 'string' && r.pdlName.trim() ? r.pdlName.trim() : 'PDL',
        relationshipLabel:
          typeof r.relationshipLabel === 'string' && r.relationshipLabel.trim()
            ? r.relationshipLabel.trim()
            : null,
        status: /** @type {'approved' | 'pending' | 'rejected'} */ (state.status),
        statusLabel: state.label,
      };
    })
    .map((item, index) => ({ item, index }))
    .sort((a, b) => order[a.item.status] - order[b.item.status] || a.index - b.index)
    .map(({ item }) => item);
}
