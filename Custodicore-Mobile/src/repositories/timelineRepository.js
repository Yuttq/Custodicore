import { USE_MOCK_TIMELINE } from '../mock/devFlags';
import { mockNetworkDelay } from '../mock/mockDelay';
import { getMockVisitTrackingTimeline } from '../mock/visitTrackingTimeline.mock';
import * as api from '../services/api';

/**
 * Visit timeline data access: mock 11-step BJMP workflow or live API.
 * `TimelineScreen` should import from here only.
 *
 * @param {string} scheduleId
 * @param {string | undefined} visitStatus
 * @returns {Promise<{ source: 'mock', steps: ReturnType<typeof getMockVisitTrackingTimeline> } | { source: 'api', data: unknown }>}
 */
export async function fetchVisitTimeline(scheduleId, visitStatus) {
  if (USE_MOCK_TIMELINE) {
    await mockNetworkDelay(420);
    return {
      source: 'mock',
      steps: getMockVisitTrackingTimeline(scheduleId, visitStatus),
    };
  }

  const data = await api.getTimeline(scheduleId);
  return { source: 'api', data };
}
