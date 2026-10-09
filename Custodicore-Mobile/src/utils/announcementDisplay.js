/**
 * Display helpers for Home announcements (GET /api/announcements).
 *
 * Pure functions only (no React / React Native imports) so they can be unit
 * tested with `node --test`.
 */

import { MONTH_NAMES, manilaTodayIso, parseIsoDate } from './scheduleAvailability.js';

/**
 * ISO-8601 date-time with an explicit offset, as the API sends `createdAt`
 * (Carbon toIso8601String: "2026-10-07T00:00:00+08:00"). Other strings are
 * rejected rather than left to engine-specific Date.parse leniency.
 */
const ISO_DATE_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/;

/**
 * "2026-10-07T00:00:00+08:00" → "Oct 7, 2026", read as a facility (Manila)
 * date whatever offset the timestamp carries; null when missing or invalid.
 * @param {unknown} createdAt
 * @returns {string | null}
 */
export function formatAnnouncementDate(createdAt) {
  if (typeof createdAt !== 'string' || !ISO_DATE_TIME.test(createdAt.trim())) return null;
  const time = Date.parse(createdAt.trim());
  if (Number.isNaN(time)) return null;
  const p = parseIsoDate(manilaTodayIso(new Date(time)));
  if (!p) return null;
  return `${MONTH_NAMES[p.month - 1].slice(0, 3)} ${p.day}, ${p.year}`;
}

/**
 * "schedule" → "Schedule", "visit_rules" → "Visit Rules"; null when empty.
 * @param {unknown} category
 * @returns {string | null}
 */
export function announcementCategoryLabel(category) {
  if (typeof category !== 'string') return null;
  const words = category.trim().split(/[\s_-]+/).filter(Boolean);
  if (words.length === 0) return null;
  return words.map((w) => w[0].toUpperCase() + w.slice(1).toLowerCase()).join(' ');
}
