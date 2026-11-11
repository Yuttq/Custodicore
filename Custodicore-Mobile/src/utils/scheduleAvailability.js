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
/** Calendar column headers, Monday first: the weekly visit limit runs Monday–Sunday. */
export const WEEKDAY_SHORT = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** Backend `reason` codes (ScheduleAvailabilityService) → visitor-facing text. */
export const SLOT_REASON_LABELS = {
  past: 'Past',
  ended: 'Past',
  not_eligible: 'Not available for your schedule',
  not_in_effect: 'Not available for your schedule',
  not_published: 'Not open for visits',
  pdl_unavailable: 'Not available for this PDL',
  closed: 'Closed',
  already_scheduled: 'Already scheduled',
  daily_limit: 'Visiting another PDL that day',
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
 * Monday-first month grid, so each row is one Monday–Sunday week — the same
 * week the backend counts visit.max_per_week in. Each week has 7 cells;
 * padding cells are null.
 * @param {number} year
 * @param {number} month 1-12
 * @returns {(string | null)[][]}
 */
export function buildMonthGrid(year, month) {
  const first = new Date(Date.UTC(year, month - 1, 1));
  const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
  const cells = [];
  const leading = (first.getUTCDay() + 6) % 7; // Monday = 0 … Sunday = 6
  for (let i = 0; i < leading; i += 1) cells.push(null);
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

/** "13:00" → "13:00" */
export function formatTime24(hhmm) {
  const m = /^(\d{1,2}):(\d{2})/.exec(String(hhmm ?? ''));
  if (!m) return String(hhmm ?? '');
  return `${String(Number(m[1])).padStart(2, '0')}:${m[2]}`;
}

export function formatSlotRange(start, end) {
  return `${formatTime24(start)} – ${formatTime24(end)}`;
}

/** "2026-10-08" → "Thursday, October 8, 2026" */
export function formatDateLong(iso) {
  const p = parseIsoDate(iso);
  if (!p) return String(iso ?? '');
  return `${DAY_NAMES[dayOfWeekIso(iso)]}, ${MONTH_NAMES[p.month - 1]} ${p.day}, ${p.year}`;
}

/**
 * The Monday ("YYYY-MM-DD") of the Monday–Sunday week containing `iso`,
 * matching the backend's ScheduleAvailabilityService::weekStart().
 * @param {string} iso
 * @returns {string | null}
 */
export function weekStartIso(iso) {
  if (!parseIsoDate(iso)) return null;
  return addDaysIso(iso, -((dayOfWeekIso(iso) + 6) % 7));
}

/**
 * Monday–Sunday range for the week starting `weekStart`:
 * "November 16–22", "November 30 – December 6",
 * "December 28, 2026 – January 3, 2027".
 * @param {string} weekStart YYYY-MM-DD (a Monday)
 */
export function formatWeekRange(weekStart) {
  const s = parseIsoDate(weekStart);
  const e = parseIsoDate(addDaysIso(weekStart, 6));
  if (!s || !e) return String(weekStart ?? '');
  if (s.year !== e.year) {
    return `${MONTH_NAMES[s.month - 1]} ${s.day}, ${s.year} – ${MONTH_NAMES[e.month - 1]} ${e.day}, ${e.year}`;
  }
  if (s.month !== e.month) {
    return `${MONTH_NAMES[s.month - 1]} ${s.day} – ${MONTH_NAMES[e.month - 1]} ${e.day}`;
  }
  return `${MONTH_NAMES[s.month - 1]} ${s.day}–${e.day}`;
}

/**
 * @typedef {object} WeeklyUsage
 * @property {string} weekStart Monday, YYYY-MM-DD
 * @property {string} weekEnd Sunday, YYYY-MM-DD
 * @property {number} used visits counted that week, across ALL linked PDLs
 * @property {number} limit visit.max_per_week
 * @property {boolean} reached used >= limit
 */

/**
 * Normalizes the response's `weeklyLimit` ({ limit, weeks[] }), or null when
 * the backend did not send one.
 * @param {unknown} raw
 * @returns {{ limit: number; weeks: Record<string, number> } | null}
 */
function normalizeWeeklyLimit(raw) {
  if (!raw || typeof raw !== 'object') return null;
  const limit = Number(raw.limit);
  if (!Number.isInteger(limit) || limit < 0) return null;
  /** @type {Record<string, number>} */
  const weeks = {};
  for (const w of Array.isArray(raw.weeks) ? raw.weeks : []) {
    if (w && parseIsoDate(w.weekStart)) weeks[w.weekStart] = Math.max(Number(w.used) || 0, 0);
  }
  return { limit, weeks };
}

/**
 * The visitor's usage of the weekly limit in the Monday–Sunday week holding
 * `date`. A week the backend did not report (outside the loaded range) is
 * null rather than a guessed 0.
 * @param {{ limit: number; weeks: Record<string, number> } | null | undefined} weeklyLimit
 * @param {string | null | undefined} date YYYY-MM-DD
 * @returns {WeeklyUsage | null}
 */
export function weeklyUsageFor(weeklyLimit, date) {
  const weekStart = weekStartIso(/** @type {string} */ (date));
  if (!weeklyLimit || !weekStart || !(weekStart in weeklyLimit.weeks)) return null;
  const used = weeklyLimit.weeks[weekStart];
  return {
    weekStart,
    weekEnd: /** @type {string} */ (addDaysIso(weekStart, 6)),
    used,
    limit: weeklyLimit.limit,
    reached: used >= weeklyLimit.limit,
  };
}

/** "0 of 2 visits used" */
export function weeklyUsageLabel(usage) {
  return `${usage.used} of ${usage.limit} ${usage.limit === 1 ? 'visit' : 'visits'} used`;
}

/**
 * Plain-language explanation of a week at the limit, naming the
 * Monday–Sunday range and that it covers every linked PDL.
 * @param {WeeklyUsage} usage
 */
export function weeklyLimitReachedMessage(usage) {
  const visits = `${usage.limit} ${usage.limit === 1 ? 'visit' : 'visits'}`;
  // "Monday, November 23, 2026" → "Monday, November 23"
  const nextMonday = formatDateLong(addDaysIso(usage.weekStart, 7)).replace(/, \d{4}$/, '');
  return (
    `You've reached the limit of ${visits} for ${formatWeekRange(usage.weekStart)} (Monday–Sunday). ` +
    'The limit counts visits with all your linked PDLs together, so you can’t request another visit ' +
    `with any PDL that week. You can request again for the week starting ${nextMonday}.`
  );
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
  if (period === 'whole_day') return 'Whole day';
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
 * @property {string[]} [startTimes] whole-day option only: every session it books
 */

export const WHOLE_DAY_PERIOD = 'whole_day';

/**
 * The day's sessions booked together as ONE visit (backend `wholeDay`),
 * shaped like a slot so it can be selected the same way. Seats left is the
 * tightest session's.
 * @param {unknown} raw
 * @param {string} date
 * @param {AvailabilitySlot[]} slots the day's normalized slots
 * @param {string} relationshipId
 * @returns {AvailabilitySlot | null}
 */
function normalizeWholeDay(raw, date, slots, relationshipId) {
  if (!raw || typeof raw !== 'object' || !Array.isArray(raw.startTimes)) return null;
  const startTimes = raw.startTimes.map((t) => String(t).slice(0, 5)).filter(Boolean).sort();
  const sessions = slots.filter((s) => startTimes.includes(s.startTime));
  if (startTimes.length < 2 || sessions.length !== startTimes.length) return null;
  const available = raw.available === true && sessions.every((s) => s.available);
  return {
    slotKey: `${date}|${WHOLE_DAY_PERIOD}`,
    date,
    period: WHOLE_DAY_PERIOD,
    startTime: sessions[0].startTime,
    endTime: sessions[sessions.length - 1].endTime,
    available,
    reason: available ? null : (raw.reason ?? sessions.find((s) => !s.available)?.reason ?? null),
    capacity: Math.min(...sessions.map((s) => s.capacity)),
    slotsRemaining: Math.min(...sessions.map((s) => s.slotsRemaining)),
    relationshipId,
    startTimes,
  };
}

/**
 * "11:30 – 13:00": the break between a whole-day visit's sessions.
 * @param {AvailabilitySlot[]} slots the day's slots, sorted by start
 * @returns {string | null}
 */
export function middayBreakLabel(slots) {
  if (!Array.isArray(slots) || slots.length < 2) return null;
  return formatSlotRange(slots[0].endTime, slots[1].startTime);
}

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
      const day = {
        date: d.date,
        available: slots.some((s) => s.available),
        slots,
        wholeDay: normalizeWholeDay(d.wholeDay, d.date, slots, relationshipId),
      };
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
    weeklyLimit: normalizeWeeklyLimit(body.weeklyLimit),
  };
}

/**
 * Date-first calendar (Home): one `daysByDate` across all of the visitor's
 * verified relationships. A date is available when at least one
 * relationship (PDL) has an available session on it.
 * @param {{ daysByDate?: Record<string, { available: boolean }> }[] | null | undefined} relationships
 * @returns {Record<string, { available: boolean }>}
 */
export function combineAvailableDays(relationships) {
  /** @type {Record<string, { available: boolean }>} */
  const combined = {};
  for (const relationship of Array.isArray(relationships) ? relationships : []) {
    for (const [date, day] of Object.entries(relationship?.daysByDate ?? {})) {
      combined[date] = { available: combined[date]?.available === true || day?.available === true };
    }
  }
  return combined;
}

/**
 * The relationships (PDLs) with an available session on `date`, in their
 * original order — the "Who will you visit?" choices after a date is picked.
 * @template {{ daysByDate?: Record<string, { available: boolean }> }} R
 * @param {R[] | null | undefined} relationships
 * @param {string | null | undefined} date YYYY-MM-DD
 * @returns {R[]}
 */
export function relationshipsAvailableOn(relationships, date) {
  if (!Array.isArray(relationships) || !parseIsoDate(date)) return [];
  return relationships.filter((r) => r?.daysByDate?.[/** @type {string} */ (date)]?.available === true);
}

/**
 * Home calendar → Schedule handoff params for a tapped date, or null when the
 * date has no available session for the selected relationship (PDL). Only
 * that relationship's own days count — never another PDL's.
 * @param {{ relationshipId?: string | null; daysByDate?: Record<string, { available: boolean }> } | null | undefined} relationship
 * @param {string | null | undefined} date YYYY-MM-DD
 * @returns {{ tab: 'schedule'; date: string; relationshipId: string } | null}
 */
export function calendarDateHandoff(relationship, date) {
  if (!relationship?.relationshipId || !parseIsoDate(date)) return null;
  const iso = /** @type {string} */ (date);
  if (relationship.daysByDate?.[iso]?.available !== true) return null;
  return { tab: 'schedule', date: iso, relationshipId: String(relationship.relationshipId) };
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
 * A whole-day selection also carries `startTimes` (every session it books);
 * the backend makes them one visit.
 * @param {{ relationshipId: string; date: string; startTime: string; endTime: string; startTimes?: string[] } | null} selection
 */
export function toVisitRequestParams(selection) {
  if (!selection?.relationshipId || !parseIsoDate(selection.date) || !selection.startTime) return null;
  /** @type {{ relationshipId: string; date: string; startTime: string; endTime: string; startTimes?: string[] }} */
  const params = {
    relationshipId: String(selection.relationshipId),
    date: selection.date,
    startTime: selection.startTime,
    endTime: selection.endTime,
  };
  if (Array.isArray(selection.startTimes) && selection.startTimes.length > 1) {
    params.startTimes = [...selection.startTimes];
  }
  return params;
}
