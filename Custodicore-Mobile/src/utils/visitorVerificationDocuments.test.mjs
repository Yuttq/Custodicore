// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  canUploadGovernmentIdEntry,
  getPhotoAction,
} from './visitorVerificationDocuments.js';
import {
  getDocumentDetailAction,
  getDocumentDetailActionLabel,
} from './verificationDocumentUi.js';

test('government ID: the backend flag decides, an approved ID never offers an upload', () => {
  // Rejected ID (or returned account): backend allows.
  assert.equal(canUploadGovernmentIdEntry('rejected', true), true);
  // Waiting for review on a pending account: backend refuses.
  assert.equal(canUploadGovernmentIdEntry('pending', false), false);
  // Returned account with an ID waiting for review: backend allows.
  assert.equal(canUploadGovernmentIdEntry('pending', true), true);
  // No ID yet.
  assert.equal(canUploadGovernmentIdEntry(undefined, true), true);
  // Approved ID: never, whatever the flag says.
  assert.equal(canUploadGovernmentIdEntry('verified', true), false);
  assert.equal(canUploadGovernmentIdEntry('verified', undefined), false);
});

test('government ID: older backends without the flag keep the previous rule', () => {
  assert.equal(canUploadGovernmentIdEntry('pending', undefined), true);
  assert.equal(canUploadGovernmentIdEntry('rejected', null), true);
});

test('photo: retake only when the backend allows it', () => {
  // Pending / approved account with a photo: no action.
  assert.equal(getPhotoAction({ canReplacePhoto: false, hasPhoto: true }), null);
  // Returned for correction.
  assert.equal(getPhotoAction({ canReplacePhoto: true, hasPhoto: true }), 'retake');
  // No photo yet (older accounts).
  assert.equal(getPhotoAction({ canReplacePhoto: true, hasPhoto: false }), 'take');
  // Missing or malformed data never enables it.
  assert.equal(getPhotoAction(null), null);
  assert.equal(getPhotoAction({ hasPhoto: true }), null);
  assert.equal(getPhotoAction({ canReplacePhoto: 'true', hasPhoto: true }), null);
});

test('a rejected document is offered as "Replace Document"; an approved one has no action', () => {
  assert.equal(getDocumentDetailAction('rejected'), 'upload_new');
  assert.equal(getDocumentDetailActionLabel('rejected'), 'Replace Document');
  assert.equal(getDocumentDetailActionLabel('verified'), null);
});

// ---------------------------------------------------------------------------
// Grouping and the account status sentence.
// ---------------------------------------------------------------------------
import {
  getAccountStatusMessage,
  groupVerificationDocuments,
} from './visitorVerificationDocuments.js';

test('account status sentence per backend status', () => {
  assert.equal(getAccountStatusMessage('pending'), 'Your documents are being reviewed.');
  assert.equal(
    getAccountStatusMessage('rejected'),
    'Please review the reason and replace the required documents.',
  );
  assert.equal(getAccountStatusMessage('verified'), 'Your account verification is complete.');
  assert.equal(getAccountStatusMessage(null), null);
});

test('documents are grouped: account, first PDL, additional PDLs', () => {
  const docs = [
    { key: 'rel-20:birth_certificate', relationshipRecordId: '20', detail: 'For Ben Cruz' },
    { key: 'rel-3:marriage_certificate', relationshipRecordId: '3', detail: 'For Ana Lim' },
    { key: 'reg:birth_certificate', relationshipRecordId: null, detail: 'Sent with registration' },
    { key: 'government_id', relationshipRecordId: null, detail: 'Passport' },
    { key: 'rel-3:cenomar_visitor', relationshipRecordId: '3', detail: 'For Ana Lim' },
  ];
  const pdls = [
    { id: '20', pdlName: 'Ben Cruz' },
    { id: '3', pdlName: 'Ana Lim' },
  ];

  const sections = groupVerificationDocuments(docs, pdls);
  assert.deepEqual(
    sections.map((s) => [s.title, s.kind, s.documents.map((d) => d.key)]),
    [
      ['Account Verification', 'account', ['reg:birth_certificate', 'government_id']],
      ['First PDL: Ana Lim', 'first_pdl', ['rel-3:marriage_certificate', 'rel-3:cenomar_visitor']],
      ['Additional PDL: Ben Cruz', 'additional_pdl', ['rel-20:birth_certificate']],
    ],
  );
});

test('the first PDL stays first when it has no documents listed (e.g. rejected)', () => {
  const sections = groupVerificationDocuments(
    [{ key: 'rel-8:x', relationshipRecordId: '8', detail: 'For Cy Reyes' }],
    [{ id: '2', pdlName: 'Old PDL' }, { id: '8', pdlName: 'PDL' }],
  );
  // Name falls back to the document's "For …" detail.
  assert.deepEqual(sections.map((s) => [s.title, s.kind]), [['Additional PDL: Cy Reyes', 'additional_pdl']]);
  assert.deepEqual(groupVerificationDocuments(undefined, undefined), []);
});
