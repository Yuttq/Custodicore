/**
 * Forgot password — POST /api/auth/password/forgot (Laravel PasswordResetController).
 * The emailed link opens a web page where the visitor chooses a new password,
 * then signs in to the app as usual.
 */
import { requestPasswordReset as apiRequestPasswordReset } from './api';

/** Same wording as the backend; shown whether or not the address is registered. */
export const PASSWORD_RESET_GENERIC_MESSAGE =
  'If an account exists for this email address, a password reset link has been sent.';

/** Seconds before "Resend Link" can be tapped again (the backend also limits it). */
export const PASSWORD_RESET_RESEND_COOLDOWN_SECONDS = 60;

/**
 * @param {string} email
 * @returns {Promise<{ status?: string; message: string }>}
 */
export async function requestPasswordReset(email) {
  const data = await apiRequestPasswordReset(String(email || '').trim());
  return {
    ...data,
    message:
      typeof data?.message === 'string' && data.message.trim()
        ? data.message
        : PASSWORD_RESET_GENERIC_MESSAGE,
  };
}

/** Same request as the first one — the newest link replaces earlier ones. */
export const resendPasswordReset = requestPasswordReset;

/**
 * User-facing text for a failed request (network, 429, validation, server).
 * @param {unknown} e  an Error from services/api (with optional `status`)
 */
export function describePasswordResetError(e) {
  if (e?.status === 429) {
    return 'Too many password reset emails were requested. Please wait a few minutes and try again.';
  }
  if (typeof e?.message === 'string' && e.message.trim()) return e.message;
  return 'Could not send a password reset link. Please try again.';
}
