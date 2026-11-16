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
 * Whether the visitor can confirm/decline. `assigned` is a visitor-submitted
 * request awaiting staff review — only staff can approve or reject it.
 * @param {string} status
 */
export function canRespondToVisit(status) {
  return status === 'pending_confirmation' || status === 'scheduled';
}
