// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  isPassExpired,
  isQrPassDay,
  isUpcomingVisit,
  pickNextVisit,
  passValidUntilTime,
  pickQrVisit,
  qrPassSelection,
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
    timeLabel: '09:00 - 16:30',
    isWholeDay: true,
    sessions: [
      { period: 'morning', timeLabel: '09:00 - 11:30' },
      { period: 'afternoon', timeLabel: '13:00 - 16:30' },
    ],
  };

  assert.equal(pickNextVisit([wholeDay], NOW), wholeDay);
  assert.equal(pickQrVisit([wholeDay], NOW), wholeDay);
  assert.equal(visitTimeText(wholeDay), '09:00 - 16:30 · Whole day');
  assert.equal(
    visitSessionsText(wholeDay.sessions),
    'Morning 09:00 - 11:30 · Afternoon 13:00 - 16:30',
  );
});

test('a single-session visit keeps its own time line', () => {
  const single = { timeLabel: '13:00 - 16:30', isWholeDay: false, sessions: [{ period: 'afternoon', timeLabel: '13:00 - 16:30' }] };
  assert.equal(visitTimeText(single), '13:00 - 16:30');
  assert.equal(visitSessionsText(single.sessions), null);
  assert.equal(visitTimeText(null), '');
});

test('qrPassSelection hides the pass of a confirmed future visit', () => {
  const future = visit(1, 'confirmed', '2026-10-10');
  assert.deepEqual(qrPassSelection([future], NOW), { visit: future, passDay: false });
});

test('qrPassSelection shows the pass of a confirmed visit dated today (Manila)', () => {
  const today = visit(1, 'confirmed', '2026-10-08', '09:00');
  const later = visit(2, 'confirmed', '2026-10-10');
  assert.deepEqual(qrPassSelection([later, today], NOW), { visit: today, passDay: true });
});

test('qrPassSelection shows no pass for ineligible statuses', () => {
  for (const status of ['assigned', 'pending_confirmation', 'declined', 'cancelled', 'completed', 'no_show']) {
    assert.equal(qrPassSelection([visit(1, status, '2026-10-08')], NOW), null, status);
  }
  assert.equal(qrPassSelection([visit(1, 'confirmed', '2026-10-07')], NOW), null);
});

test('isQrPassDay flips at Manila midnight, not UTC midnight', () => {
  const oct9 = visit(1, 'confirmed', '2026-10-09');
  // 23:59:59 Oct 8 in Manila (15:59:59Z): still hidden.
  assert.equal(isQrPassDay(oct9, new Date('2026-10-08T15:59:59Z')), false);
  // 00:00 Oct 9 in Manila (16:00Z, still Oct 8 in UTC): shown.
  assert.equal(isQrPassDay(oct9, new Date('2026-10-08T16:00:00Z')), true);
  // 23:59 Oct 9 in Manila: still shown; 00:00 Oct 10: gone.
  assert.equal(isQrPassDay(oct9, new Date('2026-10-09T15:59:00Z')), true);
  assert.equal(isQrPassDay(oct9, new Date('2026-10-09T16:00:00Z')), false);
});

test('isQrPassDay keeps a whole-day visit shown for both sessions', () => {
  const wholeDay = { ...visit(1, 'confirmed', '2026-10-08', '09:00'), isWholeDay: true };
  assert.equal(isQrPassDay(wholeDay, new Date('2026-10-08T01:00:00Z')), true); // 09:00 morning
  assert.equal(isQrPassDay(wholeDay, new Date('2026-10-08T04:00:00Z')), true); // 12:00 midday break
  assert.equal(isQrPassDay(wholeDay, new Date('2026-10-08T07:00:00Z')), true); // 15:00 afternoon
});

test('isQrPassDay is false without a visit date', () => {
  assert.equal(isQrPassDay({ scheduledAt: null }, NOW), false);
  assert.equal(isQrPassDay(null, NOW), false);
});

test('passValidUntilTime reads the Manila end time whatever the phone timezone', () => {
  assert.equal(passValidUntilTime('2026-10-09T16:30:00+08:00'), '16:30');
  assert.equal(passValidUntilTime('2026-10-09T11:30:00+08:00'), '11:30');
  assert.equal(passValidUntilTime(null), null);
  assert.equal(passValidUntilTime(''), null);
});

test('isPassExpired: a pass valid until 16:30 is not expired hours after it was issued', () => {
  const expiresAt = '2026-10-09T16:30:00+08:00';
  // Issued 08:00, still valid at 13:30 (5.5 h later) and 16:29.
  assert.equal(isPassExpired(expiresAt, new Date('2026-10-09T05:30:00Z')), false);
  assert.equal(isPassExpired(expiresAt, new Date('2026-10-09T08:29:00Z')), false);
  // 16:30 Manila and later: expired.
  assert.equal(isPassExpired(expiresAt, new Date('2026-10-09T08:30:00Z')), true);
  assert.equal(isPassExpired(null, NOW), false);
});
