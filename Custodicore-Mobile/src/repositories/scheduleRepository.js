import * as api from '../services/api';
import { normalizeAvailabilityResponse } from '../utils/scheduleAvailability';

/**
 * Visit-slot availability data access (GET /api/schedules/availability).
 * Screens should use `useScheduleAvailability`, not `api` directly.
 */

/**
 * @param {{ from?: string; to?: string; relationshipId?: string }} [params]
 * @returns {Promise<ReturnType<typeof normalizeAvailabilityResponse>>}
 */
export async function fetchScheduleAvailability(params = {}) {
  return normalizeAvailabilityResponse(await api.getScheduleAvailability(params));
}
