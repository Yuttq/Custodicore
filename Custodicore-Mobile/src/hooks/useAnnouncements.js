import { useCallback, useEffect, useState } from 'react';
import { fetchAnnouncements } from '../repositories/announcementsRepository';

/**
 * Facility announcements for the Home screen (GET /api/announcements).
 * @param {{ enabled?: boolean }} [options]
 */
export default function useAnnouncements({ enabled = true } = {}) {
  const [announcements, setAnnouncements] = useState([]);
  const [loading, setLoading] = useState(Boolean(enabled));
  const [error, setError] = useState(/** @type {string | null} */ (null));

  const load = useCallback(async () => {
    if (!enabled) {
      setAnnouncements([]);
      setError(null);
      setLoading(false);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      setAnnouncements(await fetchAnnouncements());
    } catch (e) {
      setError(e instanceof Error && e.message.trim() ? e.message : 'Could not load announcements.');
    } finally {
      setLoading(false);
    }
  }, [enabled]);

  useEffect(() => {
    load();
  }, [load]);

  return { announcements, loading, error, reload: load };
}
