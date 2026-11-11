import test from 'node:test';
import assert from 'node:assert/strict';

import { mapChangePasswordErrors, validateChangePasswordForm } from './changePassword.js';

const GOOD = 'NewSecret123!';

test('a complete, valid form has no errors', () => {
  assert.deepEqual(
    validateChangePasswordForm({ currentPassword: 'old', password: GOOD, confirmation: GOOD }),
    {},
  );
});

test('every empty field is reported', () => {
  const errors = validateChangePasswordForm({ currentPassword: '', password: '', confirmation: '' });
  assert.deepEqual(Object.keys(errors).sort(), ['confirmation', 'currentPassword', 'password']);
});

test('the new password follows the registration policy', () => {
  for (const weak of ['Short1!', 'alllowercase123!', 'NoNumbersHere!!', 'NoSymbols12345', 'Has Space123!']) {
    const errors = validateChangePasswordForm({ currentPassword: 'old', password: weak, confirmation: weak });
    assert.ok(errors.password, `expected "${weak}" to be refused`);
  }
});

test('the new password must differ from the current one and the email', () => {
  assert.match(
    validateChangePasswordForm({ currentPassword: GOOD, password: GOOD, confirmation: GOOD }).password,
    /different/,
  );
  const email = 'Strong.Pass1!@x.co';
  assert.match(
    validateChangePasswordForm({ currentPassword: 'old', password: 'STRONG.pass1!@x.co', confirmation: 'STRONG.pass1!@x.co', email }).password ?? '',
    /email/,
  );
});

test('the confirmation must match', () => {
  const errors = validateChangePasswordForm({ currentPassword: 'old', password: GOOD, confirmation: 'NewSecret123?' });
  assert.equal(errors.confirmation, 'Passwords do not match.');
});

test('server 422 errors land on the right fields', () => {
  assert.deepEqual(
    mapChangePasswordErrors({
      currentPassword: ['Your current password is incorrect.'],
      password: ['Too weak.', 'Second message'],
      password_confirmation: ['Confirm your new password.'],
      unknown: ['ignored'],
    }),
    {
      currentPassword: 'Your current password is incorrect.',
      password: 'Too weak.',
      confirmation: 'Confirm your new password.',
    },
  );
  assert.deepEqual(mapChangePasswordErrors(null), {});
});
