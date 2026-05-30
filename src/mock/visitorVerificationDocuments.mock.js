import { getRequiredVerificationDocuments } from '../utils/visitorVerificationDocuments';

/**
 * TEMP MOCK DATA — Replace with GET /visitor/verification when API is available.
 *
 * @typedef {'pending' | 'uploaded' | 'under_review' | 'verified' | 'rejected'} DocumentUploadStatus
 * @typedef {'verification_pending' | 'verification_under_review' | 'verification_verified' | 'verification_rejected'} VisitorVerificationStatus
 *
 * @typedef {object} MockVerificationDocument
 * @property {string} key
 * @property {string} label
 * @property {DocumentUploadStatus} uploadStatus
 * @property {string | null} [uploadedAt]
 * @property {string | null} [verifiedAt]
 * @property {string | null} [rejectionReason]
 * @property {string | null} [reviewNote]
 */

/**
 * @typedef {object} MockVisitorVerification
 * @property {VisitorVerificationStatus} verificationStatus
 * @property {string} relationshipId
 * @property {string} relationshipLabel
 * @property {MockVerificationDocument[]} documents
 * @property {string | null} [submittedAt]
 * @property {string | null} [reviewedAt]
 * @property {string | null} [rejectionReason]
 */

/** @type {Record<string, Record<string, Partial<MockVerificationDocument>>>} */
const DOCUMENT_STATUS_PRESETS = {
  spouse: {
    marriage_certificate: {
      uploadStatus: 'verified',
      uploadedAt: '2026-05-02T10:15:00',
      verifiedAt: '2026-05-03T14:15:00',
      reviewNote: 'Certificate matched submitted profile.',
    },
    government_id: {
      uploadStatus: 'under_review',
      uploadedAt: '2026-05-02T10:20:00',
    },
  },
  child: {
    birth_certificate: {
      uploadStatus: 'under_review',
      uploadedAt: '2026-05-04T14:30:00',
    },
    government_id: {
      uploadStatus: 'under_review',
      uploadedAt: '2026-05-04T14:35:00',
    },
  },
  live_in_partner: {
    cenomar: {
      uploadStatus: 'pending',
    },
    government_id: {
      uploadStatus: 'pending',
    },
  },
  legal_guardian: {
    authorization_documents: {
      uploadStatus: 'rejected',
      uploadedAt: '2026-05-01T09:00:00',
      rejectionReason: 'Authorization letter is missing notarized signature.',
    },
    government_id: {
      uploadStatus: 'verified',
      uploadedAt: '2026-05-01T09:05:00',
      verifiedAt: '2026-05-02T10:30:00',
    },
  },
  parent: {
    government_id: {
      uploadStatus: 'verified',
      uploadedAt: '2026-04-28T11:00:00',
      verifiedAt: '2026-04-29T08:30:00',
    },
  },
};

/** @type {Record<string, Omit<MockVisitorVerification, 'documents' | 'relationshipId' | 'relationshipLabel'>>} */
const VERIFICATION_META = {
  spouse: {
    verificationStatus: 'verification_under_review',
    submittedAt: '2026-05-02T10:20:00',
    reviewedAt: null,
    rejectionReason: null,
  },
  child: {
    verificationStatus: 'verification_under_review',
    submittedAt: '2026-05-04T14:35:00',
    reviewedAt: null,
    rejectionReason: null,
  },
  live_in_partner: {
    verificationStatus: 'verification_pending',
    submittedAt: null,
    reviewedAt: null,
    rejectionReason: null,
  },
  legal_guardian: {
    verificationStatus: 'verification_rejected',
    submittedAt: '2026-05-01T09:05:00',
    reviewedAt: '2026-05-03T16:00:00',
    rejectionReason:
      'Authorization documents were rejected. Please upload a notarized authorization letter.',
  },
  parent: {
    verificationStatus: 'verification_verified',
    submittedAt: '2026-04-28T11:00:00',
    reviewedAt: '2026-04-29T08:30:00',
    rejectionReason: null,
  },
};

/**
 * @param {string} [relationshipId]
 * @returns {MockVisitorVerification}
 */
export function getMockVisitorVerification(relationshipId = 'spouse') {
  const key = relationshipId in DOCUMENT_STATUS_PRESETS ? relationshipId : 'spouse';
  const meta = VERIFICATION_META[key] ?? VERIFICATION_META.spouse;
  const presets = DOCUMENT_STATUS_PRESETS[key] ?? DOCUMENT_STATUS_PRESETS.spouse;
  const required = getRequiredVerificationDocuments(key);

  /** @type {MockVerificationDocument[]} */
  const documents = required.map((doc) => {
    const preset = presets[doc.key];
    return {
      key: doc.key,
      label: doc.label,
      uploadStatus: preset?.uploadStatus ?? 'pending',
      uploadedAt: preset?.uploadedAt ?? null,
      verifiedAt: preset?.verifiedAt ?? null,
      rejectionReason: preset?.rejectionReason ?? null,
      reviewNote: preset?.reviewNote ?? null,
    };
  });

  return {
    ...meta,
    relationshipId: key,
    relationshipLabel: getRelationshipLabelFromId(key),
    documents,
  };
}

/**
 * @param {string} relationshipId
 */
function getRelationshipLabelFromId(relationshipId) {
  switch (relationshipId) {
    case 'spouse':
      return 'Spouse';
    case 'child':
      return 'Child';
    case 'live_in_partner':
      return 'Live-In Partner';
    case 'legal_guardian':
      return 'Legal Guardian';
    case 'parent':
      return 'Parent';
    default:
      return 'Visitor';
  }
}

/**
 * Maps document workflow status to StatusChip keys (BJMP: Pending, Under Review, Verified, Rejected).
 * @param {DocumentUploadStatus} uploadStatus
 */
export function documentWorkflowStatusToChip(uploadStatus) {
  switch (uploadStatus) {
    case 'uploaded':
    case 'under_review':
      return 'document_under_review';
    case 'verified':
      return 'document_verified';
    case 'rejected':
      return 'document_rejected';
    case 'pending':
    default:
      return 'document_pending';
  }
}

/**
 * Maps mock upload status to StatusChip keys.
 * @param {DocumentUploadStatus} uploadStatus
 */
export function documentUploadStatusToChip(uploadStatus) {
  return documentWorkflowStatusToChip(uploadStatus);
}
