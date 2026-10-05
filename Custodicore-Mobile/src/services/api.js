import AsyncStorage from '@react-native-async-storage/async-storage';
import axios, { isAxiosError } from 'axios';

/**
 * Visitor API base URL.
 * Set `EXPO_PUBLIC_API_URL` in `.env` to `http://YOUR-PC-LAN-IP:8000/api` (no trailing slash).
 * Paths below are relative to that base (e.g. `/auth/login` → `…/api/auth/login`).
 */
const BASE_URL = (
  (typeof process !== 'undefined' && process.env?.EXPO_PUBLIC_API_URL) ||
    'https://api.custodicore.placeholder'
).replace(/\/$/, '');

export const TOKEN_KEY = '@custodicore/auth_token';

// axios.create is the documented API; eslint-plugin-import flags default.create.
// eslint-disable-next-line import/no-named-as-default-member -- axios public API
const client = axios.create({
  baseURL: BASE_URL,
  timeout: 20000,
  headers: { 'Content-Type': 'application/json' },
  // Laravel validation errors use Accept application/json
  validateStatus: (status) => status >= 200 && status < 300,
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
 * Flatten Laravel `{ errors: { field: ["msg"] } }` into a single message.
 * @param {unknown} body
 * @returns {string|null}
 */
function formatLaravelErrors(body) {
  if (!body || typeof body !== 'object') return null;
  if (typeof body.message === 'string' && body.message.trim() && !body.errors) {
    return body.message.trim();
  }
  const errors = body.errors;
  if (errors && typeof errors === 'object' && !Array.isArray(errors)) {
    const parts = Object.values(errors)
      .flat()
      .map((x) => (typeof x === 'string' ? x : null))
      .filter(Boolean);
    if (parts.length) return parts.join(' ');
  }
  if (typeof body.message === 'string' && body.message.trim()) {
    return body.message.trim();
  }
  if (typeof body.error === 'string' && body.error.trim()) {
    return body.error.trim();
  }
  return null;
}

/**
 * Normalizes axios failures into a plain `Error` for alerts and logging.
 * @param {unknown} error
 * @returns {Error & { status?: number; code?: string; errors?: Record<string, string[]> }}
 */
function toRequestError(error) {
  if (isAxiosError(error)) {
    const status = error.response?.status;
    const body = error.response?.data;
    // Never surface raw server exception text (Laravel debug messages) to visitors.
    let message =
      status >= 500
        ? 'The server ran into a problem. Please try again in a moment.'
        : formatLaravelErrors(body);

    if (!message) {
      if (status === 401) message = 'Your session has expired. Please sign in again.';
      else if (status === 403) message = 'You do not have access to this resource.';
      else if (status === 404) message = 'The requested visit or resource was not found.';
      else if (status === 409) message = 'This visit can no longer be updated.';
      else if (!error.response) {
        message =
          'Unable to reach the server. Check that your phone and PC are on the same Wi‑Fi and the API URL is correct.';
      } else {
        message = error.message || 'Request failed';
      }
    }

    const err = new Error(String(message).trim());
    err.status = status;
    // Machine-readable backend code (e.g. Google auth `link_required`).
    if (typeof body?.code === 'string' && body.code) {
      err.code = body.code;
    }
    if (body?.errors && typeof body.errors === 'object') {
      err.errors = body.errors;
    }
    return err;
  }
  if (error instanceof Error) return error;
  return new Error('Request failed');
}

export async function persistToken(token) {
  if (token) {
    await AsyncStorage.setItem(TOKEN_KEY, String(token));
  }
}

export async function clearStoredToken() {
  await AsyncStorage.removeItem(TOKEN_KEY);
}

export async function getStoredToken() {
  return AsyncStorage.getItem(TOKEN_KEY);
}

/**
 * Logs in a visitor and returns `{ token, user }`.
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
 * Exchanges a Google ID token for a CustodiCore decision — POST /auth/google.
 * Only the ID token is sent; the backend reads identity from the verified token.
 * Returns `{ status: 'authenticated', token, user }` or
 * `{ status: 'registration_required', profile: { email, fullName }, consentVersion }`.
 * Other outcomes arrive as errors with `err.code` (e.g. `link_required`).
 * @param {string} idToken
 */
export async function loginWithGoogle(idToken) {
  try {
    const { data } = await client.post('/auth/google', { idToken });
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Links a Google identity to the existing visitor account after `/auth/google`
 * answered `link_required` — POST /auth/google/link. Returns `{ status, token, user }`.
 * @param {{ idToken: string; password: string }} payload
 */
export async function linkGoogleAccount({ idToken, password }) {
  try {
    const { data } = await client.post('/auth/google/link', { idToken, password });
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Registers a new visitor through Google after `/auth/google` answered
 * `registration_required` — POST /auth/google/register. The body carries the
 * Google ID token plus the registration form (no email: the backend takes
 * email and Google ID only from the verified token). Returns
 * `{ status: 'authenticated', token, user }` (201).
 * @param {Record<string, unknown> & { idToken: string }} payload
 */
export async function registerWithGoogle(payload) {
  try {
    const { data } = await client.post('/auth/google/register', payload);
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Registers a new visitor account.
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
 * Terms & Conditions + Privacy Policy text (public — shown BEFORE the visitor
 * has an account) — GET /api/legal. Same wording as the web /terms and
 * /privacy pages (config/legal.php on the backend).
 * @returns {Promise<{ version: string, effectiveDate: string, draft: boolean,
 *   terms: LegalDoc, privacy: LegalDoc }>}
 *   where LegalDoc = { title, intro, sections: { heading, body: string[] }[] }
 */
export async function getLegal() {
  try {
    const { data } = await client.get('/legal');
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Authenticated visitor profile — GET /api/me
 */
export async function getMe() {
  try {
    const { data } = await client.get('/me');
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Revokes the current Sanctum token — POST /api/auth/logout
 */
export async function logout() {
  try {
    const { data } = await client.post('/auth/logout');
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Updates the visitor's own editable profile fields — PATCH /api/me
 * @param {Record<string, unknown>} fields
 */
export async function updateMe(fields) {
  try {
    const { data } = await client.patch('/me', fields);
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/** Uploads can be slow on mobile data (files up to 10 MB). */
const UPLOAD_TIMEOUT_MS = 90000;

/**
 * Uploads a government ID — POST /api/documents (multipart: documentType, file, idNumber?)
 * @param {FormData} formData
 */
export async function uploadDocument(formData) {
  try {
    const { data } = await client.post('/documents', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: UPLOAD_TIMEOUT_MS,
    });
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * The visitor's own government IDs and relationship document state — GET /api/documents
 */
export async function getDocuments() {
  try {
    const { data } = await client.get('/documents');
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Uploads the supporting document for one of the visitor's own relationships —
 * POST /api/relationships/{relationshipId}/supporting-document (multipart: file)
 * @param {string} relationshipId
 * @param {FormData} formData
 */
export async function uploadSupportingDocument(relationshipId, formData) {
  try {
    const { data } = await client.post(
      `/relationships/${encodeURIComponent(relationshipId)}/supporting-document`,
      formData,
      {
        headers: { 'Content-Type': 'multipart/form-data' },
        timeout: UPLOAD_TIMEOUT_MS,
      },
    );
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Full visit list for the authenticated visitor — GET /api/visits
 * @returns {Promise<{ visits: object[] }>}
 */
export async function getVisits() {
  try {
    const { data } = await client.get('/visits');
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
 * Confirms attendance for a pending visit request.
 * Backend route: POST /api/schedules/{visitRequest}/confirm
 * (`scheduleId` in the mobile app is visit_requests.visit_request_id.)
 * @param {string} scheduleId
 */
export async function confirmSchedule(scheduleId) {
  try {
    const { data } = await client.post(
      `/schedules/${encodeURIComponent(scheduleId)}/confirm`,
    );
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Declines a pending visit request.
 * Backend route: POST /api/schedules/{visitRequest}/decline
 * @param {string} scheduleId
 * @param {{ reason?: string }=} options
 */
export async function declineSchedule(scheduleId, options = {}) {
  try {
    const body = {};
    if (options.reason) body.reason = options.reason;
    const { data } = await client.post(
      `/schedules/${encodeURIComponent(scheduleId)}/decline`,
      body,
    );
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Returns list history of past visits for the signed-in visitor.
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
 * Returns timeline events for a specific schedule.
 * @param {string} scheduleId
 */
export async function getTimeline(scheduleId) {
  try {
    const { data } = await client.get(
      `/schedules/${encodeURIComponent(scheduleId)}/timeline`,
    );
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

export async function getNotifications() {
  try {
    const { data } = await client.get('/notifications');
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

export async function getUnreadNotificationCount() {
  try {
    const { data } = await client.get('/notifications/unread-count');
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

export async function markNotificationRead(notificationId) {
  try {
    const { data } = await client.patch(
      `/notifications/${encodeURIComponent(notificationId)}/read`,
    );
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

export { BASE_URL };
export default client;
