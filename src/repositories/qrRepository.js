import { USE_MOCK_QR } from '../mock/devFlags';
import { mockNetworkDelay } from '../mock/mockDelay';
import { buildMockQrResponse, MOCK_QR_VISIT_ID } from '../mock/qr.mock';
import * as api from '../services/api';

/**
 * QR / schedule resolution: delegates to static mocks or `api.*` based on `USE_MOCK_QR`.
 * `QRCodeScreen` should import from here only.
 *
 * Resolves which schedule id to use for the gate QR (route param or “next” visit).
 * @param {string | undefined} scheduleIdFromRoute
 * @returns {Promise<string>}
 */
export async function resolveScheduleIdForQr(scheduleIdFromRoute) {
  if (USE_MOCK_QR) {
    await mockNetworkDelay(160);
    if (scheduleIdFromRoute != null && String(scheduleIdFromRoute).trim()) {
      return String(scheduleIdFromRoute).trim();
    }
    return MOCK_QR_VISIT_ID;
  }

  if (scheduleIdFromRoute != null && String(scheduleIdFromRoute).trim()) {
    return String(scheduleIdFromRoute).trim();
  }
  const upcoming = await api.getUpcomingSchedule();
  const id =
    upcoming?.scheduleId ??
    upcoming?.id ??
    upcoming?.schedule?.id ??
    upcoming?.visitId;
  if (!id) {
    throw new Error(
      'No upcoming visit found. Check My Visits for an assigned visit, then try again.',
    );
  }
  return String(id);
}

/**
 * Gate token / QR payload for a schedule.
 * @param {string} scheduleId
 * @returns {Promise<unknown>}
 */
export async function fetchQrTokenPayload(scheduleId) {
  if (USE_MOCK_QR) {
    await mockNetworkDelay(340);
    return buildMockQrResponse(scheduleId);
  }
  return api.getQrToken(scheduleId);
}
