/** @typedef {import('../mock/visitorVerificationDocuments.mock').DocumentUploadStatus} DocumentUploadStatus */

export const VERIFICATION_STATUS_COLORS = {
  missing: '#9CA3AF',
  underReview: '#F59E0B',
  verified: '#16A34A',
  rejected: '#EF4444',
};

/**
 * @param {DocumentUploadStatus} uploadStatus
 */
export function getDocumentStatusDisplay(uploadStatus) {
  switch (uploadStatus) {
    case 'verified':
      return { label: 'Verified', color: VERIFICATION_STATUS_COLORS.verified, key: 'verified' };
    case 'rejected':
      return { label: 'Rejected', color: VERIFICATION_STATUS_COLORS.rejected, key: 'rejected' };
    case 'under_review':
    case 'uploaded':
      return {
        label: 'Under Review',
        color: VERIFICATION_STATUS_COLORS.underReview,
        key: 'under_review',
      };
    case 'pending':
    default:
      return { label: 'Missing', color: VERIFICATION_STATUS_COLORS.missing, key: 'missing' };
  }
}

/**
 * @param {import('../mock/visitorVerificationDocuments.mock').MockVerificationDocument[]} documents
 */
export function getVerificationProgress(documents) {
  const total = documents.length;
  const verifiedCount = documents.filter((doc) => doc.uploadStatus === 'verified').length;
  const percent = total > 0 ? Math.round((verifiedCount / total) * 100) : 0;

  return { total, verifiedCount, percent };
}

/**
 * @param {import('../mock/visitorVerificationDocuments.mock').MockVerificationDocument[]} documents
 * @param {import('../mock/visitorVerificationDocuments.mock').VisitorVerificationStatus} overallStatus
 */
export function getVerificationSummaryTitle(documents, overallStatus) {
  const { verifiedCount, total } = getVerificationProgress(documents);
  if (total > 0 && verifiedCount === total) {
    return 'Verification Complete';
  }
  if (overallStatus === 'verification_verified') {
    return 'Verified';
  }
  return 'Verification In Progress';
}

/**
 * @param {DocumentUploadStatus} uploadStatus
 */
export function isDocumentVerified(uploadStatus) {
  return uploadStatus === 'verified';
}

/**
 * @param {DocumentUploadStatus} uploadStatus
 */
export function canModifyDocument(uploadStatus) {
  return uploadStatus !== 'verified';
}

/**
 * @param {DocumentUploadStatus} uploadStatus
 * @returns {null | 'replace' | 'upload_new' | 'upload'}
 */
export function getDocumentDetailAction(uploadStatus) {
  switch (uploadStatus) {
    case 'verified':
      return null;
    case 'under_review':
    case 'uploaded':
      return 'replace';
    case 'rejected':
      return 'upload_new';
    case 'pending':
    default:
      return 'upload';
  }
}

/**
 * @param {DocumentUploadStatus} uploadStatus
 */
export function getDocumentDetailActionLabel(uploadStatus) {
  const action = getDocumentDetailAction(uploadStatus);
  switch (action) {
    case 'replace':
      return 'Replace Document';
    case 'upload_new':
      return 'Upload New Document';
    case 'upload':
      return 'Upload Document';
    default:
      return null;
  }
}
