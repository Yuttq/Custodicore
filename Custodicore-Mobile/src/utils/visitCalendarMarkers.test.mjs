// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  buildVisitDateMarkers,
  visitMarkerKind,
  visitsForRelationship,
} from './visitCalendarMarkers.js';

const TODAY = '2026-10-08';

/** Mirrors GET /api/visits: `scheduledAt` is a Manila local timestamp. */
function visit(id, status, date, pdlName = 'Juan Dela Cruz', time = '09:00') {
  return { id, status, pdlName, scheduledAt: `${date}T${time}:00+08:00` };
}

test('only confirmed visits are marked confirmed', () => {
  assert.equal(visitMarkerKind('confirmed'), 'confirmed');
  assert.equal(visitMarkerKind('assigned'), 'pending');
  assert.equal(visitMarkerKind('pending_confirmation'), 'pending');
  for (const status of ['completed', 'cancelled', 'declined', 'no_show', 'scheduled', '', null, undefined]) {
    assert.equal(visitMarkerKind(status), null, String(status));
  }
});

test('pending requests are never shown as confirmed', () => {
  const markers = buildVisitDateMarkers(
    [
      visit(1, 'assigned', '2026-10-09'),
      visit(2, 'pending_confirmation', '2026-10-10'),
      visit(3, 'confirmed', '2026-10-11'),
    ],
    TODAY,
  );
  assert.deepEqual(markers, {
    '2026-10-09': 'pending',
    '2026-10-10': 'pending',
    '2026-10-11': 'confirmed',
  });
});

test('a date with any pending visit stays pending, in either order', () => {
  const pending = visit(1, 'assigned', '2026-10-09', 'A');
  const confirmed = visit(2, 'confirmed', '2026-10-09', 'A', '13:00');
  assert.deepEqual(buildVisitDateMarkers([pending, confirmed], TODAY), { '2026-10-09': 'pending' });
  assert.deepEqual(buildVisitDateMarkers([confirmed, pending], TODAY), { '2026-10-09': 'pending' });
});

test('past, terminal and undated visits are not marked', () => {
  const markers = buildVisitDateMarkers(
    [
      visit(1, 'confirmed', '2026-10-07'),
      visit(2, 'cancelled', '2026-10-12'),
      visit(3, 'completed', '2026-10-12'),
      { id: 4, status: 'confirmed', scheduledAt: null },
    ],
    TODAY,
  );
  assert.deepEqual(markers, {});
  assert.deepEqual(buildVisitDateMarkers(undefined, TODAY), {});
});

test('the marker date is the calendar date written in scheduledAt (API sends +08:00)', () => {
  // 23:30 Manila on the 8th is 15:30Z; the date part of the API's
  // Manila-local timestamp is used as-is, so it stays on the 8th.
  const late = visit(1, 'confirmed', '2026-10-08', 'A', '23:30');
  assert.deepEqual(buildVisitDateMarkers([late], TODAY), { '2026-10-08': 'confirmed' });
});

test('a whole-day visit (two sessions) is one marker on its date', () => {
  const wholeDay = {
    id: 14,
    status: 'assigned',
    pdlName: 'A',
    scheduledAt: '2026-10-23T09:00:00+08:00',
    endAt: '2026-10-23T16:30:00+08:00',
    isWholeDay: true,
    sessions: [
      { period: 'morning', startTime: '09:00', endTime: '11:30' },
      { period: 'afternoon', startTime: '13:00', endTime: '16:30' },
    ],
  };
  assert.deepEqual(buildVisitDateMarkers([wholeDay], TODAY), { '2026-10-23': 'pending' });
  const approved = { ...wholeDay, status: 'confirmed' };
  assert.deepEqual(buildVisitDateMarkers([approved], TODAY), { '2026-10-23': 'confirmed' });
});

test('visits are matched to the relationship by PDL name', () => {
  const a = { relationshipId: '1', pdlName: 'Juan Dela Cruz' };
  const b = { relationshipId: '2', pdlName: 'Pedro Santos' };
  const visits = [
    visit(1, 'assigned', '2026-10-09', 'Juan Dela Cruz'),
    visit(2, 'confirmed', '2026-10-10', 'Pedro Santos'),
    visit(3, 'confirmed', '2026-10-11', ' Juan Dela Cruz '),
  ];
  assert.deepEqual(visitsForRelationship(visits, a, [a, b]).map((v) => v.id), [1, 3]);
  assert.deepEqual(visitsForRelationship(visits, b, [a, b]).map((v) => v.id), [2]);
});

test('no markers when the PDL name is shared or missing', () => {
  const a = { relationshipId: '1', pdlName: 'Juan Dela Cruz' };
  const twin = { relationshipId: '2', pdlName: 'Juan Dela Cruz' };
  const unnamed = { relationshipId: '3', pdlName: null };
  const visits = [visit(1, 'confirmed', '2026-10-09', 'Juan Dela Cruz'), visit(2, 'confirmed', '2026-10-10', null)];
  assert.deepEqual(visitsForRelationship(visits, a, [a, twin]), []);
  assert.deepEqual(visitsForRelationship(visits, unnamed, [a, unnamed]), []);
  assert.deepEqual(visitsForRelationship(visits, null, [a]), []);
  assert.deepEqual(visitsForRelationship(undefined, a, [a]), []);
});
