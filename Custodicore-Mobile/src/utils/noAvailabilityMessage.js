/**
 * The message shown when the selected PDL has no bookable date in the
 * loaded range (Home calendar and Visitation Schedule). Instead of one
 * generic line, it names the main reason, read from the per-slot `reason`
 * codes the backend sends (ScheduleAvailabilityService).
 *
 * Each upcoming day counts once, under the reason of its first unavailable
 * session; past days are ignored. The most common reason wins; a tie goes
 * to the earlier reason in REASON_ORDER.
 */

import { formatWeekRange, weeklyUsageFor } from './scheduleAvailability.js';

const GENERIC = 'No visit dates are available soon. Check again later.';

/** Tie-break order: the reason the visitor can do least about comes first. */
const REASON_ORDER = [
  'pdl_unavailable',
  'not_published',
  'closed',
  'full',
  'weekly_limit',
  'daily_limit',
  'already_scheduled',
  'not_eligible',
  'not_in_effect',
];

/**
 * The weekly-limit message. With the response's `weeklyLimit` it names the
 * Monday–Sunday week(s) at the limit; the limit is always visitor-wide.
 * @param {string[]} weekStarts Mondays of the blocked weeks, oldest first
 * @param {number | null} limit
 */
function weeklyLimitMessage(weekStarts, limit) {
  const ranges = weekStarts.map(formatWeekRange);
  const which = ranges.length > 0
    ? ` for ${ranges.length === 1 ? ranges[0] : `${ranges.slice(0, -1).join(', ')} and ${ranges[ranges.length - 1]}`} (Monday–Sunday)`
    : '';
  const of = limit != null ? ` of ${limit} ${limit === 1 ? 'visit' : 'visits'}` : '';
  return (
    `Weekly limit${of} reached${which}. ` +
    // The limit is per week of the visit date: other weeks are bookable now.
    'It applies across all your linked PDLs. Dates in other weeks can be booked once they open.'
  );
}

/** @type {Record<string, (pdl: string) => string>} */
const MESSAGES = {
  pdl_unavailable: (pdl) => `Visits with ${pdl} aren't available right now. Contact the facility.`,
  not_published: (pdl) => `Visitation dates haven't been opened for ${pdl} yet.`,
  closed: (pdl) => `Visiting is closed on ${pdl}'s upcoming days. Check again later.`,
  full: (pdl) => `Upcoming visit dates for ${pdl} are full. Check again later.`,
  weekly_limit: () => weeklyLimitMessage([], null),
  daily_limit: (pdl) => `You have a visit with another PDL on ${pdl}'s visiting days. Only one PDL per day.`,
  already_scheduled: (pdl) => `You already have a visit with ${pdl} on every open date.`,
  not_eligible: (pdl) => `No visiting days for ${pdl} in the coming weeks.`,
  not_in_effect: (pdl) => `No visiting days for ${pdl} in the coming weeks.`,
};

const IGNORED = new Set(['past', 'ended']);

/** Reasons for days that are not visiting days at all; used only when nothing else applies. */
const FALLBACK_ONLY = new Set(['not_eligible', 'not_in_effect']);

/**
 * @param {{ pdlName?: string | null; pdlAvailable?: boolean; days?: { date?: string; available: boolean; slots?: { available: boolean; reason: string | null }[] }[] } | null | undefined} relationship
 * @param {{ limit: number; weeks: Record<string, number> } | null} [weeklyLimit] the
 *   availability response's visitor-wide weekly usage, to name the weeks at the limit
 * @returns {string}
 */
export function noAvailabilityMessage(relationship, weeklyLimit = null) {
  const pdl = typeof relationship?.pdlName === 'string' && relationship.pdlName.trim()
    ? relationship.pdlName.trim()
    : 'this PDL';

  const reason = noAvailabilityReason(relationship);
  if (reason === 'weekly_limit') {
    /** @type {Set<string>} */
    const limitWeeks = new Set();
    for (const day of unavailableDays(relationship)) {
      if (day.reason !== 'weekly_limit') continue;
      const usage = weeklyUsageFor(weeklyLimit, day.date);
      if (usage?.reached) limitWeeks.add(usage.weekStart);
    }
    return weeklyLimitMessage([...limitWeeks].sort(), weeklyLimit?.limit ?? null);
  }
  // The later dates are unopened, but the visitor already booked the open
  // ones: "haven't been opened" alone would read as if nothing was ever open.
  if (
    reason === 'not_published' &&
    unavailableDays(relationship).some((d) => d.reason === 'already_scheduled')
  ) {
    return `You've already booked the open dates for ${pdl}. Later dates haven't been opened yet.`;
  }
  return reason ? MESSAGES[reason](pdl) : GENERIC;
}

/**
 * True when the relationship has at least one bookable (available) day.
 * @param {Parameters<typeof noAvailabilityMessage>[0]} relationship
 */
export function hasBookableDate(relationship) {
  return (Array.isArray(relationship?.days) ? relationship.days : []).some((d) => d?.available);
}

/**
 * The notice Home and Schedule show above the calendar when nothing is
 * bookable, or null when a date can be booked. It reads only the loaded
 * days, so it always matches the calendar on screen (also while a refresh
 * runs in the background).
 * @param {Parameters<typeof noAvailabilityMessage>[0]} relationship
 * @param {Parameters<typeof noAvailabilityMessage>[1]} [weeklyLimit]
 * @returns {string | null}
 */
export function noAvailabilityNotice(relationship, weeklyLimit = null) {
  if (!relationship || hasBookableDate(relationship)) return null;
  return noAvailabilityMessage(relationship, weeklyLimit);
}

/**
 * Each upcoming unavailable day with the reason of its first unavailable session.
 * @param {Parameters<typeof noAvailabilityMessage>[0]} relationship
 * @returns {{ date?: string; reason: string }[]}
 */
function unavailableDays(relationship) {
  const out = [];
  for (const day of Array.isArray(relationship?.days) ? relationship.days : []) {
    if (!day || day.available) continue;
    const reason = (Array.isArray(day.slots) ? day.slots : [])
      .map((s) => s?.reason)
      .find((r) => typeof r === 'string' && !IGNORED.has(r));
    if (reason && MESSAGES[reason]) out.push({ date: day.date, reason });
  }
  return out;
}

/**
 * The main reason the PDL has no bookable date (a REASON_ORDER code), or
 * null when there is nothing to go on. Screens use it to skip a message
 * another element already shows (the weekly counter for `weekly_limit`).
 * @param {Parameters<typeof noAvailabilityMessage>[0]} relationship
 * @returns {string | null}
 */
export function noAvailabilityReason(relationship) {
  if (relationship?.pdlAvailable === false) return 'pdl_unavailable';

  /** @type {Record<string, number>} */
  const counts = {};
  for (const { reason } of unavailableDays(relationship)) {
    counts[reason] = (counts[reason] ?? 0) + 1;
  }

  // Every non-visiting day in the range (e.g. each Tuesday of a Thursday-only
  // PDL) comes back as not_eligible, so it would always outnumber the real
  // visiting days. "No visiting days" is only true when no visiting day gave
  // another reason.
  if (Object.keys(counts).some((r) => !FALLBACK_ONLY.has(r))) {
    for (const r of FALLBACK_ONLY) delete counts[r];
  }

  const ranked = Object.keys(counts).sort(
    (a, b) => counts[b] - counts[a] || REASON_ORDER.indexOf(a) - REASON_ORDER.indexOf(b),
  );
  return ranked[0] ?? null;
}
