import { loginWithGoogle as apiLoginWithGoogle } from './api';
import {
  GooglePlayServicesUnavailableError,
  GoogleSignInCancelledError,
  GoogleSignInNotConfiguredError,
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
 * @property {{ email: string, fullName: string|null }} profile — verified Google claims
 * @property {string} consentVersion — Terms/Privacy version to accept at registration
 */

/**
 * Reusable handler: native Google Sign-In → `{ idToken }` → POST /auth/google.
 * Keeps UI, the native Google SDK, and the REST API separate. Does not apply
 * a session — the caller decides what to do with the result.
 *
 * Backend rejections (`link_required`, `not_visitor_account`, `account_inactive`,
 * `invalid_google_token`, `google_unavailable`, 429) propagate as request
 * errors carrying `status` and `code`.
 *
 * @returns {Promise<GoogleAuthenticatedResult | GoogleRegistrationRequiredResult>}
 */
export async function authenticateWithGoogle() {
  const { idToken } = await signInWithGoogle();
  const data = await apiLoginWithGoogle(idToken);

  if (data?.status === 'authenticated') {
    if (!data.token) {
      throw new Error('Google Sign-In succeeded but no session token was returned.');
    }
    return { status: 'authenticated', token: data.token, user: data.user };
  }

  if (data?.status === 'registration_required') {
    return {
      status: 'registration_required',
      profile: {
        email: data.profile?.email ?? '',
        fullName: data.profile?.fullName ?? null,
      },
      consentVersion: data.consentVersion,
    };
  }

  throw new Error('Google Sign-In returned an unexpected response. Please try again.');
}

export {
  GooglePlayServicesUnavailableError,
  GoogleSignInCancelledError,
  GoogleSignInNotConfiguredError,
};

export default authenticateWithGoogle;
