import { getRelationshipLabel } from './registrationRequirements';

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
 * All required verification uploads for a relationship (relationship docs + Government ID).
 * @param {string} [relationshipId]
 * @returns {{ key: string, label: string }[]}
 */
export function getRequiredVerificationDocuments(relationshipId) {
  return [
    ...getRelationshipVerificationDocs(relationshipId),
    { key: GOVERNMENT_ID_KEY, label: 'Government ID' },
  ];
}

/**
 * Relationship-based documents for Section 2 (excludes Government ID).
 * @param {string} [relationshipId]
 * @returns {{ key: string, label: string }[]}
 */
export function getRelationshipVerificationDocs(relationshipId) {
  switch (relationshipId) {
    case 'spouse':
      return [{ key: 'marriage_certificate', label: 'Marriage Certificate' }];
    case 'parent':
      return [];
    case 'child':
      return [{ key: 'birth_certificate', label: 'PSA Birth Certificate' }];
    case 'live_in_partner':
      return [{ key: 'cenomar', label: 'CENOMAR' }];
    case 'legal_guardian':
      return [{ key: 'authorization_documents', label: 'Authorization Documents' }];
    case 'legal_counsel':
      return [{ key: 'legal_representation', label: 'Legal Representation Documents' }];
    default:
      return [];
  }
}

/**
 * @param {string} [relationshipId]
 * @returns {number}
 */
export function getTotalVerificationDocumentCount(relationshipId) {
  return 1 + getRelationshipVerificationDocs(relationshipId).length;
}

/**
 * @param {string} [relationshipId]
 * @returns {string}
 */
export function getRelationshipSectionSubtitle(relationshipId) {
  const label = getRelationshipLabel(relationshipId);
  if (!relationshipId || relationshipId === 'parent') {
    return 'Based on your relationship to the PDL, only your government-issued ID is required.';
  }
  return `Required documents for ${label}.`;
}

/**
 * @param {{ uri?: string | null, status?: string }} entry
 * @returns {boolean}
 */
export function isDocumentUploaded(entry) {
  return Boolean(entry?.uri) || entry?.status === 'uploaded' || entry?.status === 'under_review' || entry?.status === 'verified';
}

/**
 * @param {Record<string, { uri?: string | null }>} documents
 * @param {string} relationshipId
 * @returns {number}
 */
export function countUploadedVerificationDocuments(documents, relationshipId) {
  const keys = [
    GOVERNMENT_ID_KEY,
    ...getRelationshipVerificationDocs(relationshipId).map((d) => d.key),
  ];
  return keys.filter((key) => isDocumentUploaded(documents[key])).length;
}
