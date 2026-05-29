import { USE_MOCK_VISIT_HISTORY } from '../mock/devFlags';
import { MOCK_VISITATION_HISTORY } from '../mock/visitationHistory.mock';
import { mockNetworkDelay } from '../mock/mockDelay';
import * as api from '../services/api';
import {
  filterVisitationHistoryRecords,
  normalizeVisitHistoryResponse,
} from '../utils/visitHistoryNormalize';

/**
 * Visitation history for the signed-in visitor.
 * `VisitHistoryScreen` should import from here only.
 * @returns {Promise<import('../mock/visitationHistory.mock').MOCK_VISITATION_HISTORY[number][]>}
 */
export async function fetchVisitationHistory() {
  if (USE_MOCK_VISIT_HISTORY) {
    await mockNetworkDelay(480);
    return [...MOCK_VISITATION_HISTORY];
  }

  const data = await api.getVisitHistory();
  return filterVisitationHistoryRecords(normalizeVisitHistoryResponse(data));
}
