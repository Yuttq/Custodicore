import { isGoogleSignInConfigured } from '../config/authConfig';

/**
 * Thrown when Google OAuth client IDs are missing or the native flow is not wired yet.
 */
export class GoogleSignInNotConfiguredError extends Error {
  constructor(message) {
    super(
      message ??
        'Google Sign-In is not configured. Add EXPO_PUBLIC_GOOGLE_* client IDs to your environment.',
    );
    this.name = 'GoogleSignInNotConfiguredError';
  }
}

/**
 * Thrown when the user cancels the Google account picker.
 */
export class GoogleSignInCancelledError extends Error {
  constructor() {
    super('Google Sign-In was cancelled.');
    this.name = 'GoogleSignInCancelledError';
  }
}

/**
 * @typedef {object} GoogleSignInResult
 * @property {string} idToken — send to backend `POST /auth/google` for verification
 * @property {string} [accessToken]
 * @property {string} email
 * @property {string} [name]
 * @property {string} [photoUrl]
 */

/**
 * Starts the Google OAuth user-consent flow and returns tokens for backend exchange.
 *
 * Integration steps (production):
 * 1. Install `expo-auth-session` and `expo-web-browser` (already in project).
 * 2. Configure Google Cloud OAuth clients (Android, iOS, Web) for your bundle ID / SHA-1.
 * 3. Set `EXPO_PUBLIC_GOOGLE_*` in `.env`.
 * 4. Implement `promptGoogleOAuth()` below using `Google.useAuthRequest` or native SDK.
 * 5. Exchange `idToken` via `api.loginWithGoogle({ idToken })` in `socialAuthHandlers.js`.
 *
 * @returns {Promise<GoogleSignInResult>}
 */
export async function signInWithGoogle() {
  if (!isGoogleSignInConfigured()) {
    throw new GoogleSignInNotConfiguredError();
  }

  // INTEGRATION POINT — replace with real OAuth (expo-auth-session / @react-native-google-signin/google-signin)
  // const result = await promptGoogleOAuth(authConfig.google);
  // if (result.type === 'cancel') throw new GoogleSignInCancelledError();
  // return mapGoogleAuthResult(result);

  throw new GoogleSignInNotConfiguredError(
    'Google OAuth client IDs are set, but signInWithGoogle() is not implemented yet. Complete googleAuthService.js and call the backend token exchange.',
  );
}

/**
 * Optional: revoke Google session on logout when using native Google Sign-In.
 * @returns {Promise<void>}
 */
export async function signOutGoogle() {
  if (!isGoogleSignInConfigured()) return;
  // INTEGRATION POINT — GoogleSignin.signOut() if using @react-native-google-signin/google-signin
}

export default signInWithGoogle;
