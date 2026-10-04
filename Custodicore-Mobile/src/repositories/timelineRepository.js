import { USE_MOCK_TIMELINE } from '../mock/devFlags';
import { mockNetworkDelay } from '../mock/mockDelay';
import { getMockVisitTrackingTimeline } from '../mock/visitTrackingTimeline.mock';
import * as api from '../services/api';
import { normalizeTimelineResponse } from '../utils/visitProgressSnapshot';

/**
 * Visit timeline data access: mock BJMP workflow or live API.
 * Screens should use `useVisitTimeline` (or import from here), not `api` directly.
 *
 * @param {string} scheduleId — visit_requests.visit_request_id
 * @param {string | undefined} visitStatus
 * @returns {Promise<import('../utils/visitProgressSnapshot').TimelineEvent[]>}
 */
export async function fetchVisitTimeline(scheduleId, visitStatus) {
  if (USE_MOCK_TIMELINE) {
    await mockNetworkDelay(420);
    return normalizeTimelineResponse(getMockVisitTrackingTimeline(scheduleId, visitStatus));
  }

  const data = await api.getTimeline(scheduleId);
  return normalizeTimelineResponse(data);
}
