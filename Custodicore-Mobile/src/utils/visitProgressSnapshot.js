import { getMockVisitTrackingTimeline } from '../mock/visitTrackingTimeline.mock';

/** Six-step visit progress snapshot (BJMP workflow, courier-style). */
export const VISIT_PROGRESS_SNAPSHOT_DEFS = [
  { label: 'Documents Verified', stepId: 'visitor_eligible' },
  { label: 'Schedule Assigned', stepId: 'schedule_assigned' },
  { label: 'Attendance Confirmed', stepId: 'attendance_confirmed' },
  { label: 'QR Pass Ready', stepId: 'qr_generated' },
  { label: 'Check-In', stepId: 'checked_in' },
  { label: 'Visit Completed', stepId: 'visit_completed' },
];

/**
 * @typedef {object} CompactVisitStep
 * @property {string} id
 * @property {string} label
 * @property {'completed' | 'current' | 'pending'} stepState
 * @property {string} description
 * @property {string | null} occurredAt
 * @property {string | null} officerNote
 */

/**
 * @param {ReturnType<typeof getMockVisitTrackingTimeline>} fullSteps
 * @returns {CompactVisitStep[]}
 */
export function buildCompactVisitStepsFromTimeline(fullSteps) {
  return VISIT_PROGRESS_SNAPSHOT_DEFS.map(({ label, stepId }) => {
    const entry = fullSteps.find((s) => s.id.endsWith(`-${stepId}`) || s.id === stepId);
    return {
      id: stepId,
      label,
      stepState: entry?.stepState ?? 'pending',
      description: entry?.description ?? '',
      occurredAt: entry?.occurredAt ?? null,
      officerNote: entry?.officerNote ?? null,
    };
  });
}

/**
 * @param {string | undefined} scheduleId
 * @param {string | undefined} visitStatus
 * @returns {CompactVisitStep[]}
 */
export function getCompactVisitSteps(scheduleId, visitStatus) {
  const fullSteps = getMockVisitTrackingTimeline(String(scheduleId || ''), visitStatus);
  return buildCompactVisitStepsFromTimeline(fullSteps);
}
