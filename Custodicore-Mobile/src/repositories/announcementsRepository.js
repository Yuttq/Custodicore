import { MOCK_ANNOUNCEMENTS } from '../mock/announcements.mock';
import { USE_MOCK_ANNOUNCEMENTS } from '../mock/devFlags';
import { mockNetworkDelay } from '../mock/mockDelay';
import * as api from '../services/api';

/**
 * Facility announcements data access: mock or Laravel API based on `USE_MOCK_ANNOUNCEMENTS`.
 */

function normalizeAnnouncement(raw) {
  if (!raw || typeof raw !== 'object' || raw.id == null || !raw.title) return null;
  return {
    id: String(raw.id),
    title: String(raw.title),
    body: String(raw.body ?? ''),
    category: raw.category ?? 'general',
    pinned: Boolean(raw.pinned),
    createdAt: raw.createdAt ?? null,
  };
}

/**
 * @returns {Promise<{ id: string; title: string; body: string; category: string; pinned: boolean; createdAt: string | null }[]>}
 */
export async function fetchAnnouncements() {
  let data;
  if (USE_MOCK_ANNOUNCEMENTS) {
    await mockNetworkDelay(200);
    data = { announcements: MOCK_ANNOUNCEMENTS };
  } else {
    data = await api.getAnnouncements();
  }
  const list = Array.isArray(data?.announcements) ? data.announcements : Array.isArray(data) ? data : [];
  return list.map(normalizeAnnouncement).filter(Boolean);
}
