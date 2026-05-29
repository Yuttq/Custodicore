/**
 * TEMPORARY — development-only QR / visit gate payloads.
 * Replace with `api.getQrToken` when backend is available.
 */

/** Default visit id aligned with `assignedVisits.mock` / dashboard. */
export const MOCK_QR_VISIT_ID = 'visit-001';

/**
 * Builds a fake gate-token payload for the given visit / schedule id.
 * @param {string} scheduleId
 * @returns {Record<string, unknown>}
 */
export function buildMockQrResponse(scheduleId) {
  const expiresAt = new Date(
    Date.now() + (2 * 60 * 60 + 45 * 60 + 30) * 1000,
  ).toISOString();
  const qrToken = `custodicore|${scheduleId}|gate|${Date.now().toString(36)}`;

  return {
    qrToken,
    expiresAt,
    referenceNumber: 'VIS-2026-0616-001',
    schedule: {
      scheduledAt: '2026-06-16T14:00:00',
      endAt: '2026-06-16T16:30:00',
      dateDisplay: 'Jun 16, 2026',
      timeLabel: '2:00 PM – 4:30 PM',
      pdlName: 'Juan D. Rivera',
      facilityName: 'BJMP Facility — Visiting Area',
    },
    scheduleId,
  };
}
