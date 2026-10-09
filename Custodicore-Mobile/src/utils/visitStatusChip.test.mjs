// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { resolveVisitStatusChip } from './visitStatusChip.js';

test('backend visit statuses keep their own chip', () => {
  for (const status of [
    'assigned',
    'pending_confirmation',
    'confirmed',
    'declined',
    'cancelled',
    'completed',
    'no_show',
  ]) {
    assert.equal(resolveVisitStatusChip(status), status, status);
  }
});

test('checked_out reads as completed, not pending', () => {
  assert.equal(resolveVisitStatusChip('checked_out'), 'completed');
});

test('scheduled reads as awaiting confirmation', () => {
  assert.equal(resolveVisitStatusChip('scheduled'), 'pending_confirmation');
});

test('unable_to_attend reads as declined', () => {
  assert.equal(resolveVisitStatusChip('unable_to_attend'), 'declined');
});

test('gate statuses keep their own chip', () => {
  assert.equal(resolveVisitStatusChip('qr_ready'), 'qr_ready');
  assert.equal(resolveVisitStatusChip('checked_in'), 'checked_in');
});

test('status spelling variants are normalized', () => {
  assert.equal(resolveVisitStatusChip('Checked-Out'), 'completed');
  assert.equal(resolveVisitStatusChip(' COMPLETED '), 'completed');
  assert.equal(resolveVisitStatusChip('no show'), 'no_show');
});

test('unknown, null and missing statuses fall back to pending, never completed', () => {
  for (const status of ['', 'archived', 'used', null, undefined, 42, {}]) {
    assert.equal(resolveVisitStatusChip(status), 'pending', String(status));
  }
});

test('object prototype keys are not treated as statuses', () => {
  for (const status of ['constructor', 'toString', '__proto__', 'hasOwnProperty']) {
    assert.equal(resolveVisitStatusChip(status), 'pending', status);
  }
});

test('every mapped chip is a StatusChip variant', () => {
  const source = readFileSync(
    new URL('../designSystem/components/StatusChip.js', import.meta.url),
    'utf8',
  );
  const statuses = [
    'assigned', 'pending_confirmation', 'scheduled', 'confirmed', 'qr_ready', 'checked_in',
    'checked_out', 'completed', 'cancelled', 'declined', 'no_show', 'unable_to_attend', null,
  ];
  for (const status of statuses) {
    const chip = resolveVisitStatusChip(status);
    assert.match(source, new RegExp(`^\\s+${chip}: \\{`, 'm'), `${status} -> ${chip}`);
  }
});
