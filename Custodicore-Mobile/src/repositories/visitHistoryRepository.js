import * as api from '../services/api';
import {
  filterVisitationHistoryRecords,
  normalizeVisitHistoryResponse,
} from '../utils/visitHistoryNormalize';

/**
 * Visitation history for the signed-in visitor.
 * `VisitHistoryScreen` should import from here only.
 */
export async function fetchVisitationHistory() {
  const data = await api.getVisitHistory();
  return filterVisitationHistoryRecords(normalizeVisitHistoryResponse(data));
}
