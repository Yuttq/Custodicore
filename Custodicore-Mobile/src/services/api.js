import AsyncStorage from '@react-native-async-storage/async-storage';
import axios from 'axios';
import { Platform } from 'react-native';

import {
  SERVER_NOT_CONFIGURED_CODE,
  SERVER_NOT_CONFIGURED_MESSAGE,
  resolveApiBaseUrl,
} from '../utils/apiBaseUrl';
import { toRequestError } from '../utils/requestError';

/**
 * Visitor API base URL.
 * Development: set `EXPO_PUBLIC_API_URL` in `.env` to `http://YOUR-PC-LAN-IP:8000/api` (no trailing slash).
 * EAS preview / production builds: set it as an EAS environment variable for the
 * build profile's environment (see eas.json); `.env` is not uploaded to EAS. It must be https.
 * The web build uses `EXPO_PUBLIC_WEB_API_URL` when set; otherwise `EXPO_PUBLIC_API_URL`
 * with the Android-emulator-only host `10.0.2.2` swapped for `127.0.0.1`, which a
 * browser on the same computer can reach.
 * Paths below are relative to that base (e.g. `/auth/login` → `…/api/auth/login`).
 *
 * Without a usable URL every request fails with SERVER_NOT_CONFIGURED_MESSAGE
 * instead of being sent anywhere.
 */
// Static `process.env.EXPO_PUBLIC_*` access: Expo only inlines that exact form at build time.
const API_CONFIG = resolveApiBaseUrl({
  nativeUrl: process.env.EXPO_PUBLIC_API_URL,
  webUrl: process.env.EXPO_PUBLIC_WEB_API_URL,
  platform: Platform.OS,
  isRelease: !__DEV__,
});

const BASE_URL = API_CONFIG.baseUrl ?? '';

if (API_CONFIG.problem) {
  console.error(`CustodiCore API URL is ${API_CONFIG.problem}: check EXPO_PUBLIC_API_URL for this build.`);
}

export const TOKEN_KEY = '@custodicore/auth_token';

/** Uploads can be slow on mobile data (files up to 10 MB). */
const UPLOAD_TIMEOUT_MS = 90000;

// axios.create is the documented API; eslint-plugin-import flags default.create.
// eslint-disable-next-line import/no-named-as-default-member -- axios public API
const client = axios.create({
  baseURL: BASE_URL,
  timeout: 20000,
  headers: { 'Content-Type': 'application/json' },
  // Laravel validation errors use Accept application/json
  validateStatus: (status) => status >= 200 && status < 300,
});

/**
 * Stops the request when the build has no usable API URL, and attaches
 * `Authorization: Bearer <token>` when a stored session exists.
 */
client.interceptors.request.use(async (config) => {
  if (!API_CONFIG.baseUrl) {
    const err = new Error(SERVER_NOT_CONFIGURED_MESSAGE);
    err.code = SERVER_NOT_CONFIGURED_CODE;
    throw err;
  }
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
    // FormData (multipart) when relationship documents are attached.
    const isMultipart = typeof FormData !== 'undefined' && payload instanceof FormData;
    const { data } = await client.post(
      '/auth/google/register',
      payload,
      isMultipart
        ? { headers: { 'Content-Type': 'multipart/form-data' }, timeout: UPLOAD_TIMEOUT_MS }
        : undefined,
    );
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Registers a new visitor account — POST /auth/register. Never signs in:
 * returns `{ status: 'verification_required', message, email }` (201) and the
 * backend emails a verification link. A FormData body (multipart) carries the
 * government ID picked during registration (governmentId + governmentIdType).
 * @param {Record<string, unknown> | FormData} payload
 */
export async function register(payload) {
  try {
    const isMultipart = typeof FormData !== 'undefined' && payload instanceof FormData;
    const { data } = await client.post(
      '/auth/register',
      payload,
      isMultipart
        ? { headers: { 'Content-Type': 'multipart/form-data' }, timeout: UPLOAD_TIMEOUT_MS }
        : undefined,
    );
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Requests another email verification link — POST /auth/email/resend.
 * The backend answers the same way whether or not the address is registered.
 * @param {string} email
 */
export async function resendVerificationEmail(email) {
  try {
    const { data } = await client.post('/auth/email/resend', { email });
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Requests a password reset link — POST /auth/password/forgot. The backend
 * answers the same way whether or not the address is registered; the link
 * opens a web page where the visitor chooses a new password.
 * Returns `{ status, message }`; 429 when requested too often.
 * @param {string} email
 */
export async function requestPasswordReset(email) {
  try {
    const { data } = await client.post('/auth/password/forgot', { email });
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

/**
 * Help Center contact set by the Admin — GET /api/facility-contact.
 * Returns `{ contact: { name, phone, hours } | null }`.
 */
export async function getFacilityContact() {
  try {
    const { data } = await client.get('/facility-contact');
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Changes the signed-in visitor's password — POST /api/me/password.
 * Other devices are signed out; this one stays signed in. 422 for a wrong
 * current password or a new one that fails the policy, 429 when locked.
 * @param {{ currentPassword: string; password: string; passwordConfirmation: string }} fields
 */
export async function changePassword({ currentPassword, password, passwordConfirmation }) {
  try {
    const { data } = await client.post('/me/password', {
      currentPassword,
      password,
      password_confirmation: passwordConfirmation,
    });
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

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
 * Uploads the file for one checklist requirement of the visitor's own
 * relationship — POST /api/relationships/{relationshipId}/requirements/{requirementKey}
 * (multipart: file). Recorded in relationship_documents, which is what the
 * Record Officer's checklist reviews. (The older single-file
 * /supporting-document endpoint is kept on the backend for old app builds.)
 * @param {string} relationshipId
 * @param {string} requirementKey — backend checklist key, e.g. marriage_certificate
 * @param {FormData} formData
 */
export async function uploadRelationshipRequirementDocument(relationshipId, requirementKey, formData) {
  try {
    const { data } = await client.post(
      `/relationships/${encodeURIComponent(relationshipId)}/requirements/${encodeURIComponent(requirementKey)}`,
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
 * Replaces a rejected document sent with the registration — POST
 * /api/registration-documents/{requirementKey} (multipart: file). Pending or
 * returned accounts only; the new file waits for staff review.
 * @param {string} requirementKey — e.g. marriage_certificate
 * @param {FormData} formData
 */
export async function uploadRegistrationDocument(requirementKey, formData) {
  try {
    const { data } = await client.post(
      `/registration-documents/${encodeURIComponent(requirementKey)}`,
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
 * Takes or retakes the visitor's own photo — POST /api/me/photo (multipart:
 * photo). Refused (409) once the account is approved.
 * @param {FormData} formData
 */
export async function uploadVisitorPhoto(formData) {
  try {
    const { data } = await client.post('/me/photo', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: UPLOAD_TIMEOUT_MS,
    });
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Phase 5B: ask facility staff to add another PDL — POST /api/pdl-requests
 * (multipart: pdlName, relationshipType, hasChildrenTogether?, documents[key]).
 * Approved visitors only; 409 `open_request_exists` while one is open.
 * @param {FormData} formData
 * @returns {Promise<{ pdlRequest: object }>}
 */
export async function createPdlRequest(formData) {
  try {
    const { data } = await client.post('/pdl-requests', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: UPLOAD_TIMEOUT_MS,
    });
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Cancels the visitor's own open PDL request — POST /api/pdl-requests/{id}/cancel.
 * 409 when staff already linked or rejected it.
 * @param {string} requestId
 * @returns {Promise<{ pdlRequest: object }>}
 */
export async function cancelPdlRequest(requestId) {
  try {
    const { data } = await client.post(`/pdl-requests/${encodeURIComponent(requestId)}/cancel`);
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

/**
 * Visit-slot availability for the visitor's own verified PDL relationships —
 * GET /api/schedules/availability (approved visitors only). Read-only: it
 * reserves nothing.
 * @param {{ from?: string; to?: string; relationshipId?: string }} [params]
 *   from / to are YYYY-MM-DD; omitted values use the backend defaults.
 */
export async function getScheduleAvailability(params = {}) {
  try {
    const query = {};
    if (params.from) query.from = params.from;
    if (params.to) query.to = params.to;
    if (params.relationshipId) query.relationshipId = params.relationshipId;
    const { data } = await client.get('/schedules/availability', { params: query });
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Submits a visit request for one of the visitor's verified PDL relationships —
 * POST /api/visit-requests. The backend re-checks eligibility, availability,
 * capacity and duplicates; on success it returns the new visit (201) with
 * status `assigned` (awaiting staff review).
 * @param {{ relationshipId: string | number; date: string; startTime: string; startTimes?: string[] }} params
 *   date is YYYY-MM-DD, startTime is HH:MM (the slot's start). startTimes
 *   (whole day) lists every session start; the backend books them as ONE visit.
 */
export async function createVisitRequest({ relationshipId, date, startTime, startTimes }) {
  try {
    const sessions =
      Array.isArray(startTimes) && startTimes.length > 1 ? { startTimes } : { startTime };
    const { data } = await client.post('/visit-requests', {
      relationshipId: Number(relationshipId),
      date,
      ...sessions,
    });
    return data;
  } catch (error) {
    throw toRequestError(error);
  }
}

/**
 * Facility announcements for the Home screen — GET /api/announcements.
 * @returns {Promise<{ announcements: { id, title, body, category, pinned, createdAt }[] }>}
 */
export async function getAnnouncements() {
  try {
    const { data } = await client.get('/announcements');
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
