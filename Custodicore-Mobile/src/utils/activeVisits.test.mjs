// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  isUpcomingVisit,
  pickNextVisit,
  pickQrVisit,
  visitSessionsText,
  visitTimeText,
} from './activeVisits.js';

/** 2026-10-08 14:00 in Manila (UTC+8). */
const NOW = new Date('2026-10-08T06:00:00Z');

/** Mirrors GET /api/visits: `scheduledAt` is a Manila local timestamp. */
function visit(id, status, date, time = '09:00') {
  return { id, status, scheduledAt: `${date}T${time}:00+08:00` };
}

test('isUpcomingVisit compares the Manila calendar date', () => {
  assert.equal(isUpcomingVisit(visit(1, 'assigned', '2026-10-08'), '2026-10-08'), true);
  assert.equal(isUpcomingVisit(visit(1, 'confirmed', '2026-10-09'), '2026-10-08'), true);
  assert.equal(isUpcomingVisit(visit(1, 'confirmed', '2026-10-07'), '2026-10-08'), false);
  assert.equal(isUpcomingVisit(visit(1, 'completed', '2026-10-09'), '2026-10-08'), false);
  assert.equal(isUpcomingVisit({ id: 1, status: 'confirmed', scheduledAt: null }, '2026-10-08'), false);
});

test('pickNextVisit ignores terminal statuses', () => {
  for (const status of ['completed', 'cancelled', 'declined', 'no_show']) {
    assert.equal(pickNextVisit([visit(1, status, '2026-10-09')], NOW), null, status);
  }
});

test('pickNextVisit ignores past assigned / pending_confirmation / confirmed visits', () => {
  for (const status of ['assigned', 'pending_confirmation', 'confirmed']) {
    assert.equal(pickNextVisit([visit(1, status, '2026-10-07', '16:00')], NOW), null, status);
  }
});

test("pickNextVisit keeps today's visit even after its time has ended", () => {
  const morning = visit(1, 'confirmed', '2026-10-08', '08:00');
  assert.equal(pickNextVisit([morning], NOW), morning);
});

test('pickNextVisit selects the earliest today/future visit', () => {
  const later = visit(1, 'assigned', '2026-10-12');
  const soonest = visit(2, 'pending_confirmation', '2026-10-08', '15:00');
  const middle = visit(3, 'confirmed', '2026-10-10');
  const past = visit(4, 'confirmed', '2026-10-01');
  const done = visit(5, 'completed', '2026-10-08', '08:00');
  assert.equal(pickNextVisit([later, past, done, middle, soonest], NOW), soonest);
});

test('pickNextVisit returns null for empty input', () => {
  assert.equal(pickNextVisit([], NOW), null);
  assert.equal(pickNextVisit(undefined, NOW), null);
});

test('pickNextVisit rolls "today" over at Manila midnight', () => {
  const oct7 = visit(1, 'confirmed', '2026-10-07', '10:00');
  // 23:59 Manila on Oct 7 — still today.
  assert.equal(pickNextVisit([oct7], new Date('2026-10-07T15:59:00Z')), oct7);
  // 00:00 Manila on Oct 8 — now in the past, although UTC is still Oct 7.
  assert.equal(pickNextVisit([oct7], new Date('2026-10-07T16:00:00Z')), null);
});

test("pickQrVisit selects today's confirmed visit", () => {
  const today = visit(1, 'confirmed', '2026-10-08', '10:00');
  assert.equal(pickQrVisit([today], NOW), today);
});

test('pickQrVisit selects a future confirmed visit', () => {
  const future = visit(1, 'confirmed', '2026-10-20');
  assert.equal(pickQrVisit([future], NOW), future);
});

test('pickQrVisit selects the nearest confirmed visit regardless of array order', () => {
  const far = visit(1, 'confirmed', '2026-10-20');
  const near = visit(2, 'confirmed', '2026-10-09', '13:00');
  const nearEarlier = visit(3, 'confirmed', '2026-10-09', '09:00');
  assert.equal(pickQrVisit([far, near, nearEarlier], NOW), nearEarlier);
  assert.equal(pickQrVisit([nearEarlier, far, near], NOW), nearEarlier);
});

test('pickQrVisit returns null when only assigned visits exist', () => {
  assert.equal(pickQrVisit([visit(1, 'assigned', '2026-10-09')], NOW), null);
});

test('pickQrVisit returns null when only pending_confirmation visits exist', () => {
  assert.equal(pickQrVisit([visit(1, 'pending_confirmation', '2026-10-09')], NOW), null);
});

test('pickQrVisit returns null when only terminal statuses exist', () => {
  const visits = ['completed', 'cancelled', 'declined', 'no_show'].map((status, i) =>
    visit(i + 1, status, '2026-10-09'),
  );
  assert.equal(pickQrVisit(visits, NOW), null);
});

test('pickQrVisit skips a past confirmed visit when a future one exists', () => {
  const past = visit(1, 'confirmed', '2026-10-07', '10:00');
  const future = visit(2, 'confirmed', '2026-10-15');
  assert.equal(pickQrVisit([past, future], NOW), future);
});

test('pickQrVisit never falls back to visits[0]', () => {
  const first = visit(1, 'assigned', '2026-10-09');
  const visits = [
    first,
    visit(2, 'confirmed', '2026-10-01'),
    visit(3, 'completed', '2026-10-08'),
  ];
  assert.equal(pickQrVisit(visits, NOW), null);
  assert.equal(pickQrVisit([], NOW), null);
});

test('a whole-day visit is one visit with both sessions', () => {
  const wholeDay = {
    ...visit(7, 'confirmed', '2026-10-09'),
    timeLabel: '9:00 AM - 4:30 PM',
    isWholeDay: true,
    sessions: [
      { period: 'morning', timeLabel: '9:00 AM - 11:30 AM' },
      { period: 'afternoon', timeLabel: '1:00 PM - 4:30 PM' },
    ],
  };

  assert.equal(pickNextVisit([wholeDay], NOW), wholeDay);
  assert.equal(pickQrVisit([wholeDay], NOW), wholeDay);
  assert.equal(visitTimeText(wholeDay), '9:00 AM - 4:30 PM · Whole day');
  assert.equal(
    visitSessionsText(wholeDay.sessions),
    'Morning 9:00 AM - 11:30 AM · Afternoon 1:00 PM - 4:30 PM',
  );
});

test('a single-session visit keeps its own time line', () => {
  const single = { timeLabel: '1:00 PM - 4:30 PM', isWholeDay: false, sessions: [{ period: 'afternoon', timeLabel: '1:00 PM - 4:30 PM' }] };
  assert.equal(visitTimeText(single), '1:00 PM - 4:30 PM');
  assert.equal(visitSessionsText(single.sessions), null);
  assert.equal(visitTimeText(null), '');
});
