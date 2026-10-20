// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  INTENDED_PDL_NAME_PLACEHOLDER,
  INTENDED_PDL_NAME_REQUIRED_MESSAGE,
  filterIntendedPdlName,
  getIntendedPdlNameError,
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
  assert.equal(INTENDED_PDL_NAME_PLACEHOLDER, 'Enter the name of the PDL you want to visit.');
});
