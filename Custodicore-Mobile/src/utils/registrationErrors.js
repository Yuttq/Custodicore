/**
 * POST /auth/register failures → visitor-facing outcome. Pure (no React
 * Native imports) so it can be unit tested with node --test.
 *
 * The backend enforces `unique:accounts,email` (case-insensitive) and answers
 * 422 `{ errors: { email: ["The email has already been taken."] } }`. That
 * covers an earlier attempt whose account still exists but whose email was
 * never verified — the visitor finishes that account (sign in, or Forgot
 * Password) instead of registering again. The message never says whether the
 * existing account is verified or linked to Google.
 */

export const EMAIL_TAKEN_FIELD_MESSAGE =
  'An account already uses this email address. Sign in instead, or use a different email.';

export const EMAIL_TAKEN_ALERT = {
  title: 'Email already registered',
  message:
    'An account already uses this email address, possibly from an earlier registration. Sign in with this email instead. If you have not verified it yet, signing in lets you resend the verification email. Forgot your password? Use Forgot Password.',
};

/**
 * Laravel's `unique` message ("The email has already been taken."). Other
 * email errors (format, length) are not an existing account.
 * @param {unknown} e
 */
export function isEmailTakenError(e) {
  if (e?.status !== 422 || !e.errors || typeof e.errors !== 'object') return false;
  const messages = [].concat(e.errors.email ?? []);
  return messages.some((m) => typeof m === 'string' && /already (been )?taken/i.test(m));
}

/**
 * The request timed out without a response (api.js sets `timedOut`): the
 * backend may still have created the account and sent the verification
 * email, so the visitor is not told to simply register again.
 * @param {unknown} e
 */
export function isUnconfirmedRegistrationError(e) {
  return Boolean(e) && typeof e === 'object' && e.status === undefined && e.timedOut === true;
}

export const UNCONFIRMED_REGISTRATION_ALERT = {
  title: 'Registration not confirmed',
  message:
    'The server took too long to respond, so we could not confirm your registration. Your account may already have been created: check your email for a verification link, or try signing in before registering again.',
};
