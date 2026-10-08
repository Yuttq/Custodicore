import { USE_MOCK_VISITS } from '../mock/devFlags';
import { MOCK_ASSIGNED_VISITS } from '../mock/assignedVisits.mock';
import { mockNetworkDelay } from '../mock/mockDelay';
import * as api from '../services/api';

/**
 * Assigned visits data access: mock or Laravel API based on `USE_MOCK_VISITS`.
 * Screens should prefer VisitsContext; this module is the HTTP/mock boundary.
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
    cancellationReason: raw.cancellationReason ?? null,
    unableReason: raw.unableReason ?? null,
    unableNotes: raw.unableNotes ?? null,
  };
}

/**
 * @returns {Promise<object[]>}
 */
export async function fetchAssignedVisits() {
  if (USE_MOCK_VISITS) {
    await mockNetworkDelay();
    return MOCK_ASSIGNED_VISITS.map((v) => ({ ...v }));
  }

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
  if (USE_MOCK_VISITS) {
    await mockNetworkDelay(300);
    return { id: visitId, status: 'confirmed' };
  }
  return normalizeVisit(await api.confirmSchedule(visitId));
}

/**
 * Submits a visit request (POST /api/visit-requests). Always hits the API —
 * there is no mock path, so nothing is created unless the backend accepts it.
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
  if (USE_MOCK_VISITS) {
    await mockNetworkDelay(350);
    return {
      id: visitId,
      status: 'declined',
      cancellationReason: details.reason || null,
    };
  }

  const reasonParts = [details.reason, details.notes?.trim()].filter(Boolean);
  const reason =
    reasonParts.length > 0
      ? reasonParts.join(' — ')
      : 'Visitor unable to attend (declined via mobile app)';

  return normalizeVisit(
    await api.declineSchedule(visitId, { reason }),
  );
}
