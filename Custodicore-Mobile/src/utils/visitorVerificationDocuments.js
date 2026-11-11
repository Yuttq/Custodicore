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

/**
 * One plain sentence for the account's own verification
 * (visitor_profiles.verification_status), shown on Verification Documents.
 * Additional PDLs have their own status under My PDLs.
 * @param {string | null | undefined} backendStatus — pending | rejected | verified
 * @returns {string | null}
 */
export function getAccountStatusMessage(backendStatus) {
  if (backendStatus === 'verified') return 'Your account verification is complete.';
  if (backendStatus === 'rejected') return 'Please review the reason and replace the required documents.';
  if (backendStatus === 'pending') return 'Your documents are being reviewed.';
  return null;
}

/**
 * @typedef {object} VerificationDocumentSection
 * @property {string} key — 'account' or 'relationship-<id>'
 * @property {string} title
 * @property {'account' | 'first_pdl' | 'additional_pdl'} kind
 * @property {any[]} documents
 */

/**
 * Splits Verification Documents into what the visitor is looking at:
 *   1. Account Verification — the Government ID and the documents sent with
 *      the registration (no relationship yet);
 *   2. the first PDL's documents (the relationship approved with the account:
 *      the oldest one on the account);
 *   3. each additional PDL's documents.
 * The order inside each section is kept. Pure, so it runs under `node --test`.
 * @param {{ relationshipRecordId?: string | null; detail?: string | null }[] | null | undefined} documents — normalizeVerification().documents
 * @param {{ id: string; pdlName?: string }[] | null | undefined} pdls — normalizeVerification().pdlRelationships
 * @returns {VerificationDocumentSection[]}
 */
export function groupVerificationDocuments(documents, pdls) {
  const docs = Array.isArray(documents) ? documents : [];
  const list = Array.isArray(pdls) ? pdls : [];
  const idNumber = (id) => {
    const n = Number(id);
    return Number.isFinite(n) ? n : Number.MAX_SAFE_INTEGER;
  };

  // The first PDL is the oldest relationship on the account (lowest id),
  // also when it is not listed with documents (e.g. rejected).
  const allIds = [
    ...list.map((p) => String(p?.id ?? '')),
    ...docs.map((d) => (d?.relationshipRecordId ? String(d.relationshipRecordId) : '')),
  ].filter(Boolean);
  const firstId = allIds.length
    ? allIds.reduce((min, id) => (idNumber(id) < idNumber(min) ? id : min))
    : null;

  /** @type {VerificationDocumentSection[]} */
  const sections = [];
  const account = docs.filter((d) => !d?.relationshipRecordId);
  if (account.length) {
    sections.push({ key: 'account', title: 'Your Account', kind: 'account', documents: account });
  }

  /** @type {Map<string, any[]>} */
  const byRelationship = new Map();
  docs.forEach((d) => {
    if (!d?.relationshipRecordId) return;
    const id = String(d.relationshipRecordId);
    if (!byRelationship.has(id)) byRelationship.set(id, []);
    byRelationship.get(id).push(d);
  });

  [...byRelationship.keys()]
    .sort((a, b) => idNumber(a) - idNumber(b))
    .forEach((id) => {
      const relDocs = byRelationship.get(id) ?? [];
      const listed = list.find((p) => String(p?.id) === id);
      const fromDetail = String(relDocs[0]?.detail ?? '').replace(/^For\s+/, '').trim();
      const name = (listed?.pdlName && listed.pdlName !== 'PDL' ? listed.pdlName : '') || fromDetail || 'PDL';
      const first = id === firstId;
      // The visitor knows their PDLs by name, not as "first" or "additional".
      const relationshipLabel = typeof listed?.relationshipLabel === 'string' ? listed.relationshipLabel.trim() : '';
      sections.push({
        key: `relationship-${id}`,
        title: relationshipLabel ? `${name} · ${relationshipLabel}` : name,
        kind: first ? 'first_pdl' : 'additional_pdl',
        documents: relDocs,
      });
    });

  return sections;
}
