// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  GUARDIAN_INFO_NOTICE,
  LOCAL_INFO_NOTICE,
  RELATIONSHIP_DOCUMENTS_NOTICE,
  RELATIONSHIP_DOCUMENTS_SUBMITTED_NOTICE,
  REGISTRATION_RELATIONSHIPS,
  asksChildrenTogether,
  getInfoNotice,
  getRelationshipLabel,
  getRequiredDocuments,
  isRegistrationRelationship,
} from './registrationRequirements.js';

test('registration step 2 offers the six relationships, without Other', () => {
  assert.deepEqual(
    REGISTRATION_RELATIONSHIPS.map((r) => r.label),
    ['Spouse', 'Parent', 'Child', 'Legal Guardian', 'Live-In Partner', 'Legal Counsel'],
  );
});

test('Other and unknown values cannot be submitted from registration', () => {
  for (const value of ['other', 'Other', '', null, undefined, 'sibling']) {
    assert.equal(isRegistrationRelationship(value), false, String(value));
  }
  for (const r of REGISTRATION_RELATIONSHIPS) {
    assert.equal(isRegistrationRelationship(r.id), true, r.id);
  }
});

test('Legal Guardian asks for Guardian Information and proof of guardianship, no birth certificate', () => {
  const docs = getRequiredDocuments('legal_guardian');
  assert.deepEqual(docs.map((d) => d.key), ['guardian_information', 'guardianship_authorization']);
  // Typed details, not an uploaded document.
  assert.equal(docs[0].isText, true);
  assert.equal(docs.some((d) => /birth/i.test(d.label)), false);
});

test('guardian notice says the details go to staff, legacy summaries stay local-only', () => {
  assert.match(GUARDIAN_INFO_NOTICE, /sent to facility staff with your registration/);
  assert.doesNotMatch(GUARDIAN_INFO_NOTICE, /device|verified|approved/i);
  assert.match(LOCAL_INFO_NOTICE, /not sent to facility staff/);

  const sent = { label: 'Guardian Information', kind: 'info', submitted: true };
  const legacyKind = { label: 'Guardian Information', kind: 'info' };
  const legacyLabel = { label: 'Guardian Information', detail: 'Maria, aunt' };
  assert.equal(getInfoNotice([sent]), GUARDIAN_INFO_NOTICE);
  assert.equal(getInfoNotice([legacyKind]), LOCAL_INFO_NOTICE);
  assert.equal(getInfoNotice([legacyLabel]), LOCAL_INFO_NOTICE);
  assert.equal(getInfoNotice([sent, legacyKind]), LOCAL_INFO_NOTICE);
  assert.equal(getInfoNotice([]), LOCAL_INFO_NOTICE);
});

test('registrations saved with Other keep their label and requirements', () => {
  assert.equal(getRelationshipLabel('other'), 'Other');
  assert.deepEqual(
    getRequiredDocuments('other').map((d) => d.key),
    ['government_id'],
  );
});

const keys = (relationshipId, options) =>
  getRequiredDocuments(relationshipId, options).map((d) => d.key);

test('each relationship asks only for its own documents, with the registration', () => {
  assert.deepEqual(keys('spouse'), ['government_id', 'marriage_certificate']);
  assert.deepEqual(keys('parent'), ['government_id', 'pdl_birth_certificate']);
  assert.deepEqual(keys('child'), ['government_id', 'visitor_birth_certificate']);
  assert.deepEqual(keys('legal_counsel'), ['government_id', 'proof_of_counsel']);
  // Nobody is asked for every certificate type.
  for (const r of REGISTRATION_RELATIONSHIPS) {
    assert.equal(keys(r.id).includes('cenomar_visitor') && keys(r.id).includes('marriage_certificate'), false, r.id);
  }
});

test('relationship documents carry the backend requirement key', () => {
  const docs = getRequiredDocuments('spouse');
  assert.equal(docs.find((d) => d.key === 'government_id').requirementKey, undefined);
  assert.equal(docs.find((d) => d.key === 'marriage_certificate').requirementKey, 'marriage_certificate');
  assert.equal(docs.find((d) => d.key === 'marriage_certificate').label, 'Marriage Certificate');
});

test('live-in partners: CENOMARs, then the child or witness document by their answer', () => {
  assert.equal(asksChildrenTogether('live_in_partner'), true);
  assert.equal(asksChildrenTogether('spouse'), false);
  assert.deepEqual(keys('live_in_partner'), ['government_id', 'cenomar_visitor', 'cenomar_pdl']);
  assert.deepEqual(keys('live_in_partner', { hasChildrenTogether: true }), [
    'government_id', 'cenomar_visitor', 'cenomar_pdl', 'child_birth_certificate',
  ]);
  assert.deepEqual(keys('live_in_partner', { hasChildrenTogether: false }), [
    'government_id', 'cenomar_visitor', 'cenomar_pdl', 'witness_statement',
  ]);
  // The answer only matters for live-in partners.
  assert.deepEqual(keys('spouse', { hasChildrenTogether: true }), ['government_id', 'marriage_certificate']);
});

test('minors upload their birth certificate instead of a Government ID', () => {
  assert.deepEqual(keys('parent', { isMinor: true }), ['pdl_birth_certificate', 'visitor_birth_certificate']);
  // Already asked for by the relationship: listed once.
  assert.deepEqual(keys('child', { isMinor: true }), ['visitor_birth_certificate']);
});

test('the notices describe one submission at registration, re-upload only when needed', () => {
  for (const notice of [RELATIONSHIP_DOCUMENTS_NOTICE, RELATIONSHIP_DOCUMENTS_SUBMITTED_NOTICE]) {
    assert.match(notice, /registration/);
    assert.match(notice, /link your account to the PDL/);
    assert.match(notice, /reject/);
    assert.doesNotMatch(notice, /not uploaded during registration/);
  }
  assert.match(RELATIONSHIP_DOCUMENTS_NOTICE, /do not need to upload them again/);
});
