import { Platform } from 'react-native';
import { authConfig, isGoogleSignInConfigured } from '../config/authConfig';

/**
 * Thrown when Google Sign-In cannot run in this build (Expo Go, non-Android,
 * or EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID missing).
 */
export class GoogleSignInNotConfiguredError extends Error {
  constructor(message) {
    super(message ?? 'Google Sign-In is not available in this version of the app.');
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
 * Thrown when Google Play Services is missing or outdated on the device.
 */
export class GooglePlayServicesUnavailableError extends Error {
  constructor() {
    super(
      'Google Play Services is not available or needs an update on this device. Please sign in with your email and password.',
    );
    this.name = 'GooglePlayServicesUnavailableError';
  }
}

/** @type {typeof import('@react-native-google-signin/google-signin') | null} */
let googleSigninModule = null;
let configured = false;

/**
 * Lazy-loads the native library. The package resolves its native module at
 * import time and throws when it is absent (Expo Go), so it is only required
 * after `isGoogleSignInConfigured()` confirms the module exists.
 */
function loadGoogleSignin() {
  if (!isGoogleSignInConfigured()) {
    throw new GoogleSignInNotConfiguredError();
  }
  if (!googleSigninModule) {
    try {
      googleSigninModule = require('@react-native-google-signin/google-signin');
    } catch {
      throw new GoogleSignInNotConfiguredError();
    }
  }
  if (!configured) {
    // ID token only: no offline access / server auth code, no client secret.
    googleSigninModule.GoogleSignin.configure({
      webClientId: authConfig.google.webClientId,
    });
    configured = true;
  }
  return googleSigninModule;
}

/**
 * Opens the native Google account picker and returns the ID token for the
 * backend `POST /auth/google` exchange, plus the picked account's email for
 * display only (the backend reads identity from the verified token). Nothing
 * else from Google is returned or stored.
 *
 * @returns {Promise<{ idToken: string, email: string|null }>}
 */
export async function signInWithGoogle() {
  const { GoogleSignin, isErrorWithCode, statusCodes } = loadGoogleSignin();

  try {
    if (Platform.OS === 'android') {
      await GoogleSignin.hasPlayServices({ showPlayServicesUpdateDialog: true });
    }

    const response = await GoogleSignin.signIn();
    if (response?.type === 'cancelled') {
      throw new GoogleSignInCancelledError();
    }

    const idToken = response?.data?.idToken;
    if (!idToken) {
      throw new Error('Google Sign-In did not return an ID token. Please try again.');
    }
    return { idToken, email: response?.data?.user?.email ?? null };
  } catch (e) {
    if (e instanceof GoogleSignInCancelledError) throw e;
    if (isErrorWithCode(e)) {
      if (e.code === statusCodes.SIGN_IN_CANCELLED) {
        throw new GoogleSignInCancelledError();
      }
      if (e.code === statusCodes.PLAY_SERVICES_NOT_AVAILABLE) {
        throw new GooglePlayServicesUnavailableError();
      }
      if (e.code === statusCodes.IN_PROGRESS) {
        throw new Error('Google Sign-In is already in progress.');
      }
      // Native error text can be technical — keep the visitor message generic.
      throw new Error('Google Sign-In failed. Please try again.');
    }
    throw e;
  }
}

/** Play Services `CommonStatusCodes.NETWORK_ERROR`, as the native module reports it. */
const GOOGLE_NETWORK_ERROR_CODE = '7';

/**
 * Thrown when no fresh Google ID token can be obtained without user
 * interaction (no saved Google session, or the silent refresh failed).
 */
export class GoogleSessionExpiredError extends Error {
  constructor() {
    super('Your Google sign-in has expired. Please sign in with Google again.');
    this.name = 'GoogleSessionExpiredError';
    this.code = 'google_session_expired';
  }
}

/**
 * Silently refreshes the native Google session (no account picker) and
 * returns a fresh ID token plus the signed-in account's email. Never falls
 * back to a previously issued token.
 *
 * @returns {Promise<{ idToken: string, email: string|null }>}
 * @throws {GoogleSessionExpiredError} when no fresh token is available
 * @throws {Error} `code: 'google_network_error'` — offline; safe to retry
 */
export async function refreshGoogleIdToken() {
  let GoogleSignin;
  try {
    ({ GoogleSignin } = loadGoogleSignin());
  } catch {
    throw new GoogleSessionExpiredError();
  }

  let response;
  try {
    response = await GoogleSignin.signInSilently();
  } catch (e) {
    // Offline: retryable — the Google session itself may still be valid.
    if (e?.code === GOOGLE_NETWORK_ERROR_CODE) {
      const err = new Error(
        'Unable to reach Google. Check your internet connection and try again.',
      );
      err.code = 'google_network_error';
      throw err;
    }
    // Native error text can be technical — the visitor just signs in again.
    throw new GoogleSessionExpiredError();
  }

  const idToken = response?.type === 'success' ? response.data?.idToken : null;
  if (!idToken) {
    throw new GoogleSessionExpiredError();
  }
  return { idToken, email: response.data?.user?.email ?? null };
}

/**
 * Clears the native Google session so the account picker shows next time.
 * Safe to call when Google Sign-In is unavailable or not configured.
 * @returns {Promise<void>}
 */
export async function signOutGoogle() {
  if (!isGoogleSignInConfigured()) return;
  try {
    const { GoogleSignin } = loadGoogleSignin();
    await GoogleSignin.signOut();
  } catch {
    // Best effort — never block Custodicore logout.
  }
}

export default signInWithGoogle;
