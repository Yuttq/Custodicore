import * as api from '../services/api';
import { normalizeTimelineResponse } from '../utils/visitProgressSnapshot';

/**
 * Visit timeline data access (GET /api/schedules/{id}/timeline).
 * Screens should use `useVisitTimeline` (or import from here), not `api` directly.
 *
 * @param {string} scheduleId — visit_requests.visit_request_id
 * @returns {Promise<import('../utils/visitProgressSnapshot').TimelineEvent[]>}
 */
export async function fetchVisitTimeline(scheduleId) {
  const data = await api.getTimeline(scheduleId);
  return normalizeTimelineResponse(data);
}
