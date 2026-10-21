// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  getVerificationBadge,
  isVisitorNotApprovedError,
  visibleRejectionReason,
} from './verificationStatusUi.js';

test('badge follows the backend status', () => {
  assert.equal(getVerificationBadge('verified').label, 'Verified');
  assert.equal(getVerificationBadge('rejected').label, 'Rejected');
  assert.equal(getVerificationBadge('pending').label, 'Under Review');
  assert.equal(getVerificationBadge(null).label, 'Under Review');
});

test('rejection reason is shown only while rejected', () => {
  assert.equal(visibleRejectionReason('rejected', 'The ID photo is blurry.'), 'The ID photo is blurry.');
  assert.equal(visibleRejectionReason('rejected', '  Trim me.  '), 'Trim me.');
  assert.equal(visibleRejectionReason('pending', 'Old note.'), null);
  assert.equal(visibleRejectionReason('verified', 'Old note.'), null);
  assert.equal(visibleRejectionReason(undefined, 'Old note.'), null);
});

test('missing or blank rejection reasons are null', () => {
  assert.equal(visibleRejectionReason('rejected', null), null);
  assert.equal(visibleRejectionReason('rejected', undefined), null);
  assert.equal(visibleRejectionReason('rejected', '   '), null);
  assert.equal(visibleRejectionReason('rejected', 42), null);
});

test('detects 403 visitor_not_approved from a raw axios error', () => {
  const error = { response: { status: 403, data: { code: 'visitor_not_approved' } } };
  assert.equal(isVisitorNotApprovedError(error), true);
});

test('detects 403 visitor_not_approved from an api.js request error', () => {
  const error = Object.assign(new Error('Under review'), { status: 403, code: 'visitor_not_approved' });
  assert.equal(isVisitorNotApprovedError(error), true);
});

test('ignores other errors', () => {
  assert.equal(isVisitorNotApprovedError({ response: { status: 403, data: { code: 'email_not_verified' } } }), false);
  assert.equal(isVisitorNotApprovedError({ response: { status: 403, data: {} } }), false);
  assert.equal(isVisitorNotApprovedError({ response: { status: 401, data: { code: 'visitor_not_approved' } } }), false);
  assert.equal(isVisitorNotApprovedError({ response: { status: 422, data: { message: 'x' } } }), false);
  assert.equal(isVisitorNotApprovedError(new Error('Network Error')), false);
  assert.equal(isVisitorNotApprovedError(null), false);
  assert.equal(isVisitorNotApprovedError(undefined), false);
});
