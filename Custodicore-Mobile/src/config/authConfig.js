import { Platform, TurboModuleRegistry } from 'react-native';

/**
 * Authentication configuration — client IDs and feature flags.
 * Set via Expo public env vars (no secrets in the mobile app except client IDs).
 *
 * `webClientId` is the Google Cloud **Web** OAuth client ID. Native Google
 * Sign-In requests ID tokens for this audience, so the backend's
 * GOOGLE_CLIENT_IDS must contain the same value.
 */
export const authConfig = {
  google: {
    webClientId: String(process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID ?? '').trim(),
  },
};

/** Native module name registered by @react-native-google-signin/google-signin. */
const GOOGLE_SIGNIN_NATIVE_MODULE = 'RNGoogleSignin';

/**
 * True when the Google Sign-In native module is compiled into this binary.
 * False in Expo Go. Uses `get` (returns null) rather than `getEnforcing` (throws).
 */
export function isGoogleSignInNativeModuleAvailable() {
  try {
    return TurboModuleRegistry.get(GOOGLE_SIGNIN_NATIVE_MODULE) != null;
  } catch {
    return false;
  }
}

/**
 * True when native Google Sign-In can actually run on this device/build:
 * Android, a Web Client ID is set, and the native module is present
 * (development/production build — not Expo Go).
 */
export function isGoogleSignInConfigured() {
  return (
    Platform.OS === 'android' &&
    Boolean(authConfig.google.webClientId) &&
    isGoogleSignInNativeModuleAvailable()
  );
}

export default authConfig;
