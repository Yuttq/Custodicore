import * as api from '../services/api';

/**
 * QR / schedule resolution. `QRCodeScreen` should import from here only.
 *
 * Resolves which schedule id to use for the gate QR (route param or “next” visit).
 * @param {string | undefined} scheduleIdFromRoute
 * @returns {Promise<string>}
 */
export async function resolveScheduleIdForQr(scheduleIdFromRoute) {
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
  return api.getQrToken(scheduleId);
}
