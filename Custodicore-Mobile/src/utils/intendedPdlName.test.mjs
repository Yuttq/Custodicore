// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  INTENDED_PDL_NAME_LIMITS,
  INTENDED_PDL_NAME_PLACEHOLDER,
  INTENDED_PDL_NAME_REQUIRED_MESSAGE,
  PDL_NAME_PART_LIMITS,
  PDL_NAME_SUFFIXES,
  canonicalPdlSuffix,
  composeIntendedPdlName,
  filterIntendedPdlName,
  filterPdlNamePart,
  getIntendedPdlNameError,
  getPdlBirthDateError,
  getPdlNamePartsErrors,
  splitPdlNameForPrefill,
  toIntendedPdlBirthDateField,
  toIntendedPdlNameField,
} from './intendedPdlName.js';

test('the field is required', () => {
  assert.equal(INTENDED_PDL_NAME_REQUIRED_MESSAGE, 'Please enter the name of the PDL you want to visit.');
  assert.equal(getIntendedPdlNameError(''), INTENDED_PDL_NAME_REQUIRED_MESSAGE);
  assert.equal(getIntendedPdlNameError('   '), INTENDED_PDL_NAME_REQUIRED_MESSAGE);
  assert.equal(getIntendedPdlNameError(undefined), INTENDED_PDL_NAME_REQUIRED_MESSAGE);
  assert.deepEqual(toIntendedPdlNameField('  '), {});
});

test('name rules match the backend', () => {
  assert.equal(getIntendedPdlNameError("Ma. Clara O'Neil-Santos"), null);
  assert.equal(getIntendedPdlNameError('José Ñuñez'), null);
  assert.ok(getIntendedPdlNameError('A'));
  assert.ok(getIntendedPdlNameError('Pedro 123'));
  assert.ok(getIntendedPdlNameError('Pedro #1'));
  assert.ok(getIntendedPdlNameError('a'.repeat(151)));
});

test('the body field is the cleaned name', () => {
  assert.deepEqual(toIntendedPdlNameField(' Pedro   Reyes '), { intendedPdlName: 'Pedro Reyes' });
});

test('input is capped at 150 characters', () => {
  assert.equal(filterIntendedPdlName('a'.repeat(200)).length, 150);
});

test('placeholder text', () => {
  assert.equal(INTENDED_PDL_NAME_PLACEHOLDER, 'Full name of the PDL');
});

// --- Name parts ------------------------------------------------------------

const parts = (p) => ({ first: '', middle: '', last: '', suffix: '', noMiddleName: false, ...p });

test('the suffixes are the backend list', () => {
  assert.deepEqual(PDL_NAME_SUFFIXES, ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V']);
  assert.deepEqual(PDL_NAME_PART_LIMITS, { first: 50, middle: 40, last: 50 });
});

test('compose: First Middle Last Suffix, like Pdl::composeFullName()', () => {
  assert.equal(composeIntendedPdlName(parts({ first: 'Juan', middle: 'Santos', last: 'Dela Cruz', suffix: 'Jr.' })), 'Juan Santos Dela Cruz Jr.');
  assert.equal(composeIntendedPdlName(parts({ first: 'Juan', last: 'Dela Cruz', noMiddleName: true })), 'Juan Dela Cruz');
  assert.equal(composeIntendedPdlName(parts({ first: 'Pedro', middle: 'S.', last: 'Reyes', suffix: 'III' })), 'Pedro S. Reyes III');
});

test('compose: a typed middle name is dropped (not replaced) when "No middle name" is ticked', () => {
  assert.equal(composeIntendedPdlName(parts({ first: 'Juan', middle: 'Santos', last: 'Reyes', noMiddleName: true })), 'Juan Reyes');
});

test('compose: spaces are trimmed and collapsed; compound surnames kept whole', () => {
  assert.equal(composeIntendedPdlName(parts({ first: '  Ma.  Clara ', middle: ' De  la Paz', last: ' De los   Santos ' })), 'Ma. Clara De la Paz De los Santos');
});

test('compose: no suffix, or an unknown one, adds nothing', () => {
  assert.equal(composeIntendedPdlName(parts({ first: 'Ana', middle: 'B', last: 'Cruz', suffix: '' })), 'Ana B Cruz');
  assert.equal(composeIntendedPdlName(parts({ first: 'Ana', middle: 'B', last: 'Cruz', suffix: 'Esq.' })), 'Ana B Cruz');
});

test('compose: the longest allowed parts still fit the backend limit', () => {
  const longest = composeIntendedPdlName(parts({ first: 'a'.repeat(50), middle: 'b'.repeat(40), last: 'c'.repeat(50), suffix: 'III' }));
  assert.ok(longest.length <= INTENDED_PDL_NAME_LIMITS.max, `${longest.length} > 150`);
  assert.equal(getIntendedPdlNameError(longest), null);
});

test('parts: first and last are required', () => {
  const e = getPdlNamePartsErrors(parts({ middle: 'Santos' }));
  assert.ok(e.pdlFirstName);
  assert.ok(e.pdlLastName);
  assert.equal(e.pdlMiddleName, undefined);
  assert.ok(getPdlNamePartsErrors(parts({ first: '  ', middle: 'S', last: 'Cruz' })).pdlFirstName);
});

test('parts: the middle name is required unless "No middle name / Not sure" is ticked', () => {
  assert.ok(getPdlNamePartsErrors(parts({ first: 'Juan', last: 'Cruz' })).pdlMiddleName);
  assert.deepEqual(getPdlNamePartsErrors(parts({ first: 'Juan', last: 'Cruz', noMiddleName: true })), {});
  // Ticked: whatever is left in the hidden field is ignored.
  assert.deepEqual(getPdlNamePartsErrors(parts({ first: 'Juan', middle: '123', last: 'Cruz', noMiddleName: true })), {});
  assert.deepEqual(getPdlNamePartsErrors(parts({ first: 'Juan', middle: 'S.', last: 'Cruz' })), {});
});

test('parts: same characters as the backend name rule', () => {
  assert.deepEqual(getPdlNamePartsErrors(parts({ first: 'Ma.', middle: "O'Neil", last: 'Ñuñez-José' })), {});
  const bad = getPdlNamePartsErrors(parts({ first: 'Juan1', middle: 'S#', last: '-Cruz' }));
  assert.ok(bad.pdlFirstName);
  assert.ok(bad.pdlMiddleName);
  assert.ok(bad.pdlLastName);
});

test('parts: 50 / 40 / 50 character limits', () => {
  assert.deepEqual(getPdlNamePartsErrors(parts({ first: 'a'.repeat(50), middle: 'b'.repeat(40), last: 'c'.repeat(50) })), {});
  const e = getPdlNamePartsErrors(parts({ first: 'a'.repeat(51), middle: 'b'.repeat(41), last: 'c'.repeat(51) }));
  assert.equal(e.pdlFirstName, 'Use 50 characters or fewer.');
  assert.equal(e.pdlMiddleName, 'Use 40 characters or fewer.');
  assert.equal(e.pdlLastName, 'Use 50 characters or fewer.');
});

test('parts: the suffix must come from the list', () => {
  assert.deepEqual(getPdlNamePartsErrors(parts({ first: 'J', middle: 'S', last: 'C', suffix: 'IV' })), {});
  assert.ok(getPdlNamePartsErrors(parts({ first: 'J', middle: 'S', last: 'C', suffix: 'Esq.' })).pdlSuffix);
});

test('canonical suffix spelling', () => {
  assert.equal(canonicalPdlSuffix('jr'), 'Jr.');
  assert.equal(canonicalPdlSuffix('SR.'), 'Sr.');
  assert.equal(canonicalPdlSuffix('Iii'), 'III');
  assert.equal(canonicalPdlSuffix('v'), 'V');
  assert.equal(canonicalPdlSuffix('Cruz'), null);
  assert.equal(canonicalPdlSuffix(''), null);
});

test('filterPdlNamePart strips control characters but never cuts a value', () => {
  assert.equal(filterPdlNamePart('Juan\u0000\n'), 'Juan ');
  assert.equal(filterPdlNamePart('a'.repeat(80)).length, 80);
});

// --- Request Again prefill ---------------------------------------------------

test('prefill split: first / middle / last / suffix', () => {
  assert.deepEqual(splitPdlNameForPrefill('Juan Santos Dela Cruz Jr.'), parts({ first: 'Juan', middle: 'Santos', last: 'Dela Cruz', suffix: 'Jr.' }));
  assert.deepEqual(splitPdlNameForPrefill('Pedro Reyes'), parts({ first: 'Pedro', last: 'Reyes', noMiddleName: true }));
  assert.deepEqual(splitPdlNameForPrefill('Juan De los Santos'), parts({ first: 'Juan', last: 'De los Santos', noMiddleName: true }));
  assert.deepEqual(splitPdlNameForPrefill('Juan Dela Cruz Santos'), parts({ first: 'Juan', middle: 'Dela Cruz', last: 'Santos' }));
});

test('prefill split: a two-word first name is a visible guess the visitor can correct', () => {
  assert.deepEqual(splitPdlNameForPrefill('Maria Clara Santos'), parts({ first: 'Maria', middle: 'Clara', last: 'Santos' }));
});

test('prefill split: one word, empty, and "PDL" placeholders', () => {
  // One word: no last name and the box is NOT ticked — the visitor decides.
  assert.deepEqual(splitPdlNameForPrefill('Juan'), parts({ first: 'Juan' }));
  assert.deepEqual(splitPdlNameForPrefill(''), parts({}));
  assert.deepEqual(splitPdlNameForPrefill(null), parts({}));
  // A lone suffix-looking word stays the first name.
  assert.deepEqual(splitPdlNameForPrefill('V'), parts({ first: 'V' }));
});

test('prefill split: joining an unedited prefill gives the same name back', () => {
  for (const name of [
    'Juan Dela Cruz',
    'Juan Santos Dela Cruz Jr.',
    'Juan De los Santos',
    'Juan Santos De La Cruz III',
    'Maria Clara Santos',
    "Ma. Clara O'Neil-Santos",
    'Juan Dela Cruz Santos Sr.',
    'José Ñuñez',
  ]) {
    assert.equal(composeIntendedPdlName(splitPdlNameForPrefill(name)), name, name);
  }
  // Only the suffix spelling is corrected (old "Iii" records).
  assert.equal(composeIntendedPdlName(splitPdlNameForPrefill('Juan Dela Cruz Iii')), 'Juan Dela Cruz III');
});

// --- PDL's date of birth ---------------------------------------------------

test('birth date: required unless "I don\'t know the exact date" is ticked', () => {
  assert.ok(getPdlBirthDateError('', false));
  assert.ok(getPdlBirthDateError(null, false));
  assert.equal(getPdlBirthDateError('', true), null);
  assert.equal(getPdlBirthDateError('1991-11-23', false), null);
  assert.ok(getPdlBirthDateError('11/23/1991', false));
});

test('birth date: sent only as a real YYYY-MM-DD, never made up', () => {
  assert.deepEqual(toIntendedPdlBirthDateField('1991-11-23'), { intendedPdlBirthDate: '1991-11-23' });
  assert.deepEqual(toIntendedPdlBirthDateField(''), {});
  assert.deepEqual(toIntendedPdlBirthDateField(null), {});
  assert.deepEqual(toIntendedPdlBirthDateField(undefined), {});
  assert.deepEqual(toIntendedPdlBirthDateField('11/23/1991'), {});
});
