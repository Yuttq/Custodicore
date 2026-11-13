import AsyncStorage from '@react-native-async-storage/async-storage';
import { LEGACY_LOCAL_PROFILE_KEY, localProfileKey } from '../utils/localProfileKey';

/**
 * The old phone-wide entry cannot be traced to an account, so it is removed
 * (once per app run is enough) rather than shown to whoever logs in.
 */
let legacyRemoved = false;
async function removeLegacyProfile() {
  if (legacyRemoved) return;
  legacyRemoved = true;
  try {
    await AsyncStorage.removeItem(LEGACY_LOCAL_PROFILE_KEY);
  } catch {
    legacyRemoved = false;
  }
}

/**
 * @param {unknown} obj
 * @returns {Partial<{ fullName: string; email: string; phone: string; registrationStatus: string; photoUri: string | null }>}
 */
function pickStoredFields(obj) {
  if (!obj || typeof obj !== 'object') return {};
  const o = /** @type {Record<string, unknown>} */ (obj);
  return {
    ...(typeof o.fullName === 'string' ? { fullName: o.fullName } : {}),
    ...(typeof o.email === 'string' ? { email: o.email } : {}),
    ...(typeof o.phone === 'string' ? { phone: o.phone } : {}),
    ...(typeof o.address === 'string' ? { address: o.address } : {}),
    ...(typeof o.registrationStatus === 'string' ? { registrationStatus: o.registrationStatus } : {}),
    ...(typeof o.relationshipToPdl === 'string' ? { relationshipToPdl: o.relationshipToPdl } : {}),
    ...(typeof o.photoUri === 'string' ? { photoUri: o.photoUri } : {}),
    ...(o.photoUri === null ? { photoUri: null } : {}),
  };
}

/**
 * Loads the signed-in account's profile fields from device storage.
 * TODO: Connect to backend/database in production — replace with GET /me (or session) and merge into app state.
 *
 * @template T
 * @param {T} defaultProfile — fallback when nothing stored, no account, or parse fails
 * @param {string | number | null | undefined} accountId — useAuth().user.id
 * @returns {Promise<T & Record<string, unknown>>}
 */
export async function loadLocalProfile(defaultProfile, accountId) {
  await removeLegacyProfile();
  const key = localProfileKey(accountId);
  if (!key) return { ...defaultProfile };
  try {
    const raw = await AsyncStorage.getItem(key);
    if (!raw) return { ...defaultProfile };
    const parsed = JSON.parse(raw);
    return { ...defaultProfile, ...pickStoredFields(parsed) };
  } catch {
    return { ...defaultProfile };
  }
}

/**
 * Persists the signed-in account's profile fields locally. Kept after
 * logout, so the account finds them again on its next login.
 * TODO: Connect to backend/database in production — PATCH /me (or equivalent), then mirror to cache if needed.
 *
 * @param {{ fullName?: string; email?: string; phone?: string; address?: string; registrationStatus?: string; relationshipToPdl?: string; photoUri?: string | null }} profile
 * @param {string | number | null | undefined} accountId — useAuth().user.id
 * @throws {Error} when no account is signed in
 */
export async function persistLocalProfile(profile, accountId) {
  const key = localProfileKey(accountId);
  if (!key) throw new Error('No signed-in account to save the profile for.');
  const payload = JSON.stringify({
    fullName: profile.fullName,
    email: profile.email,
    phone: profile.phone,
    address: profile.address ?? '',
    registrationStatus: profile.registrationStatus,
    relationshipToPdl: profile.relationshipToPdl ?? '',
    photoUri: typeof profile.photoUri === 'string' ? profile.photoUri : null,
  });
  await AsyncStorage.setItem(key, payload);
}
