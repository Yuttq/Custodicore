/**
 * What the restricted Verification screen tells a visitor whose account is
 * not approved yet. Pure — no React Native imports — so it runs under
 * `node --test`.
 *
 * Two independent backend sources, never mixed up:
 *   - the ACCOUNT status, visitor_profiles.verification_status from GET /api/me
 *     (pending | verified | rejected). `rejected` is what staff call
 *     "Returned for correction": the backend moves it back to `pending` once
 *     the visitor saves a correction (VisitorProfile::resubmitIfReturned).
 *     There is no separate, final registration rejection.
 *   - each DOCUMENT's own status from GET /api/documents, already normalized
 *     by verificationRepository.normalizeVerification
 *     (pending | under_review | verified | rejected).
 * A rejected document never makes the account rejected, and an account
 * that is still `pending` is not reported as plain "Under Review" while a
 * document needs correcting.
 */

/**
 * @typedef {object} ReviewDocument
 * @property {string} key
 * @property {string} label
 * @property {'pending' | 'under_review' | 'uploaded' | 'verified' | 'rejected'} uploadStatus
 * @property {string | null} [rejectionReason]
 * @property {string | null} [detail]
 */

/**
 * @typedef {object} AttentionDocument
 * @property {string} key
 * @property {string} label
 * @property {string | null} detail
 * @property {string | null} reason
 */

/**
 * @typedef {object} VerificationReviewState
 * @property {'approved' | 'returned' | 'action_required' | 'under_review'} kind
 * @property {string} title
 * @property {string} message
 * @property {{ status: string; label: string }} chip — StatusChip variant + display label
 * @property {'success' | 'danger' | 'warning' | 'info'} tone
 * @property {string | null} accountReason — staff reason for returning the whole account
 * @property {AttentionDocument[]} documentsNeedingAttention
 * @property {number} underReviewCount — uploaded documents still waiting for staff
 * @property {boolean} documentsKnown — false while GET /api/documents is not loaded
 * @property {boolean} showDocumentsAction — the visitor has something to fix
 */

/** @param {unknown} value */
function cleanText(value) {
  return typeof value === 'string' && value.trim() ? value.trim() : null;
}

/** @param {number} n @param {string} one @param {string} many */
function plural(n, one, many) {
  return n === 1 ? one : many;
}

/**
 * @param {object} input
 * @param {string | null | undefined} input.accountStatus — /me verificationStatus
 * @param {string | null | undefined} [input.accountReason] — /me rejectionReason
 * @param {ReviewDocument[] | null | undefined} [input.documents] — normalized documents, or null while unknown
 * @returns {VerificationReviewState}
 */
export function getVerificationReviewState({ accountStatus, accountReason, documents }) {
  const documentsKnown = Array.isArray(documents);
  const list = documentsKnown ? /** @type {ReviewDocument[]} */ (documents) : [];

  /** @type {AttentionDocument[]} */
  const documentsNeedingAttention = list
    .filter((doc) => doc && doc.uploadStatus === 'rejected')
    .map((doc) => ({
      key: String(doc.key),
      label: cleanText(doc.label) ?? 'Document',
      detail: cleanText(doc.detail),
      reason: cleanText(doc.rejectionReason),
    }));
  const underReviewCount = list.filter(
    (doc) => doc && (doc.uploadStatus === 'under_review' || doc.uploadStatus === 'uploaded'),
  ).length;

  const base = { documentsNeedingAttention, underReviewCount, documentsKnown, accountReason: null };

  if (accountStatus === 'verified') {
    return {
      ...base,
      documentsNeedingAttention: [],
      kind: 'approved',
      title: 'Approved',
      message:
        'Your account has been approved. You can now use the visitor portal to request and manage visits.',
      chip: { status: 'verification_verified', label: 'Approved' },
      tone: 'success',
      showDocumentsAction: false,
    };
  }

  if (accountStatus === 'rejected') {
    return {
      ...base,
      kind: 'returned',
      title: 'Returned for Correction',
      message:
        'Staff returned your registration so you can correct it. Once you save a correction, it goes back to staff for review.',
      chip: { status: 'verification_rejected', label: 'Returned' },
      tone: 'danger',
      accountReason: cleanText(accountReason),
      showDocumentsAction: true,
    };
  }

  // `pending` (or anything unrecognised, as getVerificationBadge treats it).
  const rejectedCount = documentsNeedingAttention.length;
  if (rejectedCount > 0) {
    const stillReviewing =
      underReviewCount > 0
        ? ` ${underReviewCount} other ${plural(underReviewCount, 'document is', 'documents are')} still under review.`
        : '';
    return {
      ...base,
      kind: 'action_required',
      title: 'Action Required',
      message:
        `Staff need you to correct ${rejectedCount} ${plural(rejectedCount, 'document', 'documents')}. ` +
        `Your account is still under review.${stillReviewing}`,
      chip: { status: 'document_rejected', label: 'Action Required' },
      tone: 'warning',
      showDocumentsAction: true,
    };
  }

  return {
    ...base,
    kind: 'under_review',
    title: 'Under Review',
    message:
      "Your email is verified. Staff are reviewing your registration. We'll notify you once it's approved.",
    chip: { status: 'verification_under_review', label: 'Under Review' },
    tone: 'info',
    showDocumentsAction: false,
  };
}

/**
 * Title and message for the Check Status result, from freshly loaded data.
 * @param {VerificationReviewState} state
 * @returns {{ title: string; message: string }}
 */
export function getCheckStatusNotice(state) {
  switch (state.kind) {
    case 'approved':
      return {
        title: 'Account approved',
        message: 'You can now use the visitor portal to request and manage visits.',
      };
    case 'returned':
      return {
        title: 'Returned for correction',
        message: state.accountReason
          ? `What to fix: ${state.accountReason}`
          : 'Open Verification Documents to correct your registration, or contact facility staff for details.',
      };
    case 'action_required': {
      const n = state.documentsNeedingAttention.length;
      return {
        title: 'Action required',
        message: `${n} ${plural(n, 'document needs', 'documents need')} correction. Open Verification Documents to replace ${plural(n, 'it', 'them')}.`,
      };
    }
    case 'under_review':
    default:
      return {
        title: 'Still under review',
        message: state.documentsKnown
          ? 'Our staff is still reviewing your documents and information. You will be notified once your account has been approved.'
          : 'Your account is still under review. Document details could not be loaded right now.',
      };
  }
}
