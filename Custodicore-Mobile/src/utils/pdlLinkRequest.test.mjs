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
  normalizePdlRequestLimit,
  normalizePdlRequests,
  pdlRequestLimitMessage,
  pdlRequestLimitReached,
  pdlRequestPrefill,
  pdlRequestRefusalTitle,
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
    ['cenomar_visitor', 'cenomar_pdl'],
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

test('Add Another PDL: every approved account, open requests or not', () => {
  assert.equal(canAddAnotherPdl('verified'), true);
  assert.equal(canAddAnotherPdl('pending'), false);
  assert.equal(canAddAnotherPdl('rejected'), false);
  assert.equal(canAddAnotherPdl(null), false);
});

test('the request list keeps each open and rejected request, newest first', () => {
  const list = normalizePdlRequests([
    apiRequest({ id: 3, pdlName: 'Maria Clara' }),
    apiRequest({ id: 2, status: 'rejected', pdlName: 'Pedro Santos', rejectionReason: 'Not found.' }),
    apiRequest({ id: 1, status: 'cancelled' }),
    apiRequest({ id: 3 }), // the same request twice: kept once
  ]);
  assert.deepEqual(list.map((r) => [r.id, r.status]), [['3', 'open'], ['2', 'rejected']]);
  assert.equal(list[1].rejectionReason, 'Not found.');
});

test('an older backend: the single pdlRequest becomes a one-item list', () => {
  assert.deepEqual(normalizePdlRequests(undefined, apiRequest({ id: 4 })).map((r) => r.id), ['4']);
  assert.deepEqual(normalizePdlRequests(undefined, null), []);
  // The list wins when both are sent.
  assert.deepEqual(normalizePdlRequests([], apiRequest({ id: 4 })), []);
});

test('the open-request limit', () => {
  const requests = normalizePdlRequests([apiRequest({ id: 1 }), apiRequest({ id: 2 })]);
  assert.deepEqual(normalizePdlRequestLimit({ max: 5, open: 2 }, requests), { max: 5, open: 2 });
  assert.equal(pdlRequestLimitReached({ max: 5, open: 4 }), false);
  assert.equal(pdlRequestLimitReached({ max: 5, open: 5 }), true);
  assert.equal(pdlRequestLimitReached(null), false);
  // An older backend sends no limit and allows one open request.
  assert.deepEqual(normalizePdlRequestLimit(undefined, requests), { max: 1, open: 2 });
  assert.deepEqual(normalizePdlRequestLimit({ max: 'x', open: -1 }, []), { max: 1, open: 0 });
  assert.equal(
    pdlRequestLimitMessage(5),
    'You already have 5 PDL requests waiting for facility staff. Wait for one to be reviewed, or cancel one in My PDLs, before sending another.',
  );
});

test('a refused request gets a title for its reason', () => {
  assert.equal(pdlRequestRefusalTitle('pdl_request_limit'), 'Request limit reached');
  assert.equal(pdlRequestRefusalTitle('duplicate_pdl_request'), 'Request already sent');
  assert.equal(pdlRequestRefusalTitle('pdl_already_linked'), 'PDL already on your account');
  assert.equal(pdlRequestRefusalTitle(undefined), 'Request already waiting');
});

test('a rejected request keeps its relationship type for Request Again', () => {
  const r = normalizePdlRequest({
    id: 9,
    status: 'rejected',
    pdlName: 'Maria Santos',
    relationshipType: 'live_in_partner',
    hasChildrenTogether: true,
    rejectionReason: 'Name does not match our records.',
  });
  assert.equal(r?.relationshipType, 'live_in_partner');
  assert.deepEqual(pdlRequestPrefill(r), {
    pdlName: 'Maria Santos',
    relationship: 'live_in_partner',
    hasChildrenTogether: true,
    rejectionReason: 'Name does not match our records.',
  });
});

test('Request Again prefill: only rejected requests, only valid values', () => {
  const open = normalizePdlRequest({ id: 1, status: 'open', pdlName: 'A B', relationshipType: 'parent' });
  assert.equal(pdlRequestPrefill(open), null);
  assert.equal(pdlRequestPrefill(null), null);

  const odd = normalizePdlRequest({
    id: 2,
    status: 'rejected',
    pdlName: '',
    relationshipType: 'cousin',
    hasChildrenTogether: true,
  });
  assert.equal(odd?.relationshipType, null);
  assert.deepEqual(pdlRequestPrefill(odd), {
    pdlName: '',
    relationship: null,
    // The children answer only belongs to a live-in partner request.
    hasChildrenTogether: null,
    rejectionReason: null,
  });
});

import { relationshipRequestPrefill } from './pdlLinkRequest.js';

test('Request Again for a rejected relationship prefills its PDL and relationship', () => {
  assert.deepEqual(
    relationshipRequestPrefill(
      { status: 'rejected', pdlName: 'Pedro Santos', relationshipType: 'spouse', hasChildrenTogether: null },
      'Names do not match.',
    ),
    { pdlName: 'Pedro Santos', relationship: 'spouse', hasChildrenTogether: null, rejectionReason: 'Names do not match.' },
  );
  // Only a rejected one; an unknown relationship type is left for the visitor to pick.
  assert.equal(relationshipRequestPrefill({ status: 'approved', pdlName: 'X' }), null);
  assert.equal(relationshipRequestPrefill(null), null);
  assert.deepEqual(
    relationshipRequestPrefill({ status: 'rejected', pdlName: 'PDL', relationshipType: 'friend' }),
    { pdlName: '', relationship: null, hasChildrenTogether: null, rejectionReason: null },
  );
});
