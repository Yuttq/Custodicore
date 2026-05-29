import { MOCK_ASSIGNED_VISITS } from './assignedVisits.mock';

/**
 * Past visitation records (completed / cancelled) for Visitation History screen.
 * Derived from assigned visits mock; replace with GET /visits/history when API is ready.
 */
export const MOCK_VISITATION_HISTORY = MOCK_ASSIGNED_VISITS.filter(
  (v) => v.status === 'completed' || v.status === 'cancelled',
)
  .map((v) => ({
    id: v.id,
    scheduledAt: v.scheduledAt,
    dateDisplay: v.dateDisplay,
    timeLabel: v.timeLabel,
    pdlName: v.pdlName,
    facility: v.facility,
    referenceNumber: v.referenceNumber,
    visitType: v.visitType,
    status: v.status,
    cancellationReason:
      v.cancellationReason ??
      (v.status === 'cancelled' ? 'Visit cancelled by facility administration' : null),
  }))
  .sort((a, b) => new Date(b.scheduledAt) - new Date(a.scheduledAt));

/**
 * @param {typeof MOCK_VISITATION_HISTORY} records
 */
export function getVisitationHistorySummary(records) {
  const total = records.length;
  const completed = records.filter((r) => r.status === 'completed').length;
  const cancelled = records.filter((r) => r.status === 'cancelled').length;
  return { total, completed, cancelled };
}
