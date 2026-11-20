// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { canCancelVisit, canRespondToVisit } from './visitRules.js';

/** Friday 2026-10-09, first session at 09:00 Manila time. */
const START = '2026-10-09T09:00:00+08:00';
const BEFORE = Date.parse('2026-10-09T08:59:00+08:00');
const AT_START = Date.parse(START);

test('the visitor can cancel their own request and a confirmed visit before it starts', () => {
  assert.equal(canCancelVisit({ status: 'assigned', scheduledAt: START }, BEFORE), true);
  assert.equal(canCancelVisit({ status: 'confirmed', scheduledAt: START }, BEFORE), true);
});

test('cancelling ends when the first session starts', () => {
  assert.equal(canCancelVisit({ status: 'confirmed', scheduledAt: START }, AT_START), false);
  assert.equal(canCancelVisit({ status: 'assigned', scheduledAt: START }, AT_START + 60_000), false);
});

test('a visit awaiting the visitor’s answer is declined, not cancelled', () => {
  const pending = { status: 'pending_confirmation', scheduledAt: START };
  assert.equal(canCancelVisit(pending, BEFORE), false);
  assert.equal(canRespondToVisit(pending.status), true);
});

test('finished or unknown visits cannot be cancelled', () => {
  for (const status of ['declined', 'cancelled', 'completed', 'no_show', 'checked_in', undefined]) {
    assert.equal(canCancelVisit({ status, scheduledAt: START }, BEFORE), false, String(status));
  }
  assert.equal(canCancelVisit(null, BEFORE), false);
  assert.equal(canCancelVisit(undefined, BEFORE), false);
});

test('an unknown start time leaves the decision to the server', () => {
  assert.equal(canCancelVisit({ status: 'confirmed', scheduledAt: null }, BEFORE), true);
  assert.equal(canCancelVisit({ status: 'confirmed', scheduledAt: 'not a date' }, BEFORE), true);
});
