import assert from 'node:assert/strict';
import test from 'node:test';

import {
  VISITOR_FULL_NAME_MAX,
  composeVisitorFullName,
  getVisitorNameErrors,
  toVisitorNameFields,
  validateMiddleName,
  visitorNameChanged,
  visitorNamePartsFromUser,
} from './visitorName.js';

const parts = (p) => ({ last: '', first: '', middle: '', suffix: '', ...p });

test('compose: natural order First Middle Last Suffix, like VisitorProfile::composeFullName()', () => {
  assert.equal(
    composeVisitorFullName(parts({ last: 'Dela Cruz', first: 'Juan', middle: 'Santos', suffix: 'Jr.' })),
    'Juan Santos Dela Cruz Jr.',
  );
  assert.equal(composeVisitorFullName(parts({ last: 'Reyes', first: 'Ana' })), 'Ana Reyes');
  assert.equal(composeVisitorFullName(parts({ last: ' De  los Santos ', first: ' Ma.  Clara ' })), 'Ma. Clara De los Santos');
  assert.equal(composeVisitorFullName(parts({ last: 'Cruz', first: 'Pedro', suffix: 'iii' })), 'Pedro Cruz III');
});

test('fields: fullName stays natural; middleName/suffix only when given (older body unchanged)', () => {
  assert.deepEqual(toVisitorNameFields(parts({ last: 'Reyes', first: 'Ana' })), {
    fullName: 'Ana Reyes',
    firstName: 'Ana',
    lastName: 'Reyes',
  });
  assert.deepEqual(toVisitorNameFields(parts({ last: 'Dela Cruz', first: 'Juan', middle: 'Santos', suffix: 'Jr.' })), {
    fullName: 'Juan Santos Dela Cruz Jr.',
    firstName: 'Juan',
    lastName: 'Dela Cruz',
    middleName: 'Santos',
    suffix: 'Jr.',
  });
});

test('errors: last and first required, in field order; middle optional; suffix from the list', () => {
  assert.deepEqual(Object.keys(getVisitorNameErrors(parts({}))), ['lastName', 'firstName']);
  assert.deepEqual(getVisitorNameErrors(parts({ last: 'Reyes', first: 'Ana' })), {});
  assert.ok(getVisitorNameErrors(parts({ last: 'Reyes', first: 'Ana', middle: '123' })).middleName);
  assert.ok(getVisitorNameErrors(parts({ last: 'Reyes', first: 'Ana', suffix: 'Esq.' })).suffix);
  assert.equal(validateMiddleName(''), null);
  assert.equal(validateMiddleName('Dizon'), null);
});

test('errors: the joined name must fit visitor_profiles.full_name', () => {
  const long = 'A'.repeat(60);
  const errors = getVisitorNameErrors(parts({ last: long, first: long, middle: long }));
  assert.match(errors.lastName, new RegExp(String(VISITOR_FULL_NAME_MAX)));
});

test('prefill: stored parts are used; an older full-name-only profile is split as a guess', () => {
  assert.deepEqual(
    visitorNamePartsFromUser({ fullName: 'x', firstName: 'Juan', middleName: null, lastName: 'Dela Cruz', suffix: 'jr' }),
    { last: 'Dela Cruz', first: 'Juan', middle: '', suffix: 'Jr.' },
  );
  assert.deepEqual(visitorNamePartsFromUser({ fullName: 'Juan Santos Dela Cruz Jr.' }), {
    last: 'Dela Cruz',
    first: 'Juan',
    middle: 'Santos',
    suffix: 'Jr.',
  });
  assert.deepEqual(visitorNamePartsFromUser(null), { last: '', first: '', middle: '', suffix: '' });
});

test('changed: only a real edit counts', () => {
  const user = { fullName: 'Ana Reyes', firstName: 'Ana', lastName: 'Reyes' };
  assert.equal(visitorNameChanged(parts({ last: ' Reyes ', first: 'Ana' }), user), false);
  assert.equal(visitorNameChanged(parts({ last: 'Reyes', first: 'Ana', middle: 'B' }), user), true);
  assert.equal(visitorNameChanged(parts({ last: 'Reyes', first: 'Anna' }), user), true);
});
