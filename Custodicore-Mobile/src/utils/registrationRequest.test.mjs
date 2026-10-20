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

test('only fields AuthController::register() knows are sent', () => {
  const known = new Set([
    'fullName', 'firstName', 'lastName', 'email', 'password', 'password_confirmation',
    'dateOfBirth', 'gender', 'address', 'contactNumber', 'relationshipHint',
    'intendedPdlName',
    'relationshipType',
    'acceptedTerms', 'acceptedPrivacy', 'consentVersion',
  ]);
  const body = buildRegisterBody(payload({ intendedPdlName: 'Pedro Santos Reyes' }));
  for (const key of Object.keys(body)) assert.ok(known.has(key), key);
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
        // Left over from an earlier "children together" answer.
        child_birth_certificate: { uri: 'file:///old.jpg' },
        witness_statement: { uri: 'file:///w.jpg' },
      },
      relationshipDocumentKeys: ['cenomar_visitor', 'cenomar_pdl', 'witness_statement'],
    }),
  );
  assert.deepEqual(
    files.map(([key, file]) => [key, file.uri]),
    [
      ['cenomar_visitor', 'file:///c1.jpg'],
      ['cenomar_pdl', 'file:///c2.jpg'],
      ['witness_statement', 'file:///w.jpg'],
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
