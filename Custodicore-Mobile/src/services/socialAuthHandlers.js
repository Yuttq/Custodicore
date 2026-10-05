import { loginWithGoogle as apiLoginWithGoogle } from './api';
import {
  GooglePlayServicesUnavailableError,
  GoogleSessionExpiredError,
  GoogleSignInCancelledError,
  GoogleSignInNotConfiguredError,
  refreshGoogleIdToken,
  signInWithGoogle,
} from './googleAuthService';

/**
 * @typedef {object} GoogleAuthenticatedResult
 * @property {'authenticated'} status
 * @property {string} token — CustodiCore API session token (Bearer)
 * @property {object} user
 */

/**
 * @typedef {object} GoogleRegistrationRequiredResult
 * @property {'registration_required'} status
 * @property {string} idToken — needed for POST /auth/google/register; the
 *   caller must keep it in memory only and never hand it to screens
 * @property {{ email: string, fullName: string|null }} profile — verified Google claims
 * @property {string} consentVersion — Terms/Privacy version to accept at registration
 */

/**
 * @typedef {object} GoogleLinkRequiredResult
 * @property {'link_required'} status
 * @property {string} idToken — needed for POST /auth/google/link; the caller
 *   must keep it in memory only and never hand it to screens
 * @property {string|null} email — picked Google account, for display only
 */

/**
 * Reusable handler: native Google Sign-In → `{ idToken }` → POST /auth/google.
 * Keeps UI, the native Google SDK, and the REST API separate. Does not apply
 * a session — the caller decides what to do with the result.
 *
 * `link_required` (409) is returned as a result so the ID token survives for
 * linking. Other backend rejections (`not_visitor_account`, `account_inactive`,
 * `invalid_google_token`, `google_unavailable`, 429) propagate as request
 * errors carrying `status` and `code`.
 *
 * @returns {Promise<GoogleAuthenticatedResult | GoogleRegistrationRequiredResult | GoogleLinkRequiredResult>}
 */
export async function authenticateWithGoogle() {
  const { idToken, email } = await signInWithGoogle();

  let data;
  try {
    data = await apiLoginWithGoogle(idToken);
  } catch (e) {
    if (e?.code === 'link_required') {
      return { status: 'link_required', idToken, email };
    }
    throw e;
  }

  if (data?.status === 'authenticated') {
    if (!data.token) {
      throw new Error('Google Sign-In succeeded but no session token was returned.');
    }
    return { status: 'authenticated', token: data.token, user: data.user };
  }

  if (data?.status === 'registration_required') {
    return {
      status: 'registration_required',
      idToken,
      profile: {
        email: data.profile?.email ?? '',
        fullName: data.profile?.fullName ?? null,
      },
      consentVersion: data.consentVersion,
    };
  }

  throw new Error('Google Sign-In returned an unexpected response. Please try again.');
}

function normalizeEmail(email) {
  return String(email || '').trim().toLowerCase();
}

/**
 * Returns a fresh Google ID token for the final Google registration request,
 * so a token that expired while the visitor filled in the form is never
 * submitted. The refreshed Google account must be the one the registration
 * started with.
 *
 * @param {string} expectedEmail — verified email from `registration_required`
 * @returns {Promise<string>} fresh ID token
 * @throws {GoogleSessionExpiredError} silent refresh failed (`google_session_expired`)
 * @throws {Error} `code: 'google_email_mismatch'` — a different Google account
 */
export async function getFreshGoogleRegistrationToken(expectedEmail) {
  const { idToken, email } = await refreshGoogleIdToken();
  const expected = normalizeEmail(expectedEmail);
  if (!expected || normalizeEmail(email) !== expected) {
    const err = new Error(
      'The Google account on this device has changed. Please sign in with Google again.',
    );
    err.code = 'google_email_mismatch';
    throw err;
  }
  return idToken;
}

export {
  GooglePlayServicesUnavailableError,
  GoogleSessionExpiredError,
  GoogleSignInCancelledError,
  GoogleSignInNotConfiguredError,
};

export default authenticateWithGoogle;
