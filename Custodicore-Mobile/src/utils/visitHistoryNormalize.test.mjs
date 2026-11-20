// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  getHistoryReasonLabel,
  getHistoryStatusLabel,
  normalizeVisitHistoryRecord,
} from './visitHistoryNormalize.js';

const row = (extra) => ({
  id: 12,
  status: 'declined',
  dateDisplay: 'October 9, 2026',
  pdlName: 'Juan Dela Cruz',
  cancellationReason: 'Medical Reason',
  ...extra,
});

test('the cancelledByVisitor flag is carried from the API, and only a real true counts', () => {
  assert.equal(normalizeVisitHistoryRecord(row({ cancelledByVisitor: true }), 0).cancelledByVisitor, true);
  assert.equal(normalizeVisitHistoryRecord(row({ cancelledByVisitor: false }), 0).cancelledByVisitor, false);
  assert.equal(normalizeVisitHistoryRecord(row({}), 0).cancelledByVisitor, false);
  assert.equal(normalizeVisitHistoryRecord(row({ cancelledByVisitor: 'true' }), 0).cancelledByVisitor, false);
});

test('history list: a visitor cancellation reads Cancelled, a declined assignment reads Declined', () => {
  assert.equal(getHistoryStatusLabel('declined', true), 'Cancelled');
  assert.equal(getHistoryStatusLabel('declined', false), 'Declined');
  assert.equal(getHistoryStatusLabel('declined'), 'Declined');
  assert.equal(getHistoryStatusLabel('cancelled'), 'Cancelled');
  assert.equal(getHistoryStatusLabel('completed', true), 'Completed');
  assert.equal(getHistoryStatusLabel('no_show'), 'No Show');
});

test('history detail: Cancellation Reason for a cancel, Decline Reason only for a declined assignment', () => {
  const cancelled = normalizeVisitHistoryRecord(row({ cancelledByVisitor: true }), 0);
  const declined = normalizeVisitHistoryRecord(row({ cancelledByVisitor: false }), 0);
  assert.equal(getHistoryReasonLabel(cancelled), 'Cancellation Reason');
  assert.equal(getHistoryReasonLabel(declined), 'Decline Reason');
  assert.equal(getHistoryReasonLabel({ status: 'cancelled' }), 'Cancellation Reason');
  assert.equal(getHistoryReasonLabel({ status: 'no_show' }), 'Cancellation Reason');
  assert.equal(getHistoryReasonLabel(null), 'Cancellation Reason');
});
