// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  NO_UPCOMING_VISIT_TITLE,
  NO_UPCOMING_VISIT_UNVERIFIED,
  RELATIONSHIP_NOT_VERIFIED_SCHEDULE,
  getNoUpcomingVisitState,
} from './visitGuidance.js';

test('without a verified relationship, Home does not offer to request a visit', () => {
  const state = getNoUpcomingVisitState({ canRequestVisit: false });
  assert.equal(state.title, "You don't have an upcoming visit yet.");
  assert.equal(
    state.message,
    'Once your relationship is verified and a visit is scheduled, your visit will appear here.',
  );
  assert.equal(state.showRequestAction, false);
  assert.doesNotMatch(state.message, /request|available slot/i);
});

test('with a verified relationship, Home offers the request action', () => {
  const state = getNoUpcomingVisitState({ canRequestVisit: true });
  assert.equal(state.title, NO_UPCOMING_VISIT_TITLE);
  assert.equal(state.showRequestAction, true);
  assert.match(state.message, /request a visit/i);
  assert.match(state.message, /Staff will review/);
  assert.notEqual(state.message, NO_UPCOMING_VISIT_UNVERIFIED);
});

test('the calendar explains that staff verify the relationship first', () => {
  assert.equal(
    RELATIONSHIP_NOT_VERIFIED_SCHEDULE,
    'Visit schedules will be available after staff verify your relationship to the PDL.',
  );
});
