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

const GENERIC = 'No visit dates are available in the coming weeks. Please check again later.';

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

/** @type {Record<string, (pdl: string) => string>} */
const MESSAGES = {
  pdl_unavailable: (pdl) => `Visits with ${pdl} aren't available right now. Contact the facility for details.`,
  not_published: (pdl) => `Visit dates for ${pdl} haven't been opened yet. Facility staff open them ahead of time, so check again in a few days.`,
  closed: (pdl) => `Visiting is closed on ${pdl}'s upcoming visiting days. Please check again later.`,
  full: (pdl) => `All upcoming visit dates for ${pdl} are full. Please check again later.`,
  weekly_limit: () => `You've reached your weekly visit limit. You can book again next week.`,
  daily_limit: (pdl) => `You already have visits with another PDL on ${pdl}'s visiting days. Only one PDL can be visited per day.`,
  already_scheduled: (pdl) => `You already have a visit with ${pdl} on every open date.`,
  not_eligible: (pdl) => `${pdl} has no visiting days in the coming weeks.`,
  not_in_effect: (pdl) => `${pdl} has no visiting days in the coming weeks.`,
};

const IGNORED = new Set(['past', 'ended']);

/**
 * @param {{ pdlName?: string | null; pdlAvailable?: boolean; days?: { available: boolean; slots?: { available: boolean; reason: string | null }[] }[] } | null | undefined} relationship
 * @returns {string}
 */
export function noAvailabilityMessage(relationship) {
  const pdl = typeof relationship?.pdlName === 'string' && relationship.pdlName.trim()
    ? relationship.pdlName.trim()
    : 'this PDL';

  if (relationship?.pdlAvailable === false) return MESSAGES.pdl_unavailable(pdl);

  /** @type {Record<string, number>} */
  const counts = {};
  for (const day of Array.isArray(relationship?.days) ? relationship.days : []) {
    if (!day || day.available) continue;
    const reason = (Array.isArray(day.slots) ? day.slots : [])
      .map((s) => s?.reason)
      .find((r) => typeof r === 'string' && !IGNORED.has(r));
    if (reason && MESSAGES[reason]) counts[reason] = (counts[reason] ?? 0) + 1;
  }

  const ranked = Object.keys(counts).sort(
    (a, b) => counts[b] - counts[a] || REASON_ORDER.indexOf(a) - REASON_ORDER.indexOf(b),
  );
  return ranked.length > 0 ? MESSAGES[ranked[0]](pdl) : GENERIC;
}
