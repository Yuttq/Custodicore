import * as api from '../services/api';

/**
 * Notifications data access. Screens should import from here, not from `api` directly.
 */

/**
 * Notification list in API-like wrapper shapes supported by `normalizeList` on the screen.
 * @returns {Promise<unknown>}
 */
export async function fetchNotificationsList() {
  return api.getNotifications();
}

/**
 * @param {string} notificationId
 * @returns {Promise<unknown>}
 */
export async function markNotificationReadById(notificationId) {
  return api.markNotificationRead(notificationId);
}

/**
 * Unread count payload (shape handled by `parseUnreadCount` in `NotificationBadgeContext`).
 * @returns {Promise<unknown>}
 */
export async function fetchUnreadNotificationCount() {
  return api.getUnreadNotificationCount();
}
