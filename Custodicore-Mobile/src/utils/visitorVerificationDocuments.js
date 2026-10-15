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
