/**
 * Device-storage keys for the visitor's local profile (the profile photo).
 * Pure — no React Native imports — so it runs under `node --test`.
 *
 * The photo is kept per account: on a shared phone, one visitor never sees
 * another's photo. It is not removed on logout, so it is still there when
 * the same account logs in again.
 */

/** The old key, shared by every account on the phone. Removed, never read. */
export const LEGACY_LOCAL_PROFILE_KEY = '@custodicore/visitor_profile_local';

/**
 * The storage key for one account, or null when no account is signed in
 * (nothing is then read or saved).
 * @param {string | number | null | undefined} accountId — useAuth().user.id
 * @returns {string | null}
 */
export function localProfileKey(accountId) {
  const id = accountId == null ? '' : String(accountId).trim();
  return id ? `${LEGACY_LOCAL_PROFILE_KEY}:${id}` : null;
}
