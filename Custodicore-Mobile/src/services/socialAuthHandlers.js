import {
  GoogleSignInCancelledError,
  GoogleSignInNotConfiguredError,
  signInWithGoogle,
} from './googleAuthService';

/**
 * @typedef {object} SocialAuthSession
 * @property {string} token — CustodiCore API session token (Bearer)
 * @property {string} email
 * @property {string} [fullName]
 * @property {'google' | 'email'} provider
 */

/**
 * Reusable handler: Google OAuth → backend session.
 * Preserves separation between UI, OAuth SDK, and existing REST auth.
 *
 * @returns {Promise<SocialAuthSession>}
 */
export async function authenticateWithGoogle() {
  const googleUser = await signInWithGoogle();

  // INTEGRATION POINT — wire to BJMP backend when endpoint is available:
  // import * as api from './api';
  // const data = await api.loginWithGoogle({
  //   idToken: googleUser.idToken,
  //   accessToken: googleUser.accessToken,
  // });
  // return {
  //   token: data.token,
  //   email: data.user?.email ?? googleUser.email,
  //   fullName: data.user?.fullName ?? googleUser.name,
  //   provider: 'google',
  // };

  throw new GoogleSignInNotConfiguredError(
    'Google Sign-In succeeded at the OAuth layer, but backend exchange is not implemented. Add api.loginWithGoogle and uncomment socialAuthHandlers.js.',
  );
}

export { GoogleSignInCancelledError, GoogleSignInNotConfiguredError };

export default authenticateWithGoogle;
