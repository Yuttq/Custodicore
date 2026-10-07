import { USE_MOCK_SCHEDULE } from '../mock/devFlags';
import { mockNetworkDelay } from '../mock/mockDelay';
import { buildMockScheduleAvailability } from '../mock/scheduleAvailability.mock';
import * as api from '../services/api';
import { normalizeAvailabilityResponse } from '../utils/scheduleAvailability';

/**
 * Visit-slot availability data access: mock or Laravel API based on `USE_MOCK_SCHEDULE`.
 * Screens should use `useScheduleAvailability`, not `api` directly.
 */

/**
 * @param {{ from?: string; to?: string; relationshipId?: string }} [params]
 * @returns {Promise<ReturnType<typeof normalizeAvailabilityResponse>>}
 */
export async function fetchScheduleAvailability(params = {}) {
  if (USE_MOCK_SCHEDULE) {
    await mockNetworkDelay();
    return normalizeAvailabilityResponse(buildMockScheduleAvailability(params));
  }
  return normalizeAvailabilityResponse(await api.getScheduleAvailability(params));
}
