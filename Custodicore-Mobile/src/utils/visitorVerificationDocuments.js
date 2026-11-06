// Which relationship documents a visitor needs is decided by the backend
// (GET /api/documents per linked relationship; GET /api/registration/requirements
// for the general rules). No relationship document list is kept in the app.

export const GOVERNMENT_ID_KEY = 'government_id';

/** Government IDs accepted for visitor verification (Section 1). */
export const ACCEPTED_GOVERNMENT_IDS = [
  'National ID',
  "Driver's License",
  'Passport',
  "Voter's ID",
  'PhilHealth ID',
  'UMID',
];

/**
 * @param {{ uri?: string | null, status?: string }} entry
 * @returns {boolean}
 */
export function isDocumentUploaded(entry) {
  return Boolean(entry?.uri) || entry?.status === 'uploaded' || entry?.status === 'under_review' || entry?.status === 'verified';
}

/**
 * Whether the government ID entry offers an upload. The backend decides
 * (GET /api/documents `canUploadGovernmentId`, enforced by POST /documents):
 * before approval only a rejected ID — or any ID once staff returned the
 * account — can be replaced, never an approved one. Older backends without
 * the flag keep the previous rule (anything but a verified ID).
 * @param {string | null | undefined} currentIdStatus — the ID shown (verified first, else newest)
 * @param {unknown} backendFlag — `canUploadGovernmentId`
 */
export function canUploadGovernmentIdEntry(currentIdStatus, backendFlag) {
  if (currentIdStatus === 'verified') return false;
  return typeof backendFlag === 'boolean' ? backendFlag : true;
}

/** Shown on a document that is waiting for review and cannot be replaced. */
export const UNDER_REVIEW_LOCKED_NOTE =
  'Waiting for review. You can replace it only if facility staff reject it.';

/**
 * Photo action on Verification Documents, from the backend's
 * `canReplacePhoto` (POST /api/me/photo enforces the same rule: retake only
 * while the account is returned for correction, or a first photo).
 * @param {{ canReplacePhoto?: boolean; hasPhoto?: boolean } | null | undefined} verification
 * @returns {'retake' | 'take' | null}
 */
export function getPhotoAction(verification) {
  if (verification?.canReplacePhoto !== true) return null;
  return verification.hasPhoto ? 'retake' : 'take';
}
