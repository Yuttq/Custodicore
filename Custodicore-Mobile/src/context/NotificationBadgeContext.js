import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react';
import { useAuth } from '../hooks/useAuth';
import { fetchUnreadNotificationCount } from '../repositories/notificationsRepository';

const POLL_MS = 30_000;

const NotificationBadgeContext = createContext(null);

/**
 * Parses API payload for unread count (shapes vary by backend).
 * @param {unknown} data
 */
function parseUnreadCount(data) {
  if (typeof data === 'number' && Number.isFinite(data)) {
    return Math.max(0, Math.floor(data));
  }
  if (data && typeof data === 'object') {
    const o = /** @type {Record<string, unknown>} */ (data);
    const raw = o.count ?? o.unreadCount ?? o.unread ?? o.totalUnread;
    const n = Number(raw);
    if (Number.isFinite(n)) return Math.max(0, Math.floor(n));
  }
  return 0;
}

/**
 * Polls unread notification count while authenticated; exposes count for the tab bar.
 */
export function NotificationBadgeProvider({ children }) {
  const { token } = useAuth();
  const [unreadCount, setUnreadCount] = useState(0);

  const fetchCount = useCallback(async () => {
    if (!token) {
      setUnreadCount(0);
      return;
    }
    try {
      const data = await fetchUnreadNotificationCount();
      setUnreadCount(parseUnreadCount(data));
    } catch {
      // Keep last known count on transient failures.
    }
  }, [token]);

  useEffect(() => {
    if (!token) {
      setUnreadCount(0);
      return undefined;
    }

    let cancelled = false;

    const tick = async () => {
      if (cancelled) return;
      await fetchCount();
    };

    tick();
    const intervalId = setInterval(tick, POLL_MS);

    return () => {
      cancelled = true;
      clearInterval(intervalId);
    };
  }, [token, fetchCount]);

  const value = useMemo(
    () => ({
      unreadCount,
      setUnreadCount,
      refreshUnreadCount: fetchCount,
    }),
    [unreadCount, fetchCount],
  );

  return (
    <NotificationBadgeContext.Provider value={value}>
      {children}
    </NotificationBadgeContext.Provider>
  );
}

export function useNotificationBadge() {
  const ctx = useContext(NotificationBadgeContext);
  if (!ctx) {
    throw new Error(
      'useNotificationBadge must be used within NotificationBadgeProvider',
    );
  }
  return ctx;
}
