// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  buildRegistrationDocuments,
  buildRelationshipRequirementDocuments,
  hasAnyRelationshipUpload,
  isAwaitingRelationshipLink,
  toRelationshipDocumentKey,
  toRequirementUploadStatus,
} from './relationshipRequirementDocuments.js';

// Shape of one GET /api/documents `relationships[]` entry.
function relationship(overrides = {}) {
  return {
    id: '7',
    relationshipType: 'live_in_partner',
    pdlName: 'Juan Dela Cruz',
    status: 'pending',
    verifiedAt: null,
    hasSupportingDocument: false,
    requirements: [
      { key: 'valid_id', kind: 'valid_id', label: 'Valid Government ID', status: 'pending_review', canUpload: false },
      { key: 'cenomar_visitor', kind: 'document', label: "Visitor's CENOMAR", status: 'missing', canUpload: true, rejectionReason: null, uploadedAt: null, reviewedAt: null },
      { key: 'cenomar_pdl', kind: 'document', label: "PDL's CENOMAR", status: 'pending_review', canUpload: true, rejectionReason: null, uploadedAt: '2026-10-15T01:00:00+08:00', reviewedAt: null },
      { key: 'child_birth_certificate', kind: 'document', label: "Child's PSA Birth Certificate", status: 'rejected', canUpload: true, rejectionReason: 'Blurry photo', uploadedAt: '2026-10-14T01:00:00+08:00', reviewedAt: '2026-10-14T05:00:00+08:00' },
      { key: 'witness_statement', kind: 'document', label: 'Witness Statement', status: 'met', canUpload: false, rejectionReason: null, uploadedAt: '2026-10-13T01:00:00+08:00', reviewedAt: '2026-10-13T06:00:00+08:00' },
      { key: 'accompanying_guardian', kind: 'confirmation', label: 'Accompanied by parent/guardian', status: 'missing', canUpload: false },
    ],
    ...overrides,
  };
}

test('uses the backend checklist keys, labels and statuses (documents only)', () => {
  const docs = buildRelationshipRequirementDocuments([relationship()]);
  assert.deepEqual(
    docs.map((d) => [d.requirementKey, d.label, d.uploadStatus]),
    [
      ['cenomar_visitor', "Visitor's CENOMAR", 'pending'],
      ['cenomar_pdl', "PDL's CENOMAR", 'under_review'],
      ['child_birth_certificate', "Child's PSA Birth Certificate", 'rejected'],
      ['witness_statement', 'Witness Statement', 'verified'],
    ],
  );
});

test('each entry targets its relationship and requirement for the upload endpoint', () => {
  const [first] = buildRelationshipRequirementDocuments([relationship()]);
  assert.equal(first.relationshipRecordId, '7');
  assert.equal(first.requirementKey, 'cenomar_visitor');
  assert.equal(first.key, toRelationshipDocumentKey('7', 'cenomar_visitor'));
  assert.equal(first.detail, 'For Juan Dela Cruz');
});

test('rejection reason, upload date and review date come from the document', () => {
  const docs = buildRelationshipRequirementDocuments([relationship()]);
  const rejected = docs.find((d) => d.requirementKey === 'child_birth_certificate');
  assert.equal(rejected.rejectionReason, 'Blurry photo');
  assert.equal(rejected.canUpload, true);
  assert.equal(rejected.verifiedAt, null);
  const verified = docs.find((d) => d.requirementKey === 'witness_statement');
  assert.equal(verified.verifiedAt, '2026-10-13T06:00:00+08:00');
  assert.equal(verified.canUpload, false);
});

test('a verified relationship is listed read-only', () => {
  const docs = buildRelationshipRequirementDocuments([relationship({ status: 'verified' })]);
  assert.ok(docs.length > 0);
  assert.ok(docs.every((d) => d.canUpload === false));
});

test('rejected relationships are skipped; two linked PDLs never share a key', () => {
  const docs = buildRelationshipRequirementDocuments([
    relationship({ id: '1' }),
    relationship({ id: '2' }),
    relationship({ id: '3', status: 'rejected' }),
  ]);
  const keys = docs.map((d) => d.key);
  assert.equal(new Set(keys).size, keys.length);
  assert.ok(docs.every((d) => d.relationshipRecordId !== '3'));
});

test('no relationships → no relationship documents and awaiting a link', () => {
  assert.deepEqual(buildRelationshipRequirementDocuments([]), []);
  assert.deepEqual(buildRelationshipRequirementDocuments(undefined), []);
  assert.equal(isAwaitingRelationshipLink([]), true);
  assert.equal(isAwaitingRelationshipLink([{ id: 1, status: 'rejected' }]), true);
  assert.equal(isAwaitingRelationshipLink([relationship()]), false);
});

test('a legacy single-file upload is flagged on missing items but does not count as met', () => {
  const docs = buildRelationshipRequirementDocuments([relationship({ hasSupportingDocument: true })]);
  const missing = docs.find((d) => d.requirementKey === 'cenomar_visitor');
  assert.equal(missing.uploadStatus, 'pending');
  assert.match(missing.reviewNote, /earlier upload/);
  assert.equal(docs.find((d) => d.requirementKey === 'cenomar_pdl').reviewNote, null);
});

test('hasAnyRelationshipUpload sees checklist files and legacy uploads', () => {
  const none = relationship({
    requirements: [{ key: 'marriage_certificate', kind: 'document', status: 'missing' }],
  });
  assert.equal(hasAnyRelationshipUpload([none]), false);
  assert.equal(hasAnyRelationshipUpload([{ ...none, hasSupportingDocument: true }]), true);
  assert.equal(hasAnyRelationshipUpload([relationship()]), true);
});

test('status mapping', () => {
  assert.equal(toRequirementUploadStatus('met'), 'verified');
  assert.equal(toRequirementUploadStatus('pending_review'), 'under_review');
  assert.equal(toRequirementUploadStatus('rejected'), 'rejected');
  assert.equal(toRequirementUploadStatus('missing'), 'pending');
  assert.equal(toRequirementUploadStatus(undefined), 'pending');
});

test('documents sent with the registration are listed read-only until staff link the PDL', () => {
  const docs = buildRegistrationDocuments([
    { key: 'marriage_certificate', label: 'Marriage Certificate', status: 'pending', uploadedAt: '2026-10-15T01:00:00+08:00' },
    { key: 7 },
    null,
  ]);
  assert.equal(docs.length, 1);
  assert.equal(docs[0].key, 'registration:marriage_certificate');
  assert.equal(docs[0].uploadStatus, 'under_review');
  assert.equal(docs[0].canUpload, false);
  assert.equal(docs[0].requirementKey, 'marriage_certificate');
  assert.match(docs[0].unavailableReason, /link your account to the PDL/);
  assert.deepEqual(buildRegistrationDocuments(undefined), []);
});
