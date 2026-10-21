// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  ageFromBirthdate,
  inputFilters,
  normalizeAddress,
  normalizeMobile,
  normalizeName,
  validateAddress,
  validateBirthdateAge,
  validateFirstName,
  validateGender,
  validateLastName,
  validateMobileNumber,
  validatePasswordConfirmation,
  validateRegistrationEmail,
  validateRegistrationPassword,
} from './registrationValidation.js';
import { validatePassword } from './validators.js';

test('mobile input stops at 11 digits for 09XXXXXXXXX', () => {
  assert.equal(inputFilters.mobile('0943434343434343'), '09434343434');
  assert.equal(inputFilters.mobile('0917 123 4567 89'), '0917 123 4567 ');
});

test('mobile input stops at 12 digits for 639 / +639', () => {
  assert.equal(inputFilters.mobile('6391712345678999'), '639171234567');
  assert.equal(inputFilters.mobile('+6391712345678'), '+639171234567');
  assert.equal(inputFilters.mobile('+63 917 123 4567'), '+63 917 123 4567');
});

test('mobile input still strips disallowed characters', () => {
  assert.equal(inputFilters.mobile('09a17-123.4567'), '0917-1234567');
});

test('0943434343434343 (16 digits) is rejected on submit', () => {
  assert.notEqual(validateMobileNumber('0943434343434343'), null);
});

test('valid PH mobile formats are accepted', () => {
  for (const n of ['09434343434', '639434343434', '+639434343434', '0917 123 4567', '+63 917 123 4567']) {
    assert.equal(validateMobileNumber(n), null, n);
  }
  assert.equal(normalizeMobile('+639434343434'), '09434343434');
});

test('too-short and wrong-prefix numbers are rejected', () => {
  for (const n of ['', '0943434343', '08434343434', '094343434345']) {
    assert.notEqual(validateMobileNumber(n), null, n);
  }
});

// ── Phase 1 audit regression cases ──────────────────────────────────────────

test('names: valid PH/accented names pass, digits/symbols/emoji fail', () => {
  for (const n of ['Juan', 'José', 'Peñafrancia', 'Ma. Clara', 'dela Cruz', "O'Neil", 'Santos-Reyes', 'A'.repeat(70)]) {
    assert.equal(validateFirstName(n), null, n);
    assert.equal(validateLastName(n), null, n);
  }
  for (const n of ['', '   ', 'Juan2', '12345', 'Ju@n', 'Juan😀', '-Ana', 'Ana-', 'Ju_an']) {
    assert.notEqual(validateFirstName(n), null, n);
    assert.notEqual(validateLastName(n), null, n);
  }
  assert.notEqual(validateFirstName('A'.repeat(71)), null);
  assert.equal(normalizeName('  Juan    Carlos  '), 'Juan Carlos');
  assert.equal(inputFilters.name('A'.repeat(200)).length, 70);
});

test('gender: only Male or Female', () => {
  for (const g of ['Male', 'Female', 'male', 'female']) assert.equal(validateGender(g), null, g);
  for (const g of ['', '   ', 'Other', 'other', 'Prefer not to say', 'robot']) {
    assert.notEqual(validateGender(g), null, g);
  }
});

test('age is worked out from the birthdate (birthday this year or not)', () => {
  const today = new Date(2026, 9, 7);
  assert.equal(ageFromBirthdate('1996-10-07', today), 30);
  assert.equal(ageFromBirthdate('1996-10-08', today), 29);
  assert.equal(ageFromBirthdate(''), null);
});

test('birthdate age range: 1–120 years old', () => {
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const yearsAgo = (n) => `${now.getFullYear() - n}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  for (const n of [1, 30, 120]) assert.equal(validateBirthdateAge(yearsAgo(n)), null, String(n));
  for (const n of [121, 150]) {
    assert.equal(validateBirthdateAge(yearsAgo(n)), 'Please enter a valid birthdate.', String(n));
  }
  // Empty is left to "Birthdate is required."
  assert.equal(validateBirthdateAge(''), null);
});

test('age/DOB: a birthdate of today never passes, leap-day birthdays, non-ISO input', () => {
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const todayIso = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  assert.equal(ageFromBirthdate(todayIso), 0);
  assert.equal(validateBirthdateAge(todayIso), 'Please enter a valid birthdate.'); // below the 1–120 range
  const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
  const yesterdayIso = `${yesterday.getFullYear()}-${pad(yesterday.getMonth() + 1)}-${pad(yesterday.getDate())}`;
  assert.equal(validateBirthdateAge(yesterdayIso), 'Please enter a valid birthdate.'); // age 0
  assert.equal(ageFromBirthdate('2004-02-29', new Date(2026, 1, 28)), 21);
  assert.equal(ageFromBirthdate('2004-02-29', new Date(2026, 2, 1)), 22);
  assert.equal(ageFromBirthdate('15/06/1995'), null);
});

test('email: formats, length limits, Gmail and non-Gmail', () => {
  for (const e of ['juan.cruz@gmail.com', 'juan+tag@gmail.com', 'juan@yahoo.com.ph', 'juan@bjmp.gov.ph', 'a'.repeat(64) + '@x.com']) {
    assert.equal(validateRegistrationEmail(e), null, e);
  }
  for (const e of ['', 'juan.example.com', 'juan@', '@x.com', 'juan@example', 'juan..cruz@x.com', '.juan@x.com', 'a@b@x.com', 'a'.repeat(65) + '@x.com']) {
    assert.notEqual(validateRegistrationEmail(e), null, e);
  }
  assert.equal(inputFilters.email('  juan@x.com  '), 'juan@x.com');
  assert.equal(inputFilters.email('a'.repeat(200)).length, 100);
});

test('mobile: separators, letters and landlines', () => {
  for (const n of ['0943-434-3434', '+63-943-434-3434']) assert.equal(validateMobileNumber(n), null, n);
  for (const n of ['(02) 8123-4567', '0281234567', '+1 415 555 1234', '++639434343434', '0943+4343434']) {
    assert.notEqual(validateMobileNumber(inputFilters.mobile(n)), null, n);
  }
  assert.equal(inputFilters.mobile('abc'), '');
});

// Same cases as the backend's VisitorPasswordPolicyTest.
test('password: valid 12- and 64-character passwords are accepted', () => {
  assert.equal(validateRegistrationPassword('Abcdefgh12!x'), null);
  assert.equal(validateRegistrationPassword(`Aa1!${'x'.repeat(60)}`), null);
  assert.equal(validateRegistrationPassword('Zz9!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~'), null);
});

test('password: every policy rule is enforced', () => {
  const cases = {
    '': 'Password is required.',
    'Abcdefgh1!x': 'Password must be at least 12 characters.',
    [`Aa1!${'x'.repeat(61)}`]: 'Password must be 64 characters or fewer.',
    'abcdefgh123!': 'Password must contain at least one uppercase letter (A-Z).',
    'ABCDEFGH123!': 'Password must contain at least one lowercase letter (a-z).',
    'Abcdefghijk!': 'Password must contain at least one number (0-9).',
    'Abcdefghij12': 'Password must contain at least one special character (such as ! @ # $ %).',
    ' Abcdefgh12!': 'Password cannot contain spaces.',
    'Abcdef gh12!': 'Password cannot contain spaces.',
    'Abcdefgh12! ': 'Password cannot contain spaces.',
    'Abcdef gh123': 'Password cannot contain spaces.',
    'Abcdef\tgh12!': 'Password cannot contain spaces.',
    'Abcdef gh12!': 'Password cannot contain spaces.',
    'Contraseña12!': 'Use only English letters, numbers and standard keyboard symbols.',
    'Abcdefghijk٣!': 'Use only English letters, numbers and standard keyboard symbols.',
  };
  for (const [pw, message] of Object.entries(cases)) {
    assert.equal(validateRegistrationPassword(pw), message, JSON.stringify(pw));
  }
});

test('password: input is never truncated or altered — overlong is rejected instead', () => {
  const long = `Aa1!${'x'.repeat(76)}`;
  assert.equal(inputFilters.password(long), long);
  assert.equal(inputFilters.password(' Ab\tc '), ' Ab\tc ');
  assert.notEqual(validateRegistrationPassword(inputFilters.password(long)), null);
});

test('password confirmation is required and must match exactly', () => {
  assert.equal(validatePasswordConfirmation('Abcdefgh12!x', 'Abcdefgh12!y'), 'Passwords do not match.');
  assert.equal(validatePasswordConfirmation('Abcdefgh12!x', 'abcdefgh12!x'), 'Passwords do not match.');
  assert.equal(validatePasswordConfirmation('Abcdefgh12!x', ''), 'Please confirm your password.');
  assert.equal(validatePasswordConfirmation('Abcdefgh12!x', undefined), 'Please confirm your password.');
  assert.equal(validatePasswordConfirmation('Abcdefgh12!x', 'Abcdefgh12!x'), null);
});

test('login password check stays lenient for existing accounts', () => {
  // Accounts created under the old 6-character rule must still be able to sign in.
  assert.equal(validatePassword('abc123'), true);
  assert.equal(validatePassword(''), false);
});

test('address: required, 255 max, control chars stripped, lines tidied', () => {
  assert.notEqual(validateAddress(''), null);
  assert.notEqual(validateAddress('  \n \n '), null);
  assert.equal(validateAddress('#12 P. Burgos St. (Unit 3-B), Ñ & Co.'), null);
  assert.equal(validateAddress('A'.repeat(255)), null);
  assert.notEqual(validateAddress('A'.repeat(256)), null);
  assert.equal(inputFilters.address('A'.repeat(300)).length, 255);
  assert.equal(inputFilters.address('12\u0000 Rizal\u0007 St‮'), '12 Rizal St');
  assert.equal(normalizeAddress('Blk 1\r\n\n  Lot 2  \nQC'), 'Blk 1\nLot 2\nQC');
});
