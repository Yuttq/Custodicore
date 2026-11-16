import * as api from '../services/api';

/**
 * Facility announcements data access (GET /api/announcements).
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
  const data = await api.getAnnouncements();
  const list = Array.isArray(data?.announcements) ? data.announcements : Array.isArray(data) ? data : [];
  return list.map(normalizeAnnouncement).filter(Boolean);
}
