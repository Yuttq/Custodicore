/**
 * Visit grouping rules and fixed visitor-facing text for the visit screens.
 * Backend statuses are canonical; these helpers only group them for the UI.
 */

/**
 * Grouped visitation guidelines for Visit Details → Guidelines tab.
 * @typedef {{ title: string; items: string[] }} VisitationGuidelineSection
 */

/** @type {VisitationGuidelineSection[]} */
export const VISITATION_GUIDELINE_SECTIONS = [
  {
    title: 'Required Before Arrival',
    items: [
      'Bring valid government-issued ID',
      'Present QR Pass',
      'Arrive at least 30 minutes early',
    ],
  },
  {
    title: 'Security Screening',
    items: [
      'Food subject to inspection',
      'Belongings subject to inspection',
      'Security screening required',
    ],
  },
  {
    title: 'Facility Rules',
    items: ['No prohibited items', 'Follow officer instructions'],
  },
];

export const UNABLE_TO_ATTEND_REASONS = [
  'Personal Conflict',
  'Medical Reason',
  'Transportation Issue',
  'Family Emergency',
  'Other',
];

/**
 * My Visits screen tabs: Upcoming | Pending | Completed.
 * @param {string} status
 * @returns {'upcoming' | 'pending' | 'completed'}
 */
export function getMyVisitsTab(status) {
  if (
    status === 'completed' ||
    status === 'checked_out' ||
    status === 'cancelled' ||
    status === 'declined' ||
    status === 'no_show' ||
    status === 'unable_to_attend'
  ) {
    return 'completed';
  }
  if (
    status === 'pending_confirmation' ||
    status === 'assigned' ||
    status === 'scheduled'
  ) {
    return 'pending';
  }
  return 'upcoming';
}

/**
 * Whether the visitor can confirm/decline (staff-assigned visits only).
 * `assigned` is a legacy request booked before the review step was removed;
 * the backend settles it, so the visitor cannot confirm or decline it.
 * @param {string} status
 */
export function canRespondToVisit(status) {
  return status === 'pending_confirmation' || status === 'scheduled';
}

/**
 * Whether the visitor can cancel the visit themselves: a `confirmed` visit
 * (or a legacy `assigned` request), until its first
 * session starts. A visit awaiting their answer (`pending_confirmation`)
 * is declined instead (canRespondToVisit). The server checks the same
 * rules, plus that the visitor has not checked in.
 * @param {{ status?: string; scheduledAt?: string | null } | null | undefined} visit
 * @param {number} [now] — ms since epoch
 */
export function canCancelVisit(visit, now = Date.now()) {
  if (!visit || (visit.status !== 'assigned' && visit.status !== 'confirmed')) {
    return false;
  }
  const start = visit.scheduledAt ? Date.parse(visit.scheduledAt) : Number.NaN;
  // Unknown start: let the server decide.
  return Number.isNaN(start) || now < start;
}
