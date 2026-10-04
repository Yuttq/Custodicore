/** Six-step visit progress snapshot (BJMP workflow, courier-style). */
export const VISIT_PROGRESS_SNAPSHOT_DEFS = [
  { label: 'Documents Verified', stepId: 'visitor_eligible' },
  { label: 'Schedule Assigned', stepId: 'schedule_assigned' },
  { label: 'Attendance Confirmed', stepId: 'attendance_confirmed' },
  { label: 'QR Pass Ready', stepId: 'qr_generated' },
  { label: 'Check-In', stepId: 'checked_in' },
  { label: 'Visit Completed', stepId: 'visit_completed' },
];

/** Closing events the backend appends for visits that ended without completing. */
const TERMINAL_STEP_IDS = ['visit_declined', 'visit_cancelled', 'visit_no_show'];

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
 * @typedef {object} TimelineEvent
 * @property {string} id
 * @property {string} title
 * @property {string} description
 * @property {string | null} occurredAt
 * @property {string | null} officerNote
 * @property {'completed' | 'current' | 'pending' | null} stepState
 */

/**
 * @param {Record<string, unknown>} raw
 * @param {number} index
 * @returns {TimelineEvent}
 */
function normalizeTimelineEvent(raw, index) {
  const id = String(raw.id ?? raw.eventId ?? `evt-${index}`);
  const title =
    (typeof raw.title === 'string' && raw.title) ||
    (typeof raw.label === 'string' && raw.label) ||
    id.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  const description =
    (typeof raw.description === 'string' && raw.description) ||
    (typeof raw.message === 'string' && raw.message) ||
    '';
  const occurredAt =
    (typeof raw.occurredAt === 'string' && raw.occurredAt) ||
    (typeof raw.createdAt === 'string' && raw.createdAt) ||
    null;
  const officerNote =
    (typeof raw.officerNote === 'string' && raw.officerNote) ||
    (typeof raw.officer_note === 'string' && raw.officer_note) ||
    null;
  const stepStateRaw = raw.stepState ?? raw.step_state;
  const stepState =
    stepStateRaw === 'completed' || stepStateRaw === 'current' || stepStateRaw === 'pending'
      ? stepStateRaw
      : null;

  return { id, title, description, occurredAt, officerNote, stepState };
}

/**
 * Normalizes GET /api/schedules/{id}/timeline (`{ steps: [...] }`) or a raw array.
 * @param {unknown} data
 * @returns {TimelineEvent[]}
 */
export function normalizeTimelineResponse(data) {
  let rows = [];
  if (Array.isArray(data)) rows = data;
  else if (data && typeof data === 'object') {
    const o = /** @type {Record<string, unknown>} */ (data);
    rows =
      (Array.isArray(o.steps) && o.steps) ||
      (Array.isArray(o.events) && o.events) ||
      (Array.isArray(o.timeline) && o.timeline) ||
      (Array.isArray(o.data) && o.data) ||
      [];
  }
  return rows
    .filter((row) => row && typeof row === 'object')
    .map((row, i) => normalizeTimelineEvent(/** @type {Record<string, unknown>} */ (row), i));
}

/**
 * @param {{ id: string }} step
 * @param {string} stepId
 */
function matchesStep(step, stepId) {
  return step.id === stepId || step.id.endsWith(`-${stepId}`);
}

/**
 * Builds the six-step snapshot from real timeline events. When the visit ended
 * early (declined / cancelled / no-show), steps that never happened are dropped
 * and the backend's closing event is shown last.
 * @param {TimelineEvent[]} fullSteps
 * @returns {CompactVisitStep[]}
 */
export function buildCompactVisitStepsFromTimeline(fullSteps) {
  const base = VISIT_PROGRESS_SNAPSHOT_DEFS.map(({ label, stepId }) => {
    const entry = fullSteps.find((s) => matchesStep(s, stepId));
    return {
      id: stepId,
      label,
      stepState: entry?.stepState ?? 'pending',
      description: entry?.description ?? '',
      occurredAt: entry?.occurredAt ?? null,
      officerNote: entry?.officerNote ?? null,
    };
  });

  const terminal = fullSteps.find((s) => TERMINAL_STEP_IDS.some((id) => matchesStep(s, id)));
  if (!terminal) return base;

  return [
    ...base.filter((step) => step.stepState === 'completed'),
    {
      id: TERMINAL_STEP_IDS.find((id) => matchesStep(terminal, id)) ?? terminal.id,
      label: terminal.title,
      stepState: 'completed',
      description: terminal.description,
      occurredAt: terminal.occurredAt,
      officerNote: terminal.officerNote,
    },
  ];
}
