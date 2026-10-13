/**
 * Forgot Password "Resend Link" cooldown after a failed resend.
 *
 * Pure function only (no React / React Native imports) so it can be unit
 * tested with `node --test`. ForgotPasswordSuccessScreen wires it to its
 * countdown state.
 */

/** Never wait longer than the backend's per-email window (10 minutes). */
export const PASSWORD_RESET_MAX_COOLDOWN_SECONDS = 600;

/**
 * Seconds to disable "Resend Link" after a failed resend: on HTTP 429 at
 * least `defaultSeconds`, or the backend's `retryAfter` when longer (capped).
 * Any other failure (network, validation, server) returns 0 so the visitor
 * can try again straight away.
 * @param {{ status?: number; retryAfter?: number } | null | undefined} error
 * @param {number} defaultSeconds
 */
export function cooldownAfterResendError(error, defaultSeconds) {
  if (error?.status !== 429) return 0;
  const retryAfter = Number.isFinite(error.retryAfter) ? Math.ceil(error.retryAfter) : 0;
  return Math.min(Math.max(defaultSeconds, retryAfter), PASSWORD_RESET_MAX_COOLDOWN_SECONDS);
}
