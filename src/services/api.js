import AsyncStorage from '@react-native-async-storage/async-storage';
import axios, { isAxiosError } from 'axios';

/**
 * Visitor API base URL — replace when the BJMP backend is available.
 * In Expo, you can set `EXPO_PUBLIC_API_URL` in `.env` (no trailing slash).
 */
const BASE_URL = (
  (typeof process !== 'undefined' && process.env?.EXPO_PUBLIC_API_URL) ||
  'https://api.custodicore.placeholder'
).replace(/\/$/, '');

const TOKEN_KEY = '@custodicore/auth_token';

// axios.create is the documented API; eslint-plugin-import flags default.create.
// eslint-disable-next-line import/no-named-as-default-member -- axios public API
const client = axios.create({
  baseURL: BASE_URL,
  timeout: 20000,
  headers: { 'Content-Type': 'application/json' },
});

/** Attaches `Authorization: Bearer <token>` when a stored session exists. */
client.interceptors.request.use(async (config) => {
  try {
    const token = await AsyncStorage.getItem(TOKEN_KEY);
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
  } catch {
    // Storage unavailable — request continues without auth header.
  }
  return config;
});

/**
 * Normalizes axios failures into a plain `Error` for alerts and logging.
 * @param {unknown} error
 * @returns {Error}
 */
function toRequestError(error) {
  if (isAxiosError(error)) {
    const body = error.response?.data;
    const fromBody =
      (typeof body?.message === 'string' && body.message) ||
      (typeof body?.error === 'string' && body.error);
    const combined =
      fromBody ||
      (Array.isArray(body?.errors) &&
        body.errors
          .map((x) => (typeof x?.message === 'string' ? x.message : null))
          .filter(Boolean)
          .join(' ')) ||
      error.message;
    const err = new Error(String(combined || 'Request failed').trim());
    err.status = error.response?.status;
    return err;
  }
  if (error instanceof Error) return error;
  return new Error('Request failed');
}

/**
 * Logs in a visitor and returns the API payload (e.g. `{ token, user }`).
 * Persist `token` with AsyncStorage from the caller after success.
 * @param {string} email
 * @param {string} password
 */
export async function login(email, password) {
  try {
    const { data } = await client.post('/auth/login', { email, password });
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Exchanges a Google ID token for a CustodiCore session (OAuth 2.0 / OpenID Connect).
 * @param {{ idToken: string; accessToken?: string }} payload
 * @returns {Promise<{ token: string; user?: Record<string, unknown> }>}
 */
export async function loginWithGoogle(payload) {
  try {
    const { data } = await client.post('/auth/google', payload);
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Registers a new visitor account (name, email, password, etc. per backend contract).
 * @param {Record<string, unknown>} payload
 */
export async function register(payload) {
  try {
    const { data } = await client.post('/auth/register', payload);
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Uploads an identification document (multipart body in production).
 * @param {FormData} formData — fields such as `documentType`, `expiresAt`, `file`
 */
export async function uploadDocument(formData) {
  try {
    const { data } = await client.post('/documents', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Fetches the visitor’s next assigned visit for dashboard / reminders.
 */
export async function getUpcomingSchedule() {
  try {
    const { data } = await client.get('/visits/upcoming');
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Confirms attendance for a pending schedule the visitor was assigned.
 * @param {string} scheduleId
 */
export async function confirmSchedule(scheduleId) {
  try {
    const { data } = await client.post(`/schedules/${scheduleId}/confirm`);
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Declines a pending schedule (visitor cannot attend).
 * @param {string} scheduleId
 */
export async function declineSchedule(scheduleId) {
  try {
    const { data } = await client.post(`/schedules/${scheduleId}/decline`);
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Returns paginated or list history of past visits for the signed-in visitor.
 */
export async function getVisitHistory() {
  try {
    const { data } = await client.get('/visits/history');
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Returns a short-lived gate token / payload to render as a QR code for a schedule.
 * @param {string} scheduleId
 */
export async function getQrToken(scheduleId) {
  try {
    const { data } = await client.get(
      `/schedules/${encodeURIComponent(scheduleId)}/qr`,
    );
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Returns timeline events for a specific schedule (approvals, check-in, etc.).
 * @param {string} scheduleId
 */
export async function getTimeline(scheduleId) {
  try {
    const { data } = await client.get(`/schedules/${scheduleId}/timeline`);
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Lists in-app notifications for the visitor (approvals, rejections, reminders).
 */
export async function getNotifications() {
  try {
    const { data } = await client.get('/notifications');
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Returns unread notification count for tab badge / polling.
 */
export async function getUnreadNotificationCount() {
  try {
    const { data } = await client.get('/notifications/unread-count');
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Marks a single notification as read (updates badge / unread state server-side).
 * @param {string} notificationId
 */
export async function markNotificationRead(notificationId) {
  try {
    const { data } = await client.patch(`/notifications/${notificationId}/read`);
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

export default client;
