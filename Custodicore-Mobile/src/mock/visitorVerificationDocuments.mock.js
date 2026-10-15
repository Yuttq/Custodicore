/**
 * Type definitions for the verification document UI. The data itself comes
 * from GET /api/documents (repositories/verificationRepository.js); which
 * relationship documents are required is decided by the backend, so no
 * document list or sample data is kept here.
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

export {};
