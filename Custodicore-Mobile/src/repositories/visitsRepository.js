import * as api from '../services/api';

/**
 * Assigned visits data access (Laravel API).
 * Screens should prefer VisitsContext; this module is the HTTP boundary.
 */

/**
 * Normalize a single visit payload from GET /api/visits (or confirm/decline response).
 * @param {Record<string, unknown>} raw
 */
export function normalizeVisit(raw) {
  if (!raw || typeof raw !== 'object') return null;
  const id = String(raw.id ?? raw.scheduleId ?? '');
  if (!id) return null;

  return {
    id,
    scheduleId: String(raw.scheduleId ?? raw.id ?? id),
    scheduledAt: raw.scheduledAt ?? null,
    endAt: raw.endAt ?? null,
    dateDisplay: raw.dateDisplay ?? null,
    timeLabel: raw.timeLabel ?? null,
    // Whole-day visit: one visit, morning + afternoon sessions.
    isWholeDay: raw.isWholeDay === true,
    sessions: Array.isArray(raw.sessions) ? raw.sessions : [],
    pdlName: raw.pdlName ?? null,
    facility: raw.facility ?? 'BJMP Facility',
    referenceNumber: raw.referenceNumber ?? null,
    visitType: raw.visitType ?? 'regular',
    status: raw.status ?? 'pending_confirmation',
    // A `declined` visit the visitor cancelled themselves (shown as Cancelled).
    cancelledByVisitor: raw.cancelledByVisitor === true,
    cancellationReason: raw.cancellationReason ?? null,
    unableReason: raw.unableReason ?? null,
    unableNotes: raw.unableNotes ?? null,
  };
}

/**
 * @returns {Promise<object[]>}
 */
export async function fetchAssignedVisits() {
  const data = await api.getVisits();
  const list = Array.isArray(data?.visits)
    ? data.visits
    : Array.isArray(data)
      ? data
      : [];
  return list.map(normalizeVisit).filter(Boolean);
}

/**
 * @param {string} visitId — visit_request_id (mobile "scheduleId")
 * @returns {Promise<object>}
 */
export async function confirmAssignedVisit(visitId) {
  return normalizeVisit(await api.confirmSchedule(visitId));
}

/**
 * Submits a visit request (POST /api/visit-requests). Nothing is created
 * unless the backend accepts it.
 * @param {{ relationshipId: string; date: string; startTime: string; startTimes?: string[] }} params
 * @returns {Promise<object | null>} the created visit (status `assigned`)
 */
export async function submitVisitRequest(params) {
  return normalizeVisit(await api.createVisitRequest(params));
}

/**
 * @param {string} visitId
 * @param {{ reason?: string; notes?: string }} details
 * @returns {Promise<object>}
 */
export async function declineAssignedVisit(visitId, details = {}) {
  const reasonParts = [details.reason, details.notes?.trim()].filter(Boolean);
  const reason =
    reasonParts.length > 0
      ? reasonParts.join(' — ')
      : 'Visitor unable to attend (declined via mobile app)';

  return normalizeVisit(
    await api.declineSchedule(visitId, { reason }),
  );
}

/**
 * The visitor cancels their own `assigned` or `confirmed` visit.
 * @param {string} visitId
 * @param {{ reason: string; notes?: string }} details
 * @returns {Promise<object | null>}
 */
export async function cancelVisit(visitId, { reason, notes } = {}) {
  return normalizeVisit(await api.cancelSchedule(visitId, { reason, notes }));
}
