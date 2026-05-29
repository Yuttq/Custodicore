/**
 * BJMP visitor visit tracking timeline — all 11 stages (chronological).
 * Replace with GET /visitor/visits/:id/tracking when API is available.
 */

/** @typedef {'completed' | 'current' | 'pending'} VisitProgressStepState */

/**
 * @typedef {object} VisitTrackingTimelineEntry
 * @property {string} id
 * @property {VisitProgressStepState} stepState
 * @property {string} title
 * @property {string} description
 * @property {string | null} occurredAt
 * @property {string | null} [officerNote]
 */

/** Canonical BJMP workflow — do not omit or reorder steps. */
export const BJMP_VISIT_TRACKING_STEP_DEFS = [
  {
    id: 'account_created',
    title: 'Account Created',
    description: 'Visitor portal account was created and activated.',
  },
  {
    id: 'documents_submitted',
    title: 'Documents Submitted',
    description: 'Required relationship and identification documents were uploaded.',
  },
  {
    id: 'identity_verified',
    title: 'Identity Verified',
    description: 'Government-issued ID was reviewed and verified by the facility.',
  },
  {
    id: 'relationship_verified',
    title: 'Relationship Verified',
    description: 'Relationship to the PDL was confirmed against submitted records.',
  },
  {
    id: 'visitor_eligible',
    title: 'Visitor Eligible',
    description: 'You are cleared for visitation under current facility policy.',
  },
  {
    id: 'schedule_assigned',
    title: 'Schedule Assigned',
    description: 'An officer assigned your visit date and time. No self-booking is required.',
  },
  {
    id: 'attendance_confirmed',
    title: 'Attendance Confirmed',
    description: 'You confirmed attendance for the assigned visit window.',
  },
  {
    id: 'qr_generated',
    title: 'QR Generated',
    description: 'Entry QR pass was issued for gate and front desk presentation.',
  },
  {
    id: 'checked_in',
    title: 'Checked In',
    description: 'Check-in was recorded at the facility visitor entrance.',
  },
  {
    id: 'checked_out',
    title: 'Checked Out',
    description: 'Check-out was recorded at the end of your visit session.',
  },
  {
    id: 'visit_completed',
    title: 'Visit Completed',
    description: 'Visit session closed. Thank you for following facility rules.',
  },
];

const MOCK_TIMESTAMPS = [
  '2026-05-01T08:00:00',
  '2026-05-01T10:30:00',
  '2026-05-03T14:15:00',
  '2026-05-04T09:45:00',
  '2026-05-05T11:20:00',
  '2026-06-10T13:00:00',
  '2026-06-12T16:40:00',
  '2026-06-15T08:05:00',
  '2026-06-16T13:55:00',
  '2026-06-16T16:35:00',
  '2026-06-16T16:40:00',
];

const MOCK_OFFICER_NOTES = [
  null,
  null,
  'Records: National ID matched submitted profile.',
  'Relationship: Spouse — marriage certificate on file.',
  null,
  'Assignment: Visiting Area · Jun 16, 2:00 PM – 4:30 PM.',
  null,
  'QR valid for assigned window only.',
  'Gate scan recorded by Duty Officer Reyes.',
  'Front desk checkout completed.',
  null,
];

/**
 * How many steps are fully completed; next index is `current` (unless all done).
 * @param {string | undefined} visitStatus
 */
function getProgressIndex(visitStatus) {
  const key = String(visitStatus || '')
    .toLowerCase()
    .replace(/-/g, '_');

  switch (key) {
    case 'completed':
      return 11;
    case 'checked_out':
      return 10;
    case 'checked_in':
      return 9;
    case 'confirmed':
      return 8;
    case 'scheduled':
      return 5;
    case 'pending_confirmation':
      return 6;
    case 'unable_to_attend':
      return 7;
    case 'cancelled':
      return 6;
    default:
      return 7;
  }
}

/**
 * @param {string | undefined} scheduleId
 * @param {string | undefined} visitStatus
 * @returns {VisitTrackingTimelineEntry[]}
 */
export function getMockVisitTrackingTimeline(scheduleId, visitStatus) {
  const progressIndex = getProgressIndex(visitStatus);
  const statusKey = String(visitStatus || '')
    .toLowerCase()
    .replace(/-/g, '_');

  return BJMP_VISIT_TRACKING_STEP_DEFS.map((def, index) => {
    let stepState =
      progressIndex >= 11 || index < progressIndex
        ? 'completed'
        : index === progressIndex
          ? 'current'
          : 'pending';

  let officerNote = MOCK_OFFICER_NOTES[index] ?? null;
  let description = def.description;

  if (statusKey === 'unable_to_attend' && def.id === 'attendance_confirmed') {
    stepState = 'current';
    description = 'Visitor indicated they are unable to attend this assigned visit.';
    officerNote = 'Visitor submitted: Unable To Attend — recorded in portal.';
  }

  if (statusKey === 'cancelled' && def.id === 'schedule_assigned') {
    stepState = 'current';
    description = 'This assigned visit was cancelled before completion.';
    officerNote = 'Visit cancelled per facility policy or visitor response.';
  }

  if (statusKey === 'pending_confirmation' && def.id === 'attendance_confirmed') {
    stepState = 'current';
    description = 'Please confirm attendance or indicate if you are unable to attend.';
    officerNote = null;
  }

    const occurredAt =
      stepState === 'completed' || stepState === 'current'
        ? MOCK_TIMESTAMPS[index]
        : null;

    return {
      id: `${scheduleId || 'visit'}-${def.id}`,
      stepState,
      title: def.title,
      description,
      occurredAt,
      officerNote,
    };
  });
}
