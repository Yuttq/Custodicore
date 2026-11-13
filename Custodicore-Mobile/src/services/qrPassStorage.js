import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import {
  buildQrPassCacheEntry,
  parseQrPassCacheEntry,
  shouldKeepQrPassCache,
} from '../utils/qrPassCache';

/**
 * Today's QR pass saved in SecureStore (encrypted, this device only, not in
 * backups) so the visitor can open it without signal. Display only: the
 * Front Desk still verifies every scan online. Rules in utils/qrPassCache.js.
 *
 * One pass per device, tagged with its account: another account never reads
 * it, and logout deletes it. SecureStore is not available on web, where
 * nothing is saved. Storage failures are swallowed: the pass then simply
 * is not available offline.
 */

const KEY = 'custodicore.qrPass';

const OPTIONS = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };

const supported = Platform.OS !== 'web';

/**
 * Saves the pass the screen is showing.
 * @param {{ accountId: unknown; visitId: unknown; pass: Record<string, unknown> }} input
 * @returns {Promise<object | null>} the saved entry
 */
export async function saveQrPass(input) {
  if (!supported) return null;
  const entry = buildQrPassCacheEntry(input);
  if (!entry) return null;
  try {
    await SecureStore.setItemAsync(KEY, JSON.stringify(entry), OPTIONS);
    return entry;
  } catch {
    return null;
  }
}

/**
 * The saved pass for this account, or null. A pass past its keep rule
 * (visit day over and not inside) is deleted instead of returned.
 * @param {unknown} accountId
 */
export async function loadQrPass(accountId) {
  if (!supported) return null;
  try {
    const entry = parseQrPassCacheEntry(await SecureStore.getItemAsync(KEY, OPTIONS), accountId);
    if (entry && shouldKeepQrPassCache(entry)) return entry;
    if (entry) await clearQrPass();
    return null;
  } catch {
    return null;
  }
}

/** Deletes the saved pass (final check-out, no pass on the server, logout). */
export async function clearQrPass() {
  if (!supported) return;
  try {
    await SecureStore.deleteItemAsync(KEY, OPTIONS);
  } catch {
    // Nothing saved, or storage unavailable.
  }
}
