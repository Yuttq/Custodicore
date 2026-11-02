/**
 * Checks the visitor API base URL the build was configured with.
 *
 * Pure functions only (no React / React Native imports) so they can be unit
 * tested with `node --test`. `src/services/api.js` passes in the
 * EXPO_PUBLIC_* values and whether this is a release build.
 *
 * Release builds (EAS preview / production APKs) must have an https URL.
 * Development builds also accept http, so a LAN address such as
 * `http://192.168.1.10:8000/api` or the emulator's `http://10.0.2.2:8000/api`
 * keeps working.
 */

export const SERVER_NOT_CONFIGURED_CODE = 'server_not_configured';

export const SERVER_NOT_CONFIGURED_MESSAGE =
  'Server not configured. This copy of the CustodiCore app has not been set up with a server address yet, so it cannot connect. Please install an updated version of the app or contact the facility.';

/** scheme://host[:port][/path] — no spaces, query string or fragment. */
const URL_PATTERN = /^(https?):\/\/([^/?#\s:@]+)(:\d{1,5})?(\/[^\s?#]*)?$/i;

/**
 * @param {unknown} value
 * @returns {string} the trimmed value, or '' when unset
 */
function clean(value) {
  if (typeof value !== 'string') return '';
  const trimmed = value.trim();
  // A shell or CI can turn an unset variable into the literal text.
  return trimmed === 'undefined' || trimmed === 'null' ? '' : trimmed;
}

/**
 * Picks and validates the API base URL.
 * @param {{ nativeUrl?: unknown; webUrl?: unknown; platform?: string; isRelease: boolean }} options
 *   nativeUrl = EXPO_PUBLIC_API_URL, webUrl = EXPO_PUBLIC_WEB_API_URL.
 * @returns {{ baseUrl: string | null; problem: 'missing' | 'invalid' | 'insecure' | null }}
 *   baseUrl has no trailing slash; it is null whenever problem is set.
 */
export function resolveApiBaseUrl({ nativeUrl, webUrl, platform, isRelease }) {
  const native = clean(nativeUrl);
  // Web (browser on the same PC): EXPO_PUBLIC_WEB_API_URL, else the native URL
  // with the Android-emulator-only host 10.0.2.2 swapped for 127.0.0.1.
  const url =
    platform === 'web'
      ? clean(webUrl) || native.replace('://10.0.2.2', '://127.0.0.1')
      : native;

  if (!url) return { baseUrl: null, problem: 'missing' };

  const match = URL_PATTERN.exec(url);
  if (!match) return { baseUrl: null, problem: 'invalid' };

  // The old built-in fallback address; it never pointed at a real server.
  if (match[2].toLowerCase().endsWith('.placeholder')) {
    return { baseUrl: null, problem: 'invalid' };
  }

  if (isRelease && match[1].toLowerCase() !== 'https') {
    return { baseUrl: null, problem: 'insecure' };
  }

  return { baseUrl: url.replace(/\/+$/, ''), problem: null };
}

/**
 * The Google Web OAuth client ID from EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID, or ''
 * when it is not set (the Google button then stays hidden).
 * @param {unknown} value
 * @returns {string}
 */
export function normalizeGoogleClientId(value) {
  return clean(value);
}
