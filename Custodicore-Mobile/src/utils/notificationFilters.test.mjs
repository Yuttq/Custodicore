// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { inferNotificationCategory, inferNotificationTone } from './notificationFilters.js';

// GET /api/notifications sends the backend notification_type as `category`.
test('a rejected PDL request is an update shown as a warning', () => {
  const raw = { category: 'pdl_request_rejected', title: 'PDL Request Not Approved', message: '…' };
  assert.equal(inferNotificationCategory(raw), 'updates');
  assert.equal(inferNotificationTone(raw), 'warning');
});

test('a rejected ID or document is an update shown as a warning', () => {
  const raw = {
    category: 'document_rejected',
    title: 'Document Not Accepted',
    message: 'Your National ID was not accepted. Reason: Blurry.',
  };
  assert.equal(inferNotificationCategory(raw), 'updates');
  assert.equal(inferNotificationTone(raw), 'warning');
});

test('account and relationship decisions are updates; not-approved ones are warnings', () => {
  const rejected = { category: 'relationship_rejected', title: 'PDL Relationship Not Approved' };
  assert.equal(inferNotificationCategory(rejected), 'updates');
  assert.equal(inferNotificationTone(rejected), 'warning');
  const returned = { category: 'account_rejected', title: 'Account Returned for Correction' };
  assert.equal(inferNotificationCategory(returned), 'updates');
  assert.equal(inferNotificationTone(returned), 'warning');
  const approved = { category: 'account_approved', title: 'Account Approved' };
  assert.equal(inferNotificationCategory(approved), 'updates');
  assert.equal(inferNotificationTone(approved), 'success');
  assert.equal(
    inferNotificationCategory({ category: 'relationship_linked', title: 'PDL Added To Your Account' }),
    'updates',
  );
});

test('existing types keep their mapping', () => {
  assert.equal(inferNotificationCategory({ category: 'visit_confirmed', title: 'Visit Confirmed' }), 'visits');
  assert.equal(inferNotificationTone({ category: 'visit_confirmed', title: 'Visit Confirmed' }), 'success');
  assert.equal(inferNotificationCategory({ category: 'verification_approved', title: 'Account Approved' }), 'updates');
  assert.equal(inferNotificationTone({ category: 'relationship_approved', title: 'PDL Relationship Approved' }), 'success');
});
