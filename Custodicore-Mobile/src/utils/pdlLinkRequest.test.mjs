// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  PDL_REQUEST_STEPS,
  canAddAnotherPdl,
  firstInvalidPdlRequestStep,
  getPdlRequestDocuments,
  getPdlRequestStepErrors,
  mapPdlRequestServerErrors,
  normalizePdlRequest,
  toPdlRequestFields,
} from './pdlLinkRequest.js';
import { REGISTRATION_RELATIONSHIPS } from './registrationRequirements.js';

const keys = (docs) => docs.map((d) => d.requirementKey);

test('the steps follow the registration order', () => {
  assert.deepEqual(PDL_REQUEST_STEPS, ['PDL Name', 'Relationship Type', 'Relationship Documents', 'Review & Submit']);
});

test('documents are the relationship documents only, never the Government ID', () => {
  assert.deepEqual(keys(getPdlRequestDocuments('spouse')), ['marriage_certificate']);
  assert.deepEqual(keys(getPdlRequestDocuments('parent')), ['pdl_birth_certificate']);
  assert.deepEqual(keys(getPdlRequestDocuments('child')), ['visitor_birth_certificate']);
  assert.deepEqual(keys(getPdlRequestDocuments('legal_guardian')), ['guardianship_authorization']);
  assert.deepEqual(keys(getPdlRequestDocuments('legal_counsel')), ['proof_of_counsel']);
  for (const r of REGISTRATION_RELATIONSHIPS) {
    assert.ok(getPdlRequestDocuments(r.id).every((d) => d.key !== 'government_id'));
  }
  assert.equal(REGISTRATION_RELATIONSHIPS.length, 6);
  assert.deepEqual(getPdlRequestDocuments(null), []);
  assert.deepEqual(getPdlRequestDocuments('other'), []);
});

test('live-in partners: the children answer decides the extra document', () => {
  assert.deepEqual(keys(getPdlRequestDocuments('live_in_partner')), ['cenomar_visitor', 'cenomar_pdl']);
  assert.deepEqual(
    keys(getPdlRequestDocuments('live_in_partner', { hasChildrenTogether: true })),
    ['cenomar_visitor', 'cenomar_pdl', 'child_birth_certificate'],
  );
  assert.deepEqual(
    keys(getPdlRequestDocuments('live_in_partner', { hasChildrenTogether: false })),
    ['cenomar_visitor', 'cenomar_pdl', 'witness_statement'],
  );
});

test('a minor also sends their own birth certificate, once', () => {
  assert.deepEqual(keys(getPdlRequestDocuments('parent', { isMinor: true })), ['pdl_birth_certificate', 'visitor_birth_certificate']);
  assert.deepEqual(keys(getPdlRequestDocuments('child', { isMinor: true })), ['visitor_birth_certificate']);
});

test('step validation', () => {
  assert.ok(getPdlRequestStepErrors(1, { pdlName: '' }).pdlName);
  assert.ok(getPdlRequestStepErrors(1, { pdlName: 'J' }).pdlName);
  assert.ok(getPdlRequestStepErrors(1, { pdlName: 'Juan 123' }).pdlName);
  assert.deepEqual(getPdlRequestStepErrors(1, { pdlName: 'Juan Dela Cruz' }), {});

  assert.ok(getPdlRequestStepErrors(2, { relationship: null }).relationship);
  assert.ok(getPdlRequestStepErrors(2, { relationship: 'friend' }).relationship);
  assert.ok(getPdlRequestStepErrors(2, { relationship: 'live_in_partner', hasChildrenTogether: null }).children_together);
  assert.deepEqual(getPdlRequestStepErrors(2, { relationship: 'live_in_partner', hasChildrenTogether: false }), {});
  assert.deepEqual(getPdlRequestStepErrors(2, { relationship: 'spouse' }), {});

  const missing = getPdlRequestStepErrors(3, { relationship: 'parent', isMinor: true, files: { pdl_birth_certificate: { uri: 'file://a.jpg' } } });
  assert.deepEqual(Object.keys(missing), ['visitor_birth_certificate']);
  assert.deepEqual(getPdlRequestStepErrors(3, { relationship: 'spouse', files: { marriage_certificate: { uri: 'file://m.jpg' } } }), {});
});

test('firstInvalidPdlRequestStep finds the earliest problem', () => {
  const complete = { pdlName: 'Juan Dela Cruz', relationship: 'spouse', files: { marriage_certificate: { uri: 'file://m.jpg' } } };
  assert.equal(firstInvalidPdlRequestStep(complete), null);
  assert.equal(firstInvalidPdlRequestStep({ ...complete, pdlName: '' }), 1);
  assert.equal(firstInvalidPdlRequestStep({ ...complete, relationship: 'live_in_partner' }), 2);
  assert.equal(firstInvalidPdlRequestStep({ ...complete, files: {} }), 3);
});

test('server validation errors go back to the right step', () => {
  assert.deepEqual(mapPdlRequestServerErrors({ pdlName: ['Bad name'], 'documents.marriage_certificate': ['Upload it'] }), {
    step: 1,
    errors: { pdlName: 'Bad name', marriage_certificate: 'Upload it' },
  });
  assert.deepEqual(mapPdlRequestServerErrors({ hasChildrenTogether: ['Answer it'] }), {
    step: 2,
    errors: { children_together: 'Answer it' },
  });
  assert.deepEqual(mapPdlRequestServerErrors({ documents: ['Not required'] }), {
    step: 3,
    errors: { documents: 'Not required' },
  });
  assert.deepEqual(mapPdlRequestServerErrors({ other: 'Something' }), { step: 4, errors: { form: 'Something' } });
  assert.equal(mapPdlRequestServerErrors(null), null);
  assert.equal(mapPdlRequestServerErrors({}), null);
});

test('request fields: trimmed name, children answer as 1/0 for live-in partners only', () => {
  assert.deepEqual(toPdlRequestFields({ pdlName: '  Juan   Dela Cruz ', relationship: 'spouse', hasChildrenTogether: true }), [
    ['pdlName', 'Juan Dela Cruz'],
    ['relationshipType', 'spouse'],
  ]);
  assert.deepEqual(toPdlRequestFields({ pdlName: 'Ana', relationship: 'live_in_partner', hasChildrenTogether: false }), [
    ['pdlName', 'Ana'],
    ['relationshipType', 'live_in_partner'],
    ['hasChildrenTogether', '0'],
  ]);
});

// Shape of GET /api/documents `pdlRequest` (PdlLinkRequestController::payload).
function apiRequest(overrides = {}) {
  return {
    id: '12',
    status: 'open',
    pdlName: 'Juan Dela Cruz',
    relationshipType: 'parent',
    relationshipLabel: 'Parent',
    hasChildrenTogether: null,
    rejectionReason: null,
    createdAt: '2026-11-05T08:00:00+08:00',
    updatedAt: '2026-11-05T08:00:00+08:00',
    documents: [{ key: 'pdl_birth_certificate', label: "PDL's PSA Birth Certificate", uploadedAt: null }],
    ...overrides,
  };
}

test('an open request shows as Pending Review with its documents', () => {
  const r = normalizePdlRequest(apiRequest());
  assert.equal(r.status, 'open');
  assert.equal(r.statusLabel, 'Pending Review');
  assert.equal(r.pdlName, 'Juan Dela Cruz');
  assert.equal(r.relationshipLabel, 'Parent');
  assert.deepEqual(r.documents, [{ key: 'pdl_birth_certificate', label: "PDL's PSA Birth Certificate" }]);
  assert.equal(r.rejectionReason, null);
});

test('a rejected request shows as Not Accepted with the staff reason', () => {
  const r = normalizePdlRequest(apiRequest({ status: 'rejected', rejectionReason: ' No PDL by that name. ', documents: [] }));
  assert.equal(r.statusLabel, 'Not Accepted');
  assert.equal(r.rejectionReason, 'No PDL by that name.');
});

test('cancelled, linked or missing requests are not shown', () => {
  assert.equal(normalizePdlRequest(apiRequest({ status: 'cancelled' })), null);
  assert.equal(normalizePdlRequest(apiRequest({ status: 'linked' })), null);
  assert.equal(normalizePdlRequest(null), null);
  assert.equal(normalizePdlRequest({ status: 'open' }), null);
});

test('Add Another PDL: approved accounts without an open request', () => {
  const open = normalizePdlRequest(apiRequest());
  const rejected = normalizePdlRequest(apiRequest({ status: 'rejected' }));
  assert.equal(canAddAnotherPdl('verified', null), true);
  assert.equal(canAddAnotherPdl('verified', rejected), true);
  assert.equal(canAddAnotherPdl('verified', open), false);
  assert.equal(canAddAnotherPdl('pending', null), false);
  assert.equal(canAddAnotherPdl('rejected', null), false);
});
