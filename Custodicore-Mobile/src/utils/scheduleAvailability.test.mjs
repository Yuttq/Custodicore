// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  addDaysIso,
  buildMonthGrid,
  dayOfWeekIso,
  formatDateLong,
  formatSlotRange,
  formatTime12,
  formatVisitingDays,
  manilaTodayIso,
  middayBreakLabel,
  normalizeAvailabilityResponse,
  parseIsoDate,
  periodLabel,
  shiftMonth,
  slotReasonLabel,
  toVisitRequestParams,
  visitingWindows,
} from './scheduleAvailability.js';

test('date helpers work in UTC regardless of device timezone', () => {
  assert.deepEqual(parseIsoDate('2026-10-08'), { year: 2026, month: 10, day: 8 });
  assert.equal(parseIsoDate('2026-02-30'), null);
  assert.equal(parseIsoDate('10/08/2026'), null);
  assert.equal(addDaysIso('2026-10-31', 1), '2026-11-01');
  assert.equal(addDaysIso('2026-12-31', 1), '2027-01-01');
  assert.equal(dayOfWeekIso('2026-10-08'), 4); // Thursday
  assert.deepEqual(shiftMonth(2026, 12, 1), { year: 2027, month: 1 });
  assert.deepEqual(shiftMonth(2026, 1, -1), { year: 2025, month: 12 });
});

test('Manila "today" rolls over at 16:00 UTC', () => {
  assert.equal(manilaTodayIso(new Date('2026-10-07T15:59:00Z')), '2026-10-07');
  assert.equal(manilaTodayIso(new Date('2026-10-07T16:00:00Z')), '2026-10-08');
});

test('month grid is Sunday-first with null padding', () => {
  const weeks = buildMonthGrid(2026, 10); // Oct 1 2026 is a Thursday
  assert.ok(weeks.every((w) => w.length === 7));
  assert.deepEqual(weeks[0].slice(0, 5), [null, null, null, null, '2026-10-01']);
  assert.equal(weeks.flat().filter(Boolean).length, 31);
  assert.equal(weeks.flat().filter(Boolean).at(-1), '2026-10-31');
});

test('time and day formatting', () => {
  assert.equal(formatTime12('09:00'), '9:00 AM');
  assert.equal(formatTime12('13:00'), '1:00 PM');
  assert.equal(formatTime12('12:30'), '12:30 PM');
  assert.equal(formatSlotRange('13:00', '16:30'), '1:00 PM – 4:30 PM');
  assert.equal(formatDateLong('2026-10-08'), 'Thursday, October 8, 2026');
  assert.equal(formatVisitingDays(['thu', 'sat']), 'Thursday and Saturday');
  assert.equal(formatVisitingDays(['fri', 'sun']), 'Friday and Sunday');
  assert.equal(formatVisitingDays([]), '');
});

test('unavailable reasons map to visitor-facing labels', () => {
  assert.equal(slotReasonLabel('full'), 'Full');
  assert.equal(slotReasonLabel('closed'), 'Closed');
  assert.equal(slotReasonLabel('past'), 'Past');
  assert.equal(slotReasonLabel('ended'), 'Past');
  assert.equal(slotReasonLabel('not_eligible'), 'Not available for your schedule');
  assert.equal(slotReasonLabel('weekly_limit'), 'Weekly limit reached');
  assert.equal(slotReasonLabel('something_new'), 'Unavailable');
});

const SAMPLE = {
  timezone: 'Asia/Manila',
  today: '2026-10-07',
  from: '2026-10-07',
  to: '2026-10-09',
  message: null,
  relationships: [
    {
      relationshipId: 5,
      pdlName: 'Example PDL',
      classification: 'non_drug_related',
      visitingDays: ['fri', 'sun'],
      pdlAvailable: true,
      days: [
        { date: '2026-10-08', slots: [
          { slotKey: '2026-10-08|13:00', startTime: '13:00', endTime: '16:30', available: false, reason: 'not_eligible' },
          { slotKey: '2026-10-08|09:00', startTime: '09:00', endTime: '11:30', available: false, reason: 'not_eligible' },
        ] },
        { date: '2026-10-09', slots: [
          { slotKey: '2026-10-09|09:00', startTime: '09:00:00', endTime: '11:30:00', available: false, reason: 'full', capacity: 30, slotsRemaining: 0 },
          { slotKey: '2026-10-09|13:00', startTime: '13:00', endTime: '16:30', available: true, reason: null, capacity: 30, slotsRemaining: 12 },
        ] },
      ],
    },
  ],
};

test('availability response is normalized and indexed by date', () => {
  const data = normalizeAvailabilityResponse(SAMPLE);
  const rel = data.relationships[0];

  assert.equal(rel.relationshipId, '5');
  assert.equal(rel.daysByDate['2026-10-08'].available, false);
  assert.equal(rel.daysByDate['2026-10-09'].available, true);
  // sorted morning first, HH:MM:SS trimmed
  assert.deepEqual(rel.daysByDate['2026-10-08'].slots.map((s) => s.startTime), ['09:00', '13:00']);
  assert.equal(rel.daysByDate['2026-10-09'].slots[0].startTime, '09:00');
  assert.equal(rel.daysByDate['2026-10-09'].slots[1].slotsRemaining, 12);
  assert.equal(rel.daysByDate['2026-10-09'].slots[1].relationshipId, '5');
  assert.deepEqual(
    visitingWindows(rel).map((w) => `${w.startTime}-${w.endTime}`),
    ['09:00-11:30', '13:00-16:30'],
  );
});

test('empty / no-relationship response keeps the backend message', () => {
  const data = normalizeAvailabilityResponse({
    today: '2026-10-07',
    message: 'A verified PDL relationship is required to view visit availability.',
    relationships: [],
  });
  assert.deepEqual(data.relationships, []);
  assert.match(data.message, /verified PDL relationship/);
  assert.deepEqual(normalizeAvailabilityResponse(null).relationships, []);
});

test('Phase 4 handoff params carry only ids and the chosen date/time', () => {
  const params = toVisitRequestParams({
    relationshipId: '5',
    date: '2026-10-09',
    startTime: '13:00',
    endTime: '16:30',
    period: 'afternoon',
    slotKey: '2026-10-09|13:00',
  });
  assert.deepEqual(params, { relationshipId: '5', date: '2026-10-09', startTime: '13:00', endTime: '16:30' });
  assert.equal(toVisitRequestParams(null), null);
  assert.equal(toVisitRequestParams({ relationshipId: '5', date: 'bad', startTime: '13:00' }), null);
});

const WHOLE_DAY_SAMPLE = {
  today: '2026-10-07',
  relationships: [
    {
      relationshipId: 5,
      days: [
        {
          date: '2026-10-09',
          wholeDay: { available: true, reason: null, startTime: '09:00', endTime: '16:30', startTimes: ['09:00', '13:00'] },
          slots: [
            { startTime: '13:00', endTime: '16:30', available: true, capacity: 30, slotsRemaining: 12 },
            { startTime: '09:00', endTime: '11:30', available: true, capacity: 30, slotsRemaining: 3 },
          ],
        },
        {
          date: '2026-10-11',
          wholeDay: { available: false, reason: 'full', startTime: '09:00', endTime: '16:30', startTimes: ['09:00', '13:00'] },
          slots: [
            { startTime: '09:00', endTime: '11:30', available: false, reason: 'full', capacity: 30, slotsRemaining: 0 },
            { startTime: '13:00', endTime: '16:30', available: true, capacity: 30, slotsRemaining: 30 },
          ],
        },
        {
          date: '2026-10-08',
          wholeDay: null,
          slots: [{ startTime: '09:00', endTime: '11:30', available: false, reason: 'not_eligible' }],
        },
      ],
    },
  ],
};

test('whole day is one selectable option spanning both sessions', () => {
  const rel = normalizeAvailabilityResponse(WHOLE_DAY_SAMPLE).relationships[0];
  const friday = rel.daysByDate['2026-10-09'].wholeDay;

  assert.equal(friday.slotKey, '2026-10-09|whole_day');
  assert.equal(friday.period, 'whole_day');
  assert.equal(periodLabel(friday.period, friday.startTime), 'Whole day');
  assert.equal(friday.startTime, '09:00');
  assert.equal(friday.endTime, '16:30');
  assert.deepEqual(friday.startTimes, ['09:00', '13:00']);
  assert.equal(friday.available, true);
  assert.equal(friday.slotsRemaining, 3, 'the tightest session limits the whole day');
  assert.equal(middayBreakLabel(rel.daysByDate['2026-10-09'].slots), '11:30 AM – 1:00 PM');

  const sunday = rel.daysByDate['2026-10-11'].wholeDay;
  assert.equal(sunday.available, false);
  assert.equal(sunday.reason, 'full');
  // The afternoon alone is still bookable.
  assert.equal(rel.daysByDate['2026-10-11'].available, true);

  assert.equal(rel.daysByDate['2026-10-08'].wholeDay, null);
});

test('whole-day request params send every session start as one visit', () => {
  const rel = normalizeAvailabilityResponse(WHOLE_DAY_SAMPLE).relationships[0];
  const whole = rel.daysByDate['2026-10-09'].wholeDay;

  assert.deepEqual(toVisitRequestParams({ ...whole, relationshipId: '5' }), {
    relationshipId: '5',
    date: '2026-10-09',
    startTime: '09:00',
    endTime: '16:30',
    startTimes: ['09:00', '13:00'],
  });
});
