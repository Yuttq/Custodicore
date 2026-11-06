// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { getCheckStatusNotice, getVerificationReviewState } from './verificationReviewStatus.js';

const doc = (key, uploadStatus, extra = {}) => ({ key, label: key, uploadStatus, ...extra });

test('pending account with nothing rejected is Under Review and asks for no action', () => {
  const state = getVerificationReviewState({
    accountStatus: 'pending',
    documents: [doc('Government ID', 'under_review'), doc('Marriage Certificate', 'verified')],
  });
  assert.equal(state.kind, 'under_review');
  assert.equal(state.title, 'Under Review');
  assert.equal(state.showDocumentsAction, false);
  assert.deepEqual(state.documentsNeedingAttention, []);
  assert.equal(state.underReviewCount, 1);
  assert.equal(state.accountReason, null);
});

test('a rejected document on a pending account is Action Required, not account rejection', () => {
  const state = getVerificationReviewState({
    accountStatus: 'pending',
    accountReason: 'stale account note',
    documents: [
      doc('gov', 'rejected', { label: 'Government ID', rejectionReason: '  Photo is blurry. ', detail: 'National ID' }),
    ],
  });
  assert.equal(state.kind, 'action_required');
  assert.equal(state.title, 'Action Required');
  assert.equal(state.chip.status, 'document_rejected');
  assert.equal(state.showDocumentsAction, true);
  assert.match(state.message, /still under review/);
  // The account was not returned, so no account-level reason is shown.
  assert.equal(state.accountReason, null);
  assert.deepEqual(state.documentsNeedingAttention, [
    { key: 'gov', label: 'Government ID', detail: 'National ID', reason: 'Photo is blurry.' },
  ]);
});

test('mixed statuses name the rejected document and count the ones still under review', () => {
  const state = getVerificationReviewState({
    accountStatus: 'pending',
    documents: [
      doc('a', 'rejected', { rejectionReason: 'Wrong file' }),
      doc('b', 'under_review'),
      doc('c', 'under_review'),
      doc('d', 'verified'),
    ],
  });
  assert.equal(state.kind, 'action_required');
  assert.equal(state.documentsNeedingAttention.length, 1);
  assert.equal(state.documentsNeedingAttention[0].key, 'a');
  assert.equal(state.underReviewCount, 2);
  assert.match(state.message, /correct 1 document\./);
  assert.match(state.message, /2 other documents are still under review/);
  assert.doesNotMatch(state.message, /Staff are reviewing your registration/);
});

test('several rejected documents use the plural wording', () => {
  const state = getVerificationReviewState({
    accountStatus: 'pending',
    documents: [doc('a', 'rejected'), doc('b', 'rejected'), doc('c', 'under_review')],
  });
  assert.match(state.message, /correct 2 documents/);
  assert.match(state.message, /1 other document is still under review/);
  assert.equal(state.documentsNeedingAttention[0].reason, null);
});

test('returned account shows its own reason and keeps rejected documents separate', () => {
  const state = getVerificationReviewState({
    accountStatus: 'rejected',
    accountReason: ' Please use your full legal name. ',
    documents: [doc('id', 'rejected', { rejectionReason: 'Expired ID' }), doc('b', 'under_review')],
  });
  assert.equal(state.kind, 'returned');
  assert.equal(state.title, 'Returned for Correction');
  assert.equal(state.chip.status, 'verification_rejected');
  assert.equal(state.chip.label, 'Returned');
  assert.equal(state.accountReason, 'Please use your full legal name.');
  assert.equal(state.showDocumentsAction, true);
  assert.deepEqual(
    state.documentsNeedingAttention.map((d) => [d.key, d.reason]),
    [['id', 'Expired ID']],
  );
});

test('returned account with no documents rejected still asks for a correction', () => {
  const state = getVerificationReviewState({ accountStatus: 'rejected', accountReason: null, documents: [] });
  assert.equal(state.kind, 'returned');
  assert.equal(state.accountReason, null);
  assert.deepEqual(state.documentsNeedingAttention, []);
});

test('approved account confirms portal access and lists nothing to fix', () => {
  const state = getVerificationReviewState({
    accountStatus: 'verified',
    documents: [doc('old', 'rejected', { rejectionReason: 'superseded' })],
  });
  assert.equal(state.kind, 'approved');
  assert.equal(state.chip.status, 'verification_verified');
  assert.match(state.message, /visitor portal/);
  assert.equal(state.showDocumentsAction, false);
  assert.deepEqual(state.documentsNeedingAttention, []);
});

test('unknown documents never invent a document problem', () => {
  for (const documents of [null, undefined]) {
    const state = getVerificationReviewState({ accountStatus: 'pending', documents });
    assert.equal(state.kind, 'under_review');
    assert.equal(state.documentsKnown, false);
    assert.equal(state.underReviewCount, 0);
  }
});

test('missing or unknown account status falls back to Under Review, as the badge does', () => {
  for (const accountStatus of [null, undefined, 'something_new']) {
    assert.equal(getVerificationReviewState({ accountStatus, documents: [] }).kind, 'under_review');
  }
});

test('missing (never uploaded) documents are not treated as rejected or under review', () => {
  const state = getVerificationReviewState({ accountStatus: 'pending', documents: [doc('x', 'pending')] });
  assert.equal(state.kind, 'under_review');
  assert.equal(state.underReviewCount, 0);
});

test('Check Status notice matches each state', () => {
  const notice = (input) => getCheckStatusNotice(getVerificationReviewState(input));

  assert.equal(notice({ accountStatus: 'verified', documents: [] }).title, 'Account approved');
  assert.equal(notice({ accountStatus: 'pending', documents: [] }).title, 'Still under review');
  assert.match(
    notice({ accountStatus: 'pending', documents: null }).message,
    /could not be loaded/,
  );

  const actionOne = notice({ accountStatus: 'pending', documents: [doc('a', 'rejected')] });
  assert.equal(actionOne.title, 'Action required');
  assert.match(actionOne.message, /1 document needs correction/);
  const actionTwo = notice({ accountStatus: 'pending', documents: [doc('a', 'rejected'), doc('b', 'rejected')] });
  assert.match(actionTwo.message, /2 documents need correction/);

  assert.equal(
    notice({ accountStatus: 'rejected', accountReason: 'Blurry photo', documents: [] }).message,
    'What to fix: Blurry photo',
  );
  assert.match(
    notice({ accountStatus: 'rejected', accountReason: '', documents: [] }).message,
    /contact facility staff/,
  );
});
