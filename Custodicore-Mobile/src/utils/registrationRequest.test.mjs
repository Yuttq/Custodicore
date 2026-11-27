// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { buildRegisterBody, getRegistrationRelationshipFiles } from './registrationRequest.js';

/** RegisterScreen payload (as passed to useAuth.register()). */
function payload(overrides = {}) {
  return {
    fullName: 'Ana Reyes',
    firstName: 'Ana',
    lastName: 'Reyes',
    email: 'ana@example.com',
    birthdate: '1990-03-10',
    gender: 'Female',
    address: 'Quezon City',
    contactNumber: '09170001111',
    password: 'secret1',
    password_confirmation: 'secret1',
    relationship: 'spouse',
    relationshipLabel: 'Spouse',
    documents: { government_id: { uri: 'file:///id.jpg' } },
    documentsSummary: [],
    certified: true,
    acceptedTerms: true,
    acceptedPrivacy: true,
    consentVersion: '2026-10-04',
    ...overrides,
  };
}

test('Legal Guardian registration sends no guardian fields', () => {
  const body = buildRegisterBody(
    payload({
      relationship: 'legal_guardian',
      relationshipLabel: 'Legal Guardian',
      documents: { government_id: { uri: 'file:///id.jpg', idType: 'Passport' } },
    }),
  );
  for (const key of ['guardianFullName', 'guardianRelationship', 'guardianContactNumber']) {
    assert.equal(key in body, false, key);
  }
  assert.equal(body.relationshipHint, 'Legal Guardian');
  assert.equal(body.relationshipType, 'legal_guardian');
  // Raw step-3 state never goes on the wire.
  assert.equal('documents' in body, false);
});

test('a spouse registration body is unchanged', () => {
  assert.deepEqual(buildRegisterBody(payload()), {
    fullName: 'Ana Reyes',
    firstName: 'Ana',
    lastName: 'Reyes',
    email: 'ana@example.com',
    password: 'secret1',
    password_confirmation: 'secret1',
    dateOfBirth: '1990-03-10',
    gender: 'female',
    address: 'Quezon City',
    contactNumber: '09170001111',
    relationshipHint: 'Spouse',
    relationshipType: 'spouse',
    acceptedTerms: true,
    acceptedPrivacy: true,
    consentVersion: '2026-10-04',
  });
});

test('password_confirmation is never filled in from password', () => {
  const missing = buildRegisterBody(payload({ password_confirmation: undefined }));
  assert.equal('password_confirmation' in missing, false);
  assert.equal(buildRegisterBody(payload({ password_confirmation: '' })).password_confirmation, undefined);
  // A mismatch is sent as typed, for the backend to reject.
  assert.equal(buildRegisterBody(payload({ password_confirmation: 'other' })).password_confirmation, 'other');
});

test('only fields AuthController::register() knows are sent', () => {
  const known = new Set([
    'fullName', 'firstName', 'lastName', 'email', 'password', 'password_confirmation',
    'dateOfBirth', 'gender', 'address', 'contactNumber', 'relationshipHint',
    'intendedPdlName', 'intendedPdlBirthDate',
    'relationshipType',
    'acceptedTerms', 'acceptedPrivacy', 'consentVersion',
  ]);
  const body = buildRegisterBody(payload({ intendedPdlName: 'Pedro Santos Reyes', intendedPdlBirthDate: '1985-06-01' }));
  for (const key of Object.keys(body)) assert.ok(known.has(key), key);
});

test("the PDL's date of birth is sent only when known, as YYYY-MM-DD", () => {
  const known = buildRegisterBody(payload({ intendedPdlName: 'Juan Dela Cruz', intendedPdlBirthDate: '1991-11-23' }));
  assert.equal(known.intendedPdlBirthDate, '1991-11-23');
  // "I don't know the exact date": the screen passes null — no field at all.
  for (const unknown of [null, undefined, '', '  ']) {
    const body = buildRegisterBody(payload({ intendedPdlName: 'Juan Dela Cruz', intendedPdlBirthDate: unknown }));
    assert.equal('intendedPdlBirthDate' in body, false, String(unknown));
  }
  // Never a reformatted or invalid date.
  assert.equal('intendedPdlBirthDate' in buildRegisterBody(payload({ intendedPdlBirthDate: '11/23/1991' })), false);
});

test('the joined PDL name parts are sent as the one intendedPdlName', () => {
  const body = buildRegisterBody(payload({ intendedPdlName: 'Juan Santos Dela Cruz Jr.' }));
  assert.equal(body.intendedPdlName, 'Juan Santos Dela Cruz Jr.');
  for (const key of ['pdlFirstName', 'pdlMiddleName', 'pdlLastName', 'pdlSuffix', 'pdlNameParts']) {
    assert.equal(key in body, false, key);
  }
});

test('the PDL name is sent as intendedPdlName only', () => {
  const body = buildRegisterBody(payload({ intendedPdlName: ' Pedro  Santos Reyes ' }));
  assert.equal(body.intendedPdlName, 'Pedro Santos Reyes');
  assert.equal('intendedPdlReference' in body, false);
  // The claimed type only selects which documents the backend accepts.
  assert.equal(body.relationshipType, 'spouse');
  // The free-text relationship hint is sent as before.
  assert.equal(body.relationshipHint, 'Spouse');
});

test('no PDL name means no intendedPdlName field', () => {
  const body = buildRegisterBody(payload({ intendedPdlName: '' }));
  assert.equal('intendedPdlName' in body, false);
});

test('relationship documents: only the keys the relationship asks for are sent', () => {
  const files = getRegistrationRelationshipFiles(
    payload({
      relationship: 'live_in_partner',
      documents: {
        government_id: { uri: 'file:///id.jpg' },
        cenomar_visitor: { uri: 'file:///c1.jpg' },
        cenomar_pdl: { uri: 'file:///c2.jpg' },
        // Left over from an earlier "children together" answer, and a
        // witness statement, which is no longer required.
        child_birth_certificate: { uri: 'file:///old.jpg' },
        witness_statement: { uri: 'file:///w.jpg' },
      },
      relationshipDocumentKeys: ['cenomar_visitor', 'cenomar_pdl'],
    }),
  );
  assert.deepEqual(
    files.map(([key, file]) => [key, file.uri]),
    [
      ['cenomar_visitor', 'file:///c1.jpg'],
      ['cenomar_pdl', 'file:///c2.jpg'],
    ],
  );
  // The government ID goes in its own governmentId field, never here.
  assert.equal(files.some(([key]) => key === 'government_id'), false);
});

test('no relationship documents without keys or files', () => {
  assert.deepEqual(getRegistrationRelationshipFiles(payload()), []);
  assert.deepEqual(
    getRegistrationRelationshipFiles(payload({ relationshipDocumentKeys: ['marriage_certificate'] })),
    [],
  );
});

test('middle name and suffix are sent with a natural-order fullName', () => {
  const body = buildRegisterBody(
    payload({
      fullName: 'Juan Santos Dela Cruz Jr.',
      firstName: 'Juan',
      lastName: 'Dela Cruz',
      middleName: 'Santos',
      suffix: 'Jr.',
    }),
  );
  assert.equal(body.fullName, 'Juan Santos Dela Cruz Jr.');
  assert.equal(body.middleName, 'Santos');
  assert.equal(body.suffix, 'Jr.');
  assert.equal(body.lastName, 'Dela Cruz');
});

test('no middle name or suffix: the keys are left out', () => {
  const body = buildRegisterBody(payload({ middleName: '', suffix: '' }));
  assert.equal('middleName' in body, false);
  assert.equal('suffix' in body, false);
});
