import AsyncStorage from '@react-native-async-storage/async-storage';

const STORAGE_KEY = '@custodicore/visitor_profile_local';

/**
 * @param {unknown} obj
 * @returns {Partial<{ fullName: string; email: string; phone: string; registrationStatus: string }>}
 */
function pickStoredFields(obj) {
  if (!obj || typeof obj !== 'object') return {};
  const o = /** @type {Record<string, unknown>} */ (obj);
  return {
    ...(typeof o.fullName === 'string' ? { fullName: o.fullName } : {}),
    ...(typeof o.email === 'string' ? { email: o.email } : {}),
    ...(typeof o.phone === 'string' ? { phone: o.phone } : {}),
    ...(typeof o.registrationStatus === 'string' ? { registrationStatus: o.registrationStatus } : {}),
  };
}

/**
 * Loads persisted visitor profile fields from device storage.
 * TODO: Connect to backend/database in production — replace with GET /me (or session) and merge into app state.
 *
 * @template T
 * @param {T} defaultProfile — fallback when nothing stored or parse fails
 * @returns {Promise<T & Record<string, unknown>>}
 */
export async function loadLocalProfile(defaultProfile) {
  try {
    const raw = await AsyncStorage.getItem(STORAGE_KEY);
    if (!raw) return { ...defaultProfile };
    const parsed = JSON.parse(raw);
    return { ...defaultProfile, ...pickStoredFields(parsed) };
  } catch {
    return { ...defaultProfile };
  }
}

/**
 * Persists editable profile fields locally.
 * TODO: Connect to backend/database in production — PATCH /me (or equivalent), then mirror to cache if needed.
 *
 * @param {{ fullName: string; email: string; phone: string; registrationStatus: string }} profile
 */
export async function persistLocalProfile(profile) {
  const payload = JSON.stringify({
    fullName: profile.fullName,
    email: profile.email,
    phone: profile.phone,
    registrationStatus: profile.registrationStatus,
  });
  await AsyncStorage.setItem(STORAGE_KEY, payload);
}
