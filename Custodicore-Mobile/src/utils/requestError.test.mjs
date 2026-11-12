// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  CONNECTION_MESSAGE,
  SERVER_ERROR_MESSAGE,
  SERVICE_UNAVAILABLE_MESSAGE,
  TIMEOUT_MESSAGE,
  UPLOAD_TOO_LARGE_MESSAGE,
  toRequestError,
} from './requestError.js';

/** Minimal axios error: `response` only when the server answered. */
function axiosError({ code, message = 'Request failed', status, data } = {}) {
  const e = new Error(message);
  e.isAxiosError = true;
  if (code) e.code = code;
  if (status !== undefined) e.response = { status, data };
  return e;
}

test('timeout (ECONNABORTED) gets the timeout message and timedOut flag', () => {
  const err = toRequestError(axiosError({ code: 'ECONNABORTED', message: 'timeout of 20000ms exceeded' }));
  assert.equal(err.message, TIMEOUT_MESSAGE);
  assert.equal(err.timedOut, true);
  assert.equal(err.status, undefined);
});

test('timeout (ETIMEDOUT) gets the timeout message and timedOut flag', () => {
  const err = toRequestError(axiosError({ code: 'ETIMEDOUT' }));
  assert.equal(err.message, TIMEOUT_MESSAGE);
  assert.equal(err.timedOut, true);
});

test('no response for another reason gets the connection message, not timedOut', () => {
  const err = toRequestError(axiosError({ code: 'ERR_NETWORK', message: 'Network Error' }));
  assert.equal(err.message, CONNECTION_MESSAGE);
  assert.equal(err.timedOut, undefined);
  assert.doesNotMatch(err.message, /Wi.?Fi|same network|PC/i);
});

test('503 service_unavailable gets the service-unavailable message and code', () => {
  const err = toRequestError(
    axiosError({ status: 503, data: { message: 'anything', code: 'service_unavailable' } }),
  );
  assert.equal(err.message, SERVICE_UNAVAILABLE_MESSAGE);
  assert.equal(err.status, 503);
  assert.equal(err.code, 'service_unavailable');
  assert.equal(err.timedOut, undefined);
});

test('503 without the code (e.g. maintenance) keeps the generic server message', () => {
  const err = toRequestError(axiosError({ status: 503, data: { message: 'Service Unavailable' } }));
  assert.equal(err.message, SERVER_ERROR_MESSAGE);
});

test('500 keeps the generic server message and never shows server text', () => {
  const err = toRequestError(
    axiosError({ status: 500, data: { message: 'SQLSTATE[42S02]: Base table not found' } }),
  );
  assert.equal(err.message, SERVER_ERROR_MESSAGE);
  assert.equal(err.status, 500);
});

test('other 5xx (502, 504) keep the generic server message', () => {
  for (const status of [502, 504]) {
    assert.equal(toRequestError(axiosError({ status, data: '<html>' })).message, SERVER_ERROR_MESSAGE);
  }
});

test('401 / 403 / 404 / 409 without body text keep their fixed messages', () => {
  const cases = {
    401: 'Your session has expired. Please sign in again.',
    403: 'You do not have access to this resource.',
    404: 'The requested visit or resource was not found.',
    409: 'This visit can no longer be updated.',
  };
  for (const [status, message] of Object.entries(cases)) {
    const err = toRequestError(axiosError({ status: Number(status), data: {} }));
    assert.equal(err.message, message);
    assert.equal(err.status, Number(status));
    assert.equal(err.timedOut, undefined);
  }
});

test('413 from the web server (HTML body) says the upload is too large', () => {
  const err = toRequestError(
    axiosError({ status: 413, message: 'Request failed with status code 413', data: '<html>413 Request Entity Too Large</html>' }),
  );
  assert.equal(err.message, UPLOAD_TOO_LARGE_MESSAGE);
  assert.equal(err.status, 413);
});

test('backend message and code win over the fixed text (e.g. 409 open_request_exists)', () => {
  const err = toRequestError(
    axiosError({ status: 409, data: { message: 'A request is already waiting.', code: 'open_request_exists' } }),
  );
  assert.equal(err.message, 'A request is already waiting.');
  assert.equal(err.code, 'open_request_exists');
});

test('422 flattens Laravel validation errors and keeps them on err.errors', () => {
  const errors = { email: ['The email field is required.'], password: ['Too short.'] };
  const err = toRequestError(axiosError({ status: 422, data: { message: 'Invalid', errors } }));
  assert.equal(err.message, 'The email field is required. Too short.');
  assert.equal(err.status, 422);
  assert.deepEqual(err.errors, errors);
});

test('429 keeps the backend message and retryAfter', () => {
  const err = toRequestError(
    axiosError({ status: 429, data: { message: 'Too Many Attempts.', retryAfter: 42 } }),
  );
  assert.equal(err.message, 'Too Many Attempts.');
  assert.equal(err.status, 429);
  assert.equal(err.retryAfter, 42);
});

test('non-axios errors pass through unchanged', () => {
  const original = Object.assign(new Error('Server not configured.'), { code: 'server_not_configured' });
  assert.equal(toRequestError(original), original);
  assert.equal(toRequestError('boom').message, 'Request failed');
});
