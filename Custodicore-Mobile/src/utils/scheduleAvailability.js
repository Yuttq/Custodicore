/**
 * Visit-slot availability helpers for the Home calendar (Phase 3).
 *
 * Pure functions only (no React / React Native imports) so they can be unit
 * tested with `node --test`. Dates are always "YYYY-MM-DD" strings in the
 * facility's timezone (Asia/Manila, as returned by the backend) and all date
 * math is done in UTC, so the device's own timezone never shifts a day.
 */

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
export const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];
export const WEEKDAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** Backend `reason` codes (ScheduleAvailabilityService) → visitor-facing text. */
export const SLOT_REASON_LABELS = {
  past: 'Past',
  ended: 'Past',
  not_eligible: 'Not available for your schedule',
  not_in_effect: 'Not available for your schedule',
  pdl_unavailable: 'Not available for this PDL',
  closed: 'Closed',
  already_scheduled: 'Already scheduled',
  full: 'Full',
  weekly_limit: 'Weekly limit reached',
};

/**
 * @param {string | null | undefined} reason
 */
export function slotReasonLabel(reason) {
  return (reason && SLOT_REASON_LABELS[reason]) || 'Unavailable';
}

/**
 * @param {unknown} iso
 * @returns {{ year: number; month: number; day: number } | null} month is 1-12
 */
export function parseIsoDate(iso) {
  if (typeof iso !== 'string') return null;
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!m) return null;
  const year = Number(m[1]);
  const month = Number(m[2]);
  const day = Number(m[3]);
  const d = new Date(Date.UTC(year, month - 1, day));
  if (d.getUTCFullYear() !== year || d.getUTCMonth() !== month - 1 || d.getUTCDate() !== day) {
    return null;
  }
  return { year, month, day };
}

/**
 * @param {number} year
 * @param {number} month 1-12
 * @param {number} day
 */
export function toIsoDate(year, month, day) {
  const d = new Date(Date.UTC(year, month - 1, day));
  const y = String(d.getUTCFullYear()).padStart(4, '0');
  const mo = String(d.getUTCMonth() + 1).padStart(2, '0');
  const da = String(d.getUTCDate()).padStart(2, '0');
  return `${y}-${mo}-${da}`;
}

/**
 * @param {string} iso
 * @param {number} days
 */
export function addDaysIso(iso, days) {
  const p = parseIsoDate(iso);
  if (!p) return iso;
  return toIsoDate(p.year, p.month, p.day + days);
}

/** 0 = Sunday … 6 = Saturday */
export function dayOfWeekIso(iso) {
  const p = parseIsoDate(iso);
  if (!p) return 0;
  return new Date(Date.UTC(p.year, p.month - 1, p.day)).getUTCDay();
}

/**
 * Today's date in Asia/Manila (UTC+8, no DST) — used only before the
 * backend has answered with its own `today`.
 * @param {Date} [now]
 */
export function manilaTodayIso(now = new Date()) {
  const manila = new Date(now.getTime() + 8 * 60 * 60 * 1000);
  return toIsoDate(manila.getUTCFullYear(), manila.getUTCMonth() + 1, manila.getUTCDate());
}

/**
 * Sunday-first month grid. Each week has 7 cells; padding cells are null.
 * @param {number} year
 * @param {number} month 1-12
 * @returns {(string | null)[][]}
 */
export function buildMonthGrid(year, month) {
  const first = new Date(Date.UTC(year, month - 1, 1));
  const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
  const cells = [];
  for (let i = 0; i < first.getUTCDay(); i += 1) cells.push(null);
  for (let d = 1; d <= daysInMonth; d += 1) cells.push(toIsoDate(year, month, d));
  while (cells.length % 7 !== 0) cells.push(null);

  const weeks = [];
  for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));
  return weeks;
}

/**
 * @param {number} year
 * @param {number} month 1-12
 * @param {number} delta
 */
export function shiftMonth(year, month, delta) {
  const index = year * 12 + (month - 1) + delta;
  return { year: Math.floor(index / 12), month: (index % 12) + 1 };
}

/** "13:00" → "1:00 PM" */
export function formatTime12(hhmm) {
  const m = /^(\d{1,2}):(\d{2})/.exec(String(hhmm ?? ''));
  if (!m) return String(hhmm ?? '');
  const h = Number(m[1]);
  const suffix = h < 12 ? 'AM' : 'PM';
  const h12 = h % 12 === 0 ? 12 : h % 12;
  return `${h12}:${m[2]} ${suffix}`;
}

export function formatSlotRange(start, end) {
  return `${formatTime12(start)} – ${formatTime12(end)}`;
}

/** "2026-10-08" → "Thursday, October 8, 2026" */
export function formatDateLong(iso) {
  const p = parseIsoDate(iso);
  if (!p) return String(iso ?? '');
  return `${DAY_NAMES[dayOfWeekIso(iso)]}, ${MONTH_NAMES[p.month - 1]} ${p.day}, ${p.year}`;
}

/** ['thu', 'sat'] → "Thursday and Saturday" */
export function formatVisitingDays(dayKeys) {
  const names = (Array.isArray(dayKeys) ? dayKeys : [])
    .map((k) => DAY_NAMES[DAY_KEYS.indexOf(String(k).toLowerCase())])
    .filter(Boolean);
  if (names.length <= 1) return names[0] ?? '';
  return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

/** @param {string} period @param {string} startTime */
export function periodLabel(period, startTime) {
  if (period === 'morning') return 'Morning';
  if (period === 'afternoon') return 'Afternoon';
  return Number(String(startTime ?? '').slice(0, 2)) < 12 ? 'Morning' : 'Afternoon';
}

/**
 * @typedef {object} AvailabilitySlot
 * @property {string} slotKey
 * @property {string} date
 * @property {string} period
 * @property {string} startTime
 * @property {string} endTime
 * @property {boolean} available
 * @property {string | null} reason
 * @property {number} capacity
 * @property {number} slotsRemaining
 * @property {string} relationshipId
 */

function normalizeSlot(raw, date, relationshipId) {
  if (!raw || typeof raw !== 'object') return null;
  const startTime = String(raw.startTime ?? '').slice(0, 5);
  const endTime = String(raw.endTime ?? '').slice(0, 5);
  if (!startTime || !endTime) return null;
  const slotDate = typeof raw.date === 'string' ? raw.date : date;
  return {
    slotKey: String(raw.slotKey ?? `${slotDate}|${startTime}`),
    date: slotDate,
    period: raw.period ?? (Number(startTime.slice(0, 2)) < 12 ? 'morning' : 'afternoon'),
    startTime,
    endTime,
    available: raw.available === true,
    reason: raw.available === true ? null : (raw.reason ?? null),
    capacity: Number(raw.capacity) || 0,
    slotsRemaining: Math.max(Number(raw.slotsRemaining) || 0, 0),
    relationshipId: String(raw.relationshipId ?? relationshipId ?? ''),
  };
}

function normalizeRelationship(raw) {
  if (!raw || typeof raw !== 'object' || raw.relationshipId == null) return null;
  const relationshipId = String(raw.relationshipId);
  const daysByDate = {};
  const days = (Array.isArray(raw.days) ? raw.days : [])
    .filter((d) => d && parseIsoDate(d.date))
    .map((d) => {
      const slots = (Array.isArray(d.slots) ? d.slots : [])
        .map((s) => normalizeSlot(s, d.date, relationshipId))
        .filter(Boolean)
        .sort((a, b) => a.startTime.localeCompare(b.startTime));
      const day = { date: d.date, available: slots.some((s) => s.available), slots };
      daysByDate[d.date] = day;
      return day;
    });

  return {
    relationshipId,
    pdlName: raw.pdlName ?? null,
    relationshipLabel: raw.relationshipLabel ?? null,
    classification: raw.classification ?? null,
    classificationLabel: raw.classificationLabel ?? null,
    visitingDays: Array.isArray(raw.visitingDays) ? raw.visitingDays : [],
    pdlAvailable: raw.pdlAvailable !== false,
    message: raw.message ?? null,
    days,
    daysByDate,
  };
}

/**
 * Normalizes GET /api/schedules/availability.
 * @param {unknown} data
 */
export function normalizeAvailabilityResponse(data) {
  const body = data && typeof data === 'object' ? data : {};
  const relationships = (Array.isArray(body.relationships) ? body.relationships : [])
    .map(normalizeRelationship)
    .filter(Boolean);

  return {
    timezone: body.timezone ?? 'Asia/Manila',
    today: parseIsoDate(body.today) ? body.today : manilaTodayIso(),
    from: parseIsoDate(body.from) ? body.from : null,
    to: parseIsoDate(body.to) ? body.to : null,
    message: typeof body.message === 'string' && body.message.trim() ? body.message.trim() : null,
    relationships,
  };
}

/** Distinct "start–end" windows across a relationship's days, e.g. Morning + Afternoon. */
export function visitingWindows(relationship) {
  const seen = new Map();
  for (const day of relationship?.days ?? []) {
    for (const slot of day.slots) {
      const key = `${slot.startTime}|${slot.endTime}`;
      if (!seen.has(key)) {
        seen.set(key, { period: slot.period, startTime: slot.startTime, endTime: slot.endTime });
      }
    }
  }
  return [...seen.values()].sort((a, b) => a.startTime.localeCompare(b.startTime));
}

/**
 * Visit-request parameters (POST /api/visit-requests). Only IDs and the
 * chosen date/time — no names or personal details. The backend must
 * re-check ownership, verification and capacity when the request is sent.
 * @param {{ relationshipId: string; date: string; startTime: string; endTime: string } | null} selection
 */
export function toVisitRequestParams(selection) {
  if (!selection?.relationshipId || !parseIsoDate(selection.date) || !selection.startTime) return null;
  return {
    relationshipId: String(selection.relationshipId),
    date: selection.date,
    startTime: selection.startTime,
    endTime: selection.endTime,
  };
}
