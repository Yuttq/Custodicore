/**
 * Friendly labels for the visitor's overall verification status.
 * Maps the backend value (visitor_profiles.verification_status: pending |
 * verified | rejected, from GET /api/me) — never the other way around.
 */

/**
 * @param {string | null | undefined} status
 * @returns {{ verified: boolean; chip: string; label: string }}
 */
export function getVerificationBadge(status) {
  switch (status) {
    case 'verified':
      return { verified: true, chip: 'verification_verified', label: 'Verified' };
    case 'rejected':
      return { verified: false, chip: 'verification_rejected', label: 'Rejected' };
    case 'pending':
    default:
      return { verified: false, chip: 'verification_under_review', label: 'Under Review' };
  }
}

/**
 * The staff-entered reason to show the visitor, only while the account or
 * document is `rejected` (the backend sends null otherwise; this keeps a
 * stale value from showing either way).
 * @param {string | null | undefined} status
 * @param {unknown} reason
 * @returns {string | null}
 */
export function visibleRejectionReason(status, reason) {
  if (status !== 'rejected' || typeof reason !== 'string') return null;
  return reason.trim() || null;
}

/**
 * True for the API's `403 visitor_not_approved` (EnsureVisitorApproved):
 * staff changed the review status after this session loaded /me. Accepts the
 * raw axios error and the api.js request error.
 * @param {any} error
 */
export function isVisitorNotApprovedError(error) {
  const status = error?.response?.status ?? error?.status;
  const code = error?.response?.data?.code ?? error?.code;
  return status === 403 && code === 'visitor_not_approved';
}
