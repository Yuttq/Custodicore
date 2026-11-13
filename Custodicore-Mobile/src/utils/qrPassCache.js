/**
 * Offline copy of the visitor's QR pass: the rules for what is saved, when
 * it is kept and when it is deleted. Storage itself is in
 * `services/qrPassStorage.js` (SecureStore).
 *
 * The cached pass is for DISPLAY only, so the visitor can still open it
 * without signal. It admits nobody by itself: the Front Desk verifies every
 * scan online against the server.
 *
 * Pure functions only (no React Native imports) so they can be unit tested
 * with `node --test`. Dates are compared on the Manila calendar.
 */

import { manilaTodayIso } from './scheduleAvailability.js';

export const QR_PASS_CACHE_VERSION = 1;

/** The display fields of a normalized pass that are saved. */
const PASS_FIELDS = [
  'qrToken',
  'expiresAt',
  'checkedIn',
  'scheduledAt',
  'pdlName',
  'dateDisplay',
  'timeLabel',
  'sessionsText',
];

/**
 * @param {unknown} scheduledAt Manila local timestamp ("YYYY-MM-DDTHH:MM:SS+08:00")
 * @returns {string | null} "YYYY-MM-DD"
 */
function visitDateOf(scheduledAt) {
  return typeof scheduledAt === 'string' && /^\d{4}-\d{2}-\d{2}/.test(scheduledAt)
    ? scheduledAt.slice(0, 10)
    : null;
}

/**
 * The entry to save for a pass the screen is showing, or null when it
 * cannot be saved (no signed-in account, no visit, no token, no date).
 * @param {{ accountId: unknown; visitId: unknown; pass: Record<string, unknown> | null | undefined; now?: Date }} input
 */
export function buildQrPassCacheEntry({ accountId, visitId, pass, now = new Date() }) {
  const account = accountId == null ? '' : String(accountId).trim();
  const visit = visitId == null ? '' : String(visitId).trim();
  const visitDate = visitDateOf(pass?.scheduledAt);
  if (!account || !visit || !visitDate || typeof pass?.qrToken !== 'string' || !pass.qrToken) {
    return null;
  }

  const saved = {};
  for (const field of PASS_FIELDS) {
    saved[field] = pass[field] ?? null;
  }
  saved.checkedIn = pass.checkedIn === true;

  return {
    v: QR_PASS_CACHE_VERSION,
    accountId: account,
    visitId: visit,
    visitDate,
    checkedIn: saved.checkedIn,
    savedAt: now.toISOString(),
    pass: saved,
  };
}

/**
 * A saved entry read back from storage, or null when it is missing,
 * unreadable, from another version or belongs to another account.
 * @param {string | null | undefined} json
 * @param {unknown} accountId the signed-in account
 */
export function parseQrPassCacheEntry(json, accountId) {
  if (typeof json !== 'string' || !json) return null;
  let entry;
  try {
    entry = JSON.parse(json);
  } catch {
    return null;
  }
  const account = accountId == null ? '' : String(accountId).trim();
  if (
    !entry ||
    typeof entry !== 'object' ||
    entry.v !== QR_PASS_CACHE_VERSION ||
    !account ||
    entry.accountId !== account ||
    typeof entry.visitId !== 'string' ||
    typeof entry.visitDate !== 'string' ||
    typeof entry.pass?.qrToken !== 'string' ||
    !entry.pass.qrToken
  ) {
    return null;
  }
  return entry;
}

/**
 * Whether a saved pass is still kept: while the visitor is inside (even
 * overnight, until the server says they checked out), otherwise only up to
 * the end of the visit day (Manila).
 * @param {{ checkedIn?: boolean; visitDate?: string } | null | undefined} entry
 * @param {Date} [now]
 */
export function shouldKeepQrPassCache(entry, now = new Date()) {
  if (!entry) return false;
  if (entry.checkedIn === true) return true;
  return typeof entry.visitDate === 'string' && entry.visitDate >= manilaTodayIso(now);
}

/**
 * The server answered and there is no pass for this visit (409: not
 * confirmed, completed, cancelled, used, ended; 404: not the visitor's).
 * The saved pass for that visit is then deleted.
 * @param {{ status?: number } | null | undefined} error
 */
export function isDefinitiveNoPass(error) {
  return error?.status === 409 || error?.status === 404;
}

/**
 * The server could not be reached or could not answer (no response, timeout,
 * 5xx): the saved pass is shown instead, marked offline.
 * @param {{ status?: number } | null | undefined} error
 */
export function isServerUnreachable(error) {
  const status = error?.status;
  return status == null || status >= 500;
}

/**
 * "09:12" when saved today (Manila), else "Nov 12, 09:12" — whatever the
 * phone's timezone. Null for a missing or bad timestamp.
 * @param {unknown} savedAt ISO timestamp
 * @param {Date} [now]
 */
export function formatLastUpdated(savedAt, now = new Date()) {
  const t = typeof savedAt === 'string' ? Date.parse(savedAt) : Number.NaN;
  if (Number.isNaN(t)) return null;
  const manila = new Date(t + 8 * 60 * 60 * 1000);
  const hh = String(manila.getUTCHours()).padStart(2, '0');
  const mm = String(manila.getUTCMinutes()).padStart(2, '0');
  const savedDay = manila.toISOString().slice(0, 10);
  if (savedDay === manilaTodayIso(now)) return `${hh}:${mm}`;
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return `${months[manila.getUTCMonth()]} ${manila.getUTCDate()}, ${hh}:${mm}`;
}
