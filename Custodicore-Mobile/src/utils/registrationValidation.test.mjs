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

test('password: 6–72 chars, no edge spaces, confirmation must match', () => {
  assert.notEqual(validateRegistrationPassword(''), null);
  assert.notEqual(validateRegistrationPassword('abcde'), null);
  assert.equal(validateRegistrationPassword('abcdef'), null);
  assert.equal(validateRegistrationPassword('a'.repeat(72)), null);
  assert.notEqual(validateRegistrationPassword('a'.repeat(73)), null);
  assert.equal(inputFilters.password('a'.repeat(80)).length, 72);
  assert.notEqual(validateRegistrationPassword(' abcdef'), null);
  assert.notEqual(validateRegistrationPassword('abcdef '), null);
  assert.notEqual(validateRegistrationPassword('      '), null);
  assert.equal(validateRegistrationPassword('abc def'), null);
  assert.equal(validatePasswordConfirmation('abcdef', 'abcdeg'), 'Passwords do not match.');
  assert.notEqual(validatePasswordConfirmation('abcdef', ''), null);
  assert.equal(validatePasswordConfirmation('abcdef', 'abcdef'), null);
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
