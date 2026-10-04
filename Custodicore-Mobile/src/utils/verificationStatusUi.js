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
