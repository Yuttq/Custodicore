/**
 * Authentication configuration — client IDs and feature flags.
 * Set via Expo public env vars (no secrets in the mobile app except client IDs).
 */

export const authConfig = {
  google: {
    expoClientId: process.env.EXPO_PUBLIC_GOOGLE_EXPO_CLIENT_ID ?? '',
    iosClientId: process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID ?? '',
    androidClientId: process.env.EXPO_PUBLIC_GOOGLE_ANDROID_CLIENT_ID ?? '',
    webClientId: process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID ?? '',
  },
};

/**
 * True when at least one Google OAuth client ID is configured for this build.
 */
export function isGoogleSignInConfigured() {
  const { expoClientId, iosClientId, androidClientId, webClientId } = authConfig.google;
  return Boolean(
    String(expoClientId).trim() ||
      String(iosClientId).trim() ||
      String(androidClientId).trim() ||
      String(webClientId).trim(),
  );
}

export default authConfig;
