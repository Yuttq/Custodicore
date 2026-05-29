import { useCallback, useState } from 'react';

/**
 * Notification counts and refresh helpers (wire to API in Phase 7).
 */
export default function useNotifications() {
  const [unreadCount, setUnreadCount] = useState(0);
  const [loading, setLoading] = useState(false);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      // await getUnreadNotificationCount()
      setUnreadCount(0);
    } finally {
      setLoading(false);
    }
  }, []);

  return { unreadCount, setUnreadCount, loading, refresh };
}
