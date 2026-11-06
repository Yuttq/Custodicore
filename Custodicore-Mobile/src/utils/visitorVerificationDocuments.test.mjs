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
