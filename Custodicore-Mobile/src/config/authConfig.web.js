/**
 * Web (Expo Web / browser) variant of `./authConfig.js` — Metro picks this
 * file for `platform: web` only; Android and iOS keep the native file.
 *
 * `webClientId` is the same Google Cloud **Web** OAuth client ID the native
 * build uses. Google Identity Services issues ID tokens for this audience in
 * the browser, so the backend's GOOGLE_CLIENT_IDS already accepts them. The
 * page's origin must be listed under the client's Authorized JavaScript origins.
 */
export const authConfig = {
  google: {
    webClientId: String(process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID ?? '').trim(),
  },
};

/** The native Google Sign-In module never exists in a browser. */
export function isGoogleSignInNativeModuleAvailable() {
  return false;
}

/**
 * True when browser Google Sign-In can run: a Web Client ID is set. Google
 * Identity Services is loaded on demand by `googleAuthService.web.js`.
 */
export function isGoogleSignInConfigured() {
  return Boolean(authConfig.google.webClientId);
}

export default authConfig;
