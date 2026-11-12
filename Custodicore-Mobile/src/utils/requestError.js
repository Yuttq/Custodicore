/**
 * Turns an axios failure into a plain `Error` for alerts and logging.
 * Used by every request in `src/services/api.js`.
 *
 * Pure functions only (no React Native / axios imports) so they can be unit
 * tested with `node --test`.
 */

export const TIMEOUT_MESSAGE = 'The request took too long. Please try again.';
export const CONNECTION_MESSAGE =
  'Unable to connect to the server. Please check your internet connection and try again.';
export const SERVICE_UNAVAILABLE_MESSAGE = 'Service temporarily unavailable. Please try again later.';
export const SERVER_ERROR_MESSAGE = 'The server ran into a problem. Please try again in a moment.';
export const UPLOAD_TOO_LARGE_MESSAGE =
  'Your photos or documents are too large to upload. Please use smaller images and try again.';

/** Backend code sent with 503 when its database cannot be reached (bootstrap/app.php). */
export const SERVICE_UNAVAILABLE_CODE = 'service_unavailable';

/** axios codes for a request that got no response before its timeout. */
const TIMEOUT_CODES = new Set(['ECONNABORTED', 'ETIMEDOUT']);

/** Same check as axios's `isAxiosError`, without importing axios. */
function isAxiosError(error) {
  return Boolean(error) && typeof error === 'object' && error.isAxiosError === true;
}

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
 * @param {unknown} error
 * @returns {Error & { status?: number; code?: string; errors?: Record<string, string[]>; timedOut?: boolean; retryAfter?: number }}
 */
export function toRequestError(error) {
  if (isAxiosError(error)) {
    const status = error.response?.status;
    const body = error.response?.data;
    // No response at all: a timeout is told apart from other connection failures.
    const timedOut = !error.response && TIMEOUT_CODES.has(error.code);

    let message;
    if (!error.response) {
      message = timedOut ? TIMEOUT_MESSAGE : CONNECTION_MESSAGE;
    } else if (status === 503 && body?.code === SERVICE_UNAVAILABLE_CODE) {
      message = SERVICE_UNAVAILABLE_MESSAGE;
    } else if (status >= 500) {
      // Never surface raw server exception text (Laravel debug messages) to visitors.
      message = SERVER_ERROR_MESSAGE;
    } else {
      message = formatLaravelErrors(body);
      if (!message) {
        if (status === 401) message = 'Your session has expired. Please sign in again.';
        else if (status === 403) message = 'You do not have access to this resource.';
        else if (status === 404) message = 'The requested visit or resource was not found.';
        else if (status === 409) message = 'This visit can no longer be updated.';
        // The web server rejects an oversized upload before Laravel sees it.
        else if (status === 413) message = UPLOAD_TOO_LARGE_MESSAGE;
        else message = error.message || 'Request failed';
      }
    }

    const err = new Error(String(message).trim());
    err.status = status;
    // Timed out waiting for a response: the server may still have processed it.
    if (timedOut) {
      err.timedOut = true;
    }
    // Machine-readable backend code (e.g. Google auth `link_required`, `service_unavailable`).
    if (typeof body?.code === 'string' && body.code) {
      err.code = body.code;
    }
    if (body?.errors && typeof body.errors === 'object') {
      err.errors = body.errors;
    }
    // Seconds until a rate-limited (429) request may be retried, when the backend says.
    if (Number.isFinite(body?.retryAfter)) {
      err.retryAfter = body.retryAfter;
    }
    return err;
  }
  if (error instanceof Error) return error;
  return new Error('Request failed');
}
