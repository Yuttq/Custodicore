import { authConfig, isGoogleSignInConfigured } from '../config/authConfig';

/**
 * Web (Expo Web / browser) variant of `./googleAuthService.js`, built on
 * Google Identity Services (GIS). Metro picks this file for `platform: web`
 * only, so the native @react-native-google-signin module is never loaded in
 * the browser (its web build is a stub that throws).
 *
 * Flow: `GoogleSignInButton.web.js` renders Google's official button via
 * `renderGoogleButton()`. When the visitor picks an account, GIS hands this
 * module an ID token and the button calls its `onPress` — the same handler
 * the native button uses — which reaches `signInWithGoogle()` through
 * `authenticateWithGoogle()`. `signInWithGoogle()` returns that token, so the
 * shared POST /auth/google handling is unchanged.
 *
 * Tokens are kept in memory only, never logged or persisted.
 */

const GIS_SCRIPT_URL = 'https://accounts.google.com/gsi/client';

/** A held token this close to expiry is not reused for registration. */
const TOKEN_EXPIRY_MARGIN_MS = 60 * 1000;

/**
 * Thrown when Google Sign-In cannot run in this browser build
 * (EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID missing).
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
 * Native-only (Play Services); exported so shared imports resolve on web.
 */
export class GooglePlayServicesUnavailableError extends Error {
  constructor() {
    super(
      'Google Play Services is not available or needs an update on this device. Please sign in with your email and password.',
    );
    this.name = 'GooglePlayServicesUnavailableError';
  }
}

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

/** @type {Promise<any> | null} */
let gisPromise = null;
let initialized = false;
/** @type {(() => void) | null} — the mounted button's handler */
let credentialListener = null;
/** Token from the button's latest pick, consumed once by signInWithGoogle(). */
let pendingIdToken = null;
/** Token returned by the last signInWithGoogle(), reused by refreshGoogleIdToken(). */
let lastIdToken = null;

/**
 * Loads the GIS script once. A failed load is not cached, so a later mount
 * (e.g. after reconnecting) retries.
 * @returns {Promise<any>} `window.google.accounts.id`
 */
function loadGis() {
  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return Promise.reject(new GoogleSignInNotConfiguredError());
  }
  if (window.google?.accounts?.id) {
    return Promise.resolve(window.google.accounts.id);
  }
  if (!gisPromise) {
    gisPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = GIS_SCRIPT_URL;
      script.async = true;
      script.defer = true;
      const fail = () => {
        gisPromise = null;
        script.remove();
        reject(new Error('Unable to reach Google. Check your internet connection and try again.'));
      };
      script.onload = () => {
        if (window.google?.accounts?.id) {
          resolve(window.google.accounts.id);
        } else {
          fail();
        }
      };
      script.onerror = fail;
      document.head.appendChild(script);
    });
  }
  return gisPromise;
}

/** GIS credential callback: hold the ID token and notify the mounted button. */
function handleCredentialResponse(response) {
  const idToken = response?.credential;
  if (typeof idToken !== 'string' || !idToken) return;
  if (!credentialListener) return;
  pendingIdToken = idToken;
  credentialListener();
}

/**
 * Reads the unverified claims of an ID token for display and expiry checks
 * only — the backend verifies the token itself.
 * @returns {{ email?: string, exp?: number } | null}
 */
function readClaims(idToken) {
  try {
    const part = String(idToken).split('.')[1] ?? '';
    const base64 = part.replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64.padEnd(Math.ceil(base64.length / 4) * 4, '=');
    return JSON.parse(window.atob(padded));
  } catch {
    return null;
  }
}

function isFresh(idToken) {
  const exp = readClaims(idToken)?.exp;
  return typeof exp === 'number' && exp * 1000 - TOKEN_EXPIRY_MARGIN_MS > Date.now();
}

/**
 * Renders Google's official "Continue with Google" button into `element`.
 * `onCredential` runs after the visitor picks an account; it should start the
 * normal sign-in (which calls signInWithGoogle()).
 *
 * @param {HTMLElement} element
 * @param {{ width: number }} options — button width in px (GIS allows 200–400)
 * @param {() => void} onCredential
 * @returns {Promise<() => void>} detaches `onCredential`
 */
export async function renderGoogleButton(element, { width }, onCredential) {
  if (!isGoogleSignInConfigured()) {
    throw new GoogleSignInNotConfiguredError();
  }
  const gis = await loadGis();
  if (!initialized) {
    // ID token only: no OAuth scopes, access token, or client secret.
    gis.initialize({
      client_id: authConfig.google.webClientId,
      callback: handleCredentialResponse,
      auto_select: false,
    });
    initialized = true;
  }
  credentialListener = onCredential;
  element.replaceChildren();
  gis.renderButton(element, {
    type: 'standard',
    theme: 'outline',
    size: 'large',
    text: 'continue_with',
    shape: 'rectangular',
    logo_alignment: 'left',
    width,
  });
  return () => {
    if (credentialListener === onCredential) {
      credentialListener = null;
      pendingIdToken = null;
    }
  };
}

/**
 * Returns the ID token GIS delivered for the account the visitor just picked
 * in Google's button, for the backend `POST /auth/google` exchange, plus its
 * email for display only. With no pick pending it reports a cancellation,
 * which callers treat silently.
 *
 * @returns {Promise<{ idToken: string, email: string|null }>}
 */
export async function signInWithGoogle() {
  if (!isGoogleSignInConfigured()) {
    throw new GoogleSignInNotConfiguredError();
  }
  const idToken = pendingIdToken;
  pendingIdToken = null;
  if (!idToken) {
    throw new GoogleSignInCancelledError();
  }
  lastIdToken = idToken;
  return { idToken, email: readClaims(idToken)?.email ?? null };
}

/**
 * Browsers cannot silently mint a new Google ID token, so this returns the
 * token from the last sign-in while it is still comfortably valid (Google ID
 * tokens last about an hour). Otherwise the visitor must sign in with Google
 * again. Never returns an expired token.
 *
 * @returns {Promise<{ idToken: string, email: string|null }>}
 * @throws {GoogleSessionExpiredError} when no fresh token is available
 */
export async function refreshGoogleIdToken() {
  const idToken = lastIdToken;
  if (!idToken || !isFresh(idToken)) {
    lastIdToken = null;
    throw new GoogleSessionExpiredError();
  }
  return { idToken, email: readClaims(idToken)?.email ?? null };
}

/**
 * Forgets held Google tokens and stops GIS from auto-selecting the account.
 * Safe to call when Google Sign-In is unavailable or not configured.
 * @returns {Promise<void>}
 */
export async function signOutGoogle() {
  pendingIdToken = null;
  lastIdToken = null;
  try {
    if (typeof window !== 'undefined') {
      window.google?.accounts?.id?.disableAutoSelect?.();
    }
  } catch {
    // Best effort — never block Custodicore logout.
  }
}

export default signInWithGoogle;
