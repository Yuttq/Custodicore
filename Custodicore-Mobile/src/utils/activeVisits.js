/**
 * Active-visit selection for Home ("next visit") and the QR Pass tab.
 *
 * Pure functions only (no React / React Native imports) so they can be unit
 * tested with `node --test`. `scheduledAt` comes from the backend as a Manila
 * local timestamp ("YYYY-MM-DDTHH:MM:SS+08:00"), so its first 10 characters
 * are the facility's calendar date and compare directly with `manilaTodayIso`.
 */

import { manilaTodayIso } from './scheduleAvailability.js';

/** Visit statuses that still lead to a future visit. */
const UPCOMING_STATUSES = ['assigned', 'pending_confirmation', 'confirmed'];

/**
 * @param {{ scheduledAt?: unknown }} visit
 * @returns {string | null} "YYYY-MM-DD" (Manila) or null
 */
export function scheduledDateIso(visit) {
  const at = visit?.scheduledAt;
  if (typeof at !== 'string' || !/^\d{4}-\d{2}-\d{2}/.test(at)) return null;
  return at.slice(0, 10);
}

/**
 * Today and future visits count; a visit earlier today still counts until the
 * Manila date rolls over (the backend expiry job closes it after that).
 * @param {{ status?: string; scheduledAt?: unknown }} visit
 * @param {string} today Manila "YYYY-MM-DD"
 */
export function isUpcomingVisit(visit, today) {
  if (!visit || !UPCOMING_STATUSES.includes(visit.status)) return false;
  const date = scheduledDateIso(visit);
  return date !== null && date >= today;
}

/**
 * @template {{ scheduledAt?: unknown }} T
 * @param {T[]} visits
 * @returns {T | null}
 */
function earliest(visits) {
  if (visits.length === 0) return null;
  const time = (v) => {
    const t = Date.parse(/** @type {string} */ (v.scheduledAt));
    return Number.isNaN(t) ? Number.POSITIVE_INFINITY : t;
  };
  return [...visits].sort((a, b) => time(a) - time(b))[0];
}

/**
 * The visit Home shows as "next": earliest assigned / pending / confirmed
 * visit dated today or later.
 * @template {{ status?: string; scheduledAt?: unknown }} T
 * @param {T[] | null | undefined} visits
 * @param {Date} [now]
 * @returns {T | null}
 */
export function pickNextVisit(visits, now = new Date()) {
  if (!Array.isArray(visits)) return null;
  const today = manilaTodayIso(now);
  return earliest(visits.filter((v) => isUpcomingVisit(v, today)));
}

/**
 * Time line for a visit card. A whole-day visit (morning + afternoon
 * sessions) is one visit: "09:00 - 16:30 · Whole day".
 * @param {{ timeLabel?: string | null; isWholeDay?: boolean } | null | undefined} visit
 * @returns {string}
 */
export function visitTimeText(visit) {
  const time = typeof visit?.timeLabel === 'string' ? visit.timeLabel : '';
  if (!visit?.isWholeDay) return time;
  return time ? `${time} · Whole day` : 'Whole day';
}

/**
 * "Morning 09:00 - 11:30 · Afternoon 13:00 - 16:30", or null for a
 * single-session visit (its time line already says it all).
 * @param {{ period?: string; timeLabel?: string }[] | null | undefined} sessions
 * @returns {string | null}
 */
export function visitSessionsText(sessions) {
  if (!Array.isArray(sessions) || sessions.length < 2) return null;
  return sessions
    .map((s) => {
      const name = s?.period === 'morning' ? 'Morning' : s?.period === 'afternoon' ? 'Afternoon' : 'Session';
      return s?.timeLabel ? `${name} ${s.timeLabel}` : name;
    })
    .join(' · ');
}

/**
 * The visit the QR Pass tab opens automatically: the nearest confirmed visit
 * dated today or later. Never falls back to another visit.
 * @template {{ status?: string; scheduledAt?: unknown }} T
 * @param {T[] | null | undefined} visits
 * @param {Date} [now]
 * @returns {T | null}
 */
export function pickQrVisit(visits, now = new Date()) {
  if (!Array.isArray(visits)) return null;
  const today = manilaTodayIso(now);
  return earliest(
    visits.filter((v) => v?.status === 'confirmed' && isUpcomingVisit(v, today)),
  );
}

/**
 * Whether the QR Pass tab may show the visit's pass: only on the visit's
 * Manila calendar date. Display only — the gate enforces the date itself.
 * @param {{ scheduledAt?: unknown }} visit
 * @param {Date} [now]
 */
export function isQrPassDay(visit, now = new Date()) {
  const date = scheduledDateIso(visit);
  return date !== null && date === manilaTodayIso(now);
}

/**
 * "16:30" from a pass's `expiresAt` — a Manila local timestamp from the
 * backend, the end of the visit's last session — whatever the phone's
 * timezone. Null when missing.
 * @param {unknown} expiresAt
 * @returns {string | null}
 */
export function passValidUntilTime(expiresAt) {
  const m = typeof expiresAt === 'string' ? /T(\d{2}:\d{2})/.exec(expiresAt) : null;
  return m ? m[1] : null;
}

/**
 * Whether the pass is past its `expiresAt` (display only; the gate decides).
 * @param {unknown} expiresAt
 * @param {Date} [now]
 */
export function isPassExpired(expiresAt, now = new Date()) {
  const t = typeof expiresAt === 'string' ? Date.parse(expiresAt) : Number.NaN;
  return !Number.isNaN(t) && now.getTime() >= t;
}

/**
 * The QR Pass tab's visit (see pickQrVisit) and whether its pass is shown
 * today; a later confirmed visit is shown without its pass.
 * @template {{ status?: string; scheduledAt?: unknown }} T
 * @param {T[] | null | undefined} visits
 * @param {Date} [now]
 * @returns {{ visit: T; passDay: boolean } | null}
 */
export function qrPassSelection(visits, now = new Date()) {
  const visit = pickQrVisit(visits, now);
  return visit ? { visit, passDay: isQrPassDay(visit, now) } : null;
}
