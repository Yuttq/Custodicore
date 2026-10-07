import * as Linking from 'expo-linking';

/**
 * True for the deep link the backend's "email verified" page opens:
 * `custodicore://login?emailVerified=1`. It carries no token or account data
 * — only that verification happened, so the app can show Login with a
 * confirmation. The visitor still signs in normally.
 * @param {string | null | undefined} url
 */
export function isEmailVerifiedLink(url) {
  if (!url) return false;
  try {
    const { hostname, path, queryParams } = Linking.parse(url);
    const target = hostname || path;
    return target === 'login' && queryParams?.emailVerified === '1';
  } catch {
    return false;
  }
}
