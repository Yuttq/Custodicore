import { USE_MOCK_NOTIFICATIONS } from '../mock/devFlags';
import { mockNetworkDelay } from '../mock/mockDelay';
import { MOCK_NOTIFICATIONS_RAW } from '../mock/notifications.mock';
import * as api from '../services/api';

/**
 * Notifications data access: delegates to static mocks or `api.*` based on `USE_MOCK_NOTIFICATIONS`.
 * Screens should import from here, not from `api` directly, so switching off mocks is one flag change.
 */

const mockMarkedReadIds = new Set();

function effectiveMockRows() {
  return MOCK_NOTIFICATIONS_RAW.map((row) => ({
    ...row,
    read: Boolean(row.read) || mockMarkedReadIds.has(String(row.id)),
  }));
}

/**
 * Notification list in API-like wrapper shapes supported by `normalizeList` on the screen.
 * @returns {Promise<unknown>}
 */
export async function fetchNotificationsList() {
  if (USE_MOCK_NOTIFICATIONS) {
    await mockNetworkDelay();
    return { notifications: effectiveMockRows() };
  }
  return api.getNotifications();
}

/**
 * @param {string} notificationId
 * @returns {Promise<unknown>}
 */
export async function markNotificationReadById(notificationId) {
  if (USE_MOCK_NOTIFICATIONS) {
    await mockNetworkDelay(120);
    mockMarkedReadIds.add(String(notificationId));
    return { ok: true };
  }
  return api.markNotificationRead(notificationId);
}

/**
 * Unread count payload (shape handled by `parseUnreadCount` in `NotificationBadgeContext`).
 * @returns {Promise<unknown>}
 */
export async function fetchUnreadNotificationCount() {
  if (USE_MOCK_NOTIFICATIONS) {
    await mockNetworkDelay(100);
    const unread = effectiveMockRows().filter((n) => !n.read).length;
    return { unreadCount: unread };
  }
  return api.getUnreadNotificationCount();
}
