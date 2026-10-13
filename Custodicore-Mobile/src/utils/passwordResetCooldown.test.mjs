// Run with: npm test  (node --test, no extra dependencies)
//
// These exercise the pure cooldown decision. They do not render
// ForgotPasswordSuccessScreen, so the countdown itself still needs checking
// in the running app.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  PASSWORD_RESET_MAX_COOLDOWN_SECONDS,
  cooldownAfterResendError,
} from './passwordResetCooldown.js';

test('429 without retryAfter uses the default cooldown', () => {
  assert.equal(cooldownAfterResendError({ status: 429 }, 60), 60);
});

test('429 with a longer retryAfter waits for it (rounded up)', () => {
  assert.equal(cooldownAfterResendError({ status: 429, retryAfter: 312.4 }, 60), 313);
});

test('429 with a shorter retryAfter still waits the default', () => {
  assert.equal(cooldownAfterResendError({ status: 429, retryAfter: 5 }, 60), 60);
});

test('429 cooldown is capped', () => {
  assert.equal(
    cooldownAfterResendError({ status: 429, retryAfter: 99999 }, 60),
    PASSWORD_RESET_MAX_COOLDOWN_SECONDS,
  );
});

test('other failures do not start a cooldown', () => {
  assert.equal(cooldownAfterResendError({ status: 500 }, 60), 0);
  assert.equal(cooldownAfterResendError({ status: 422 }, 60), 0);
  assert.equal(cooldownAfterResendError(new Error('Network Error'), 60), 0);
  assert.equal(cooldownAfterResendError(undefined, 60), 0);
});
