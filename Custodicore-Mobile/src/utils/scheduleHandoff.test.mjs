// Run with: npm test  (node --test, no extra dependencies)
//
// These exercise the pure handoff decisions. They do not render
// MyAssignedVisitsScreen, so React effect ordering and navigation behavior
// still need checking in the running app.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  INITIAL_HANDOFF,
  handoffReducer,
  nextHandoffStep,
  readHandoffParams,
} from './scheduleHandoff.js';

const A = { relationshipId: '1' };
const B = { relationshipId: '2' };

/** Availability as useScheduleAvailability exposes it. */
function schedule(overrides = {}) {
  return {
    loading: false,
    error: null,
    relationships: [A, B],
    relationshipId: '1',
    daysByDate: {
      '2026-10-16': { available: true },
      '2026-10-17': { available: false },
    },
    ...overrides,
  };
}

/** Runs params → request → steps the way the screen does, until it settles. */
function runHandoff(params, loadingAtArrival, scheduleAfterLoad) {
  const { request } = readHandoffParams(params, loadingAtArrival);
  let state = handoffReducer(INITIAL_HANDOFF, { type: 'request', request });
  let current = { ...scheduleAfterLoad };
  const actions = [];
  for (let i = 0; i < 5; i += 1) {
    const step = nextHandoffStep(state.request, current);
    actions.push(step.type);
    if (step.type === 'idle' || step.type === 'wait') break;
    if (step.type === 'select_relationship') {
      current = { ...current, relationshipId: step.relationshipId };
      continue;
    }
    const notice = step.type === 'date_unavailable' ? { date: step.date } : null;
    state = handoffReducer(state, { type: 'settle', request: state.request, notice });
    break;
  }
  return { state, actions, relationshipId: current.relationshipId };
}

test('params are consumed once; absent params do nothing', () => {
  assert.deepEqual(readHandoffParams({ tab: 'schedule' }, false), {
    consumed: false,
    request: null,
    reload: false,
  });
  assert.deepEqual(readHandoffParams(undefined, false).consumed, false);
  // Cleared params (setParams({ date: undefined, … })) read as absent, so a
  // revisit cannot re-apply them.
  assert.equal(readHandoffParams({ date: undefined, relationshipId: undefined }, false).consumed, false);
});

test('Schedule not loaded yet: its first load is used, no extra reload', () => {
  const read = readHandoffParams({ date: '2026-10-16', relationshipId: '1' }, true);
  assert.equal(read.reload, false);
  const state = handoffReducer(INITIAL_HANDOFF, { type: 'request', request: read.request });
  // Nothing loaded yet: no relationships while loading → wait, not drop.
  assert.deepEqual(
    nextHandoffStep(state.request, schedule({ loading: true, relationships: [], relationshipId: null })),
    { type: 'wait' },
  );
  const done = runHandoff({ date: '2026-10-16', relationshipId: '1' }, true, schedule());
  assert.deepEqual(done.actions, ['select_date']);
  assert.equal(done.state.request, null);
});

test('Schedule already loading (e.g. pull to refresh): waits for that load', () => {
  const read = readHandoffParams({ date: '2026-10-16', relationshipId: '1' }, true);
  assert.equal(read.reload, false);
  assert.deepEqual(nextHandoffStep(read.request, schedule({ loading: true })), { type: 'wait' });
});

test('Schedule loaded earlier and revisited: reloads, then selects on fresh data', () => {
  const read = readHandoffParams({ date: '2026-10-16', relationshipId: '1' }, false);
  assert.equal(read.reload, true);
  // The reload is in flight: old data must not be used.
  assert.deepEqual(nextHandoffStep(read.request, schedule({ loading: true })), { type: 'wait' });
  // Fresh data says the date filled up meanwhile → notice, not a selection.
  const full = schedule({ daysByDate: { '2026-10-16': { available: false } } });
  const result = runHandoff({ date: '2026-10-16', relationshipId: '1' }, false, full);
  assert.deepEqual(result.actions, ['date_unavailable']);
  assert.deepEqual(result.state, { request: null, notice: { date: '2026-10-16' } });
});

test('availability failing to load drops the request without selecting', () => {
  const result = runHandoff(
    { date: '2026-10-16', relationshipId: '1' },
    false,
    schedule({ error: 'Could not load visit availability.' }),
  );
  assert.deepEqual(result.actions, ['drop']);
  assert.deepEqual(result.state, INITIAL_HANDOFF);
  // Not approved / no verified relationship: nothing to select either.
  const none = runHandoff({ date: '2026-10-16' }, false, schedule({ relationships: [], relationshipId: null }));
  assert.deepEqual(none.actions, ['drop']);
});

test('a relationship that is no longer listed is not replaced by another one', () => {
  const result = runHandoff({ date: '2026-10-16', relationshipId: '9' }, false, schedule());
  assert.deepEqual(result.actions, ['drop']);
  assert.equal(result.relationshipId, '1');
});

test('another listed relationship is selected before its date is checked', () => {
  const result = runHandoff({ date: '2026-10-16', relationshipId: '2' }, false, schedule({ relationshipId: '1' }));
  assert.deepEqual(result.actions, ['select_relationship', 'select_date']);
  assert.equal(result.relationshipId, '2');
});

test('a relationship-only request works without a date', () => {
  const read = readHandoffParams({ relationshipId: 2 }, false);
  assert.deepEqual(read.request, { date: null, relationshipId: '2' });
  const result = runHandoff({ relationshipId: 2 }, false, schedule({ relationshipId: '1' }));
  assert.deepEqual(result.actions, ['select_relationship', 'done']);
  assert.equal(result.relationshipId, '2');
  assert.deepEqual(result.state, INITIAL_HANDOFF);
});

test('an invalid date does not discard a valid relationship', () => {
  assert.deepEqual(readHandoffParams({ date: '2026-02-30', relationshipId: '2' }, false).request, {
    date: null,
    relationshipId: '2',
  });
  for (const date of ['2026-02-30', 'tomorrow', '', null, 20261016]) {
    const read = readHandoffParams({ date }, false);
    assert.equal(read.consumed, true, String(date));
    assert.equal(read.request, null, String(date));
  }
  assert.equal(readHandoffParams({ relationshipId: '  ' }, false).request, null);
});

test('a date-only request (no relationshipId) uses the current relationship', () => {
  const result = runHandoff({ date: '2026-10-16' }, false, schedule({ relationshipId: '2' }));
  assert.deepEqual(result.actions, ['select_date']);
  assert.equal(result.relationshipId, '2');
});

test('a manual date or relationship choice invalidates the waiting request', () => {
  const { request } = readHandoffParams({ date: '2026-10-16', relationshipId: '2' }, false);
  let state = handoffReducer(INITIAL_HANDOFF, { type: 'request', request });
  assert.deepEqual(nextHandoffStep(state.request, schedule({ loading: true })), { type: 'wait' });
  state = handoffReducer(state, { type: 'manual' });
  assert.deepEqual(state, INITIAL_HANDOFF);
  // When the delayed load lands, there is nothing left to apply.
  assert.deepEqual(nextHandoffStep(state.request, schedule()), { type: 'idle' });
});

test('a manual choice also clears an unavailable-date notice', () => {
  const noticed = { request: null, notice: { date: '2026-10-17' } };
  assert.deepEqual(handoffReducer(noticed, { type: 'manual' }), INITIAL_HANDOFF);
  // With nothing to clear, the same state is returned (no extra render).
  assert.equal(handoffReducer(INITIAL_HANDOFF, { type: 'manual' }), INITIAL_HANDOFF);
});

test('a stale request cannot override a newer manual selection or newer request', () => {
  const first = readHandoffParams({ date: '2026-10-16' }, false).request;
  let state = handoffReducer(INITIAL_HANDOFF, { type: 'request', request: first });
  state = handoffReducer(state, { type: 'manual' });
  // Settling the old request after the manual choice changes nothing.
  assert.deepEqual(
    handoffReducer(state, { type: 'settle', request: first, notice: { date: '2026-10-16' } }),
    INITIAL_HANDOFF,
  );

  const second = readHandoffParams({ date: '2026-10-18' }, false).request;
  state = handoffReducer(INITIAL_HANDOFF, { type: 'request', request: first });
  state = handoffReducer(state, { type: 'request', request: second });
  // The first request settling late must not finish the second one.
  const afterStale = handoffReducer(state, { type: 'settle', request: first });
  assert.equal(afterStale.request, second);
  assert.deepEqual(handoffReducer(afterStale, { type: 'settle', request: second }), INITIAL_HANDOFF);
});

test('a new request clears an old notice', () => {
  const noticed = { request: null, notice: { date: '2026-10-17' } };
  const request = readHandoffParams({ date: '2026-10-16' }, false).request;
  assert.deepEqual(handoffReducer(noticed, { type: 'request', request }), { request, notice: null });
});
