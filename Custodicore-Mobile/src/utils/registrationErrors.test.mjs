// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  EMAIL_TAKEN_ALERT,
  EMAIL_TAKEN_FIELD_MESSAGE,
  isEmailTakenError,
  isUnconfirmedRegistrationError,
} from './registrationErrors.js';

/** Shape produced by api.js toRequestError() for a Laravel 422. */
function validationError(errors) {
  const err = new Error(Object.values(errors).flat().join(' '));
  err.status = 422;
  err.errors = errors;
  return err;
}

test('backend unique-email rejection is recognised as an existing account', () => {
  // Exact body returned by POST /api/auth/register for a registered address
  // (including one left unverified by an earlier attempt).
  assert.equal(
    isEmailTakenError(validationError({ email: ['The email has already been taken.'] })),
    true,
  );
  // Alongside other field errors.
  assert.equal(
    isEmailTakenError(
      validationError({
        email: ['The email has already been taken.'],
        contactNumber: ['The contact number field must not be greater than 20 characters.'],
      }),
    ),
    true,
  );
});

test('other errors are not misreported as an existing email', () => {
  const cases = [
    validationError({ email: ['The email field must be a valid email address.'] }),
    validationError({ email: ['The email field must not be greater than 100 characters.'] }),
    validationError({ acceptedTerms: ['You must accept the Terms and Conditions to register.'] }),
    validationError({ consentVersion: ['The Terms and Privacy Policy were updated.'] }),
    Object.assign(new Error('The email has already been taken.'), { status: 500 }),
    Object.assign(new Error('Too Many Attempts.'), { status: 429 }),
    Object.assign(new Error('Unable to reach the server.'), { status: undefined }),
    null,
    undefined,
  ];
  for (const e of cases) assert.equal(isEmailTakenError(e), false, e?.message ?? String(e));
});

test('existing-email copy offers sign-in and does not reveal account state', () => {
  const copy = `${EMAIL_TAKEN_FIELD_MESSAGE} ${EMAIL_TAKEN_ALERT.message}`;
  assert.match(copy, /sign in/i);
  assert.match(copy, /Forgot Password/);
  assert.doesNotMatch(copy, /google|verified account|is verified/i);
});

test('only a timed-out request is treated as possibly created', () => {
  assert.equal(
    isUnconfirmedRegistrationError(Object.assign(new Error('timeout'), { timedOut: true })),
    true,
  );
  // Unreachable server: nothing was created.
  assert.equal(isUnconfirmedRegistrationError(new Error('Unable to reach the server.')), false);
  // Any HTTP response is definitive.
  assert.equal(
    isUnconfirmedRegistrationError(Object.assign(new Error('x'), { status: 422, timedOut: true })),
    false,
  );
  assert.equal(isUnconfirmedRegistrationError(null), false);
});
