// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  addDaysIso,
  buildMonthGrid,
  calendarDateHandoff,
  combineAvailableDays,
  dayOfWeekIso,
  formatDateLong,
  formatSlotRange,
  formatTime24,
  formatVisitingDays,
  firstBookableDate,
  formatWeekRange,
  isWeeklyLimitDay,
  isWeeklyLimitError,
  weeklyLimitReachedNotice,
  manilaTodayIso,
  middayBreakLabel,
  normalizeAvailabilityResponse,
  parseIsoDate,
  periodLabel,
  relationshipsAvailableOn,
  shiftMonth,
  slotReasonLabel,
  toVisitRequestParams,
  visitingWindows,
  WEEKDAY_SHORT,
  weekStartIso,
  weeklyLimitNote,
  weeklyLimitReachedMessage,
  weeklyUsageFor,
  weeklyUsageLabel,
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

test('month grid is Monday-first with null padding', () => {
  assert.deepEqual(WEEKDAY_SHORT, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']);
  const weeks = buildMonthGrid(2026, 10); // Oct 1 2026 is a Thursday
  assert.ok(weeks.every((w) => w.length === 7));
  assert.deepEqual(weeks[0].slice(0, 4), [null, null, null, '2026-10-01']);
  assert.equal(weeks[0][6], '2026-10-04'); // first row ends on Sunday
  assert.equal(weeks[1][0], '2026-10-05'); // next row starts on Monday
  assert.equal(weeks.flat().filter(Boolean).length, 31);
  assert.equal(weeks.flat().filter(Boolean).at(-1), '2026-10-31');
});

test('every grid row is one Monday–Sunday week', () => {
  for (const [year, month] of [[2026, 11], [2026, 2], [2027, 3], [2026, 6]]) {
    for (const week of buildMonthGrid(year, month)) {
      week.forEach((date, i) => {
        if (date) assert.equal((dayOfWeekIso(date) + 6) % 7, i, `${date} in column ${i}`);
      });
    }
  }
  // A month starting on Monday has no padding; one starting on Sunday has six.
  assert.equal(buildMonthGrid(2026, 6)[0][0], '2026-06-01'); // Monday
  assert.equal(buildMonthGrid(2026, 11)[0][6], '2026-11-01'); // Sunday
  assert.deepEqual(buildMonthGrid(2026, 11)[0].slice(0, 6), [null, null, null, null, null, null]);
});

test('week start is the Monday; Sunday belongs to the week before Monday', () => {
  assert.equal(weekStartIso('2026-11-16'), '2026-11-16'); // Monday
  assert.equal(weekStartIso('2026-11-19'), '2026-11-16'); // Thursday
  assert.equal(weekStartIso('2026-11-22'), '2026-11-16'); // Sunday ends that week
  assert.equal(weekStartIso('2026-11-15'), '2026-11-09'); // Sunday before is the previous week
  assert.equal(weekStartIso('2027-01-03'), '2026-12-28'); // across a year
  assert.equal(weekStartIso('not-a-date'), null);
});

test('week ranges read Monday–Sunday', () => {
  assert.equal(formatWeekRange('2026-11-16'), 'November 16–22');
  assert.equal(formatWeekRange('2026-11-30'), 'November 30 – December 6');
  assert.equal(formatWeekRange('2026-12-28'), 'December 28, 2026 – January 3, 2027');
});

const WEEKLY = {
  limit: 2,
  weeks: [
    { weekStart: '2026-11-09', weekEnd: '2026-11-15', used: 1, remaining: 1 },
    { weekStart: '2026-11-16', weekEnd: '2026-11-22', used: 2, remaining: 0 },
    { weekStart: '2026-11-23', weekEnd: '2026-11-29', used: 0, remaining: 2 },
  ],
};

test('weekly usage comes from the response and is looked up by Monday–Sunday week', () => {
  const { weeklyLimit } = normalizeAvailabilityResponse({ relationships: [], weeklyLimit: WEEKLY });
  assert.deepEqual(weeklyLimit, {
    limit: 2,
    weeks: { '2026-11-09': 1, '2026-11-16': 2, '2026-11-23': 0 },
  });

  const sunday = weeklyUsageFor(weeklyLimit, '2026-11-15');
  assert.equal(sunday.weekStart, '2026-11-09');
  assert.equal(weeklyUsageLabel(sunday), '1 of 2 weekly slots used');
  assert.equal(sunday.reached, false);

  const thursday = weeklyUsageFor(weeklyLimit, '2026-11-19');
  assert.deepEqual(thursday, { weekStart: '2026-11-16', weekEnd: '2026-11-22', used: 2, limit: 2, reached: true });
  assert.equal(weeklyUsageLabel(thursday), '2 of 2 weekly slots used');

  assert.equal(weeklyUsageLabel(weeklyUsageFor(weeklyLimit, '2026-11-26')), '0 of 2 weekly slots used');

  // A week the backend did not report is unknown, not 0.
  assert.equal(weeklyUsageFor(weeklyLimit, '2026-12-03'), null);
  assert.equal(weeklyUsageFor(null, '2026-11-19'), null);
  assert.equal(normalizeAvailabilityResponse({ relationships: [] }).weeklyLimit, null);
});

test('the limit message names the full week and that later dates can be booked now', () => {
  const usage = weeklyUsageFor(normalizeAvailabilityResponse({ weeklyLimit: WEEKLY }).weeklyLimit, '2026-11-20');
  const message = weeklyLimitReachedMessage(usage);
  assert.equal(
    message,
    "That's your limit for November 16–22 across all your PDLs. You can still book dates from Monday, November 23 onward.",
  );
  // The limit follows the visit date's week, not the day of booking.
  assert.doesNotMatch(message, /book again on/);
});

test('time and day formatting', () => {
  assert.equal(formatTime24('09:00'), '09:00');
  assert.equal(formatTime24('9:05'), '09:05');
  assert.equal(formatTime24('13:00'), '13:00');
  assert.equal(formatTime24('12:30'), '12:30');
  assert.equal(formatSlotRange('13:00', '16:30'), '13:00 – 16:30');
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
  assert.equal(slotReasonLabel('not_published'), 'Not open for visits');
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
  assert.equal(middayBreakLabel(rel.daysByDate['2026-10-09'].slots), '11:30 – 13:00');

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

// Date-first Home calendar: availability combined across PDLs.
function relationshipWith(relationshipId, pdlName, availableByDate) {
  return normalizeAvailabilityResponse({
    relationships: [
      {
        relationshipId,
        pdlName,
        days: Object.entries(availableByDate).map(([date, available]) => ({
          date,
          slots: [{ startTime: '09:00', endTime: '11:00', available, reason: available ? null : 'full' }],
        })),
      },
    ],
  }).relationships[0];
}

test('combined calendar: a date is available when any PDL has an available session', () => {
  const ana = relationshipWith('1', 'Ana', { '2026-11-10': true, '2026-11-11': false, '2026-11-12': false });
  const ben = relationshipWith('2', 'Ben', { '2026-11-11': true, '2026-11-12': false, '2026-11-13': true });

  assert.deepEqual(combineAvailableDays([ana, ben]), {
    '2026-11-10': { available: true },
    '2026-11-11': { available: true }, // Ana closed, Ben open
    '2026-11-12': { available: false }, // closed for both
    '2026-11-13': { available: true }, // only Ben lists it
  });
  // One PDL: the same days as that PDL's own calendar.
  assert.deepEqual(combineAvailableDays([ana]), {
    '2026-11-10': { available: true },
    '2026-11-11': { available: false },
    '2026-11-12': { available: false },
  });
  // No verified PDL / bad input: nothing is available.
  assert.deepEqual(combineAvailableDays([]), {});
  assert.deepEqual(combineAvailableDays(null), {});
});

test('PDLs available on a date: only those with an available session, in order', () => {
  const ana = relationshipWith('1', 'Ana', { '2026-11-10': true, '2026-11-11': true });
  const ben = relationshipWith('2', 'Ben', { '2026-11-10': false, '2026-11-11': true });
  const cy = relationshipWith('3', 'Cy', { '2026-11-11': true });

  assert.deepEqual(relationshipsAvailableOn([ana, ben, cy], '2026-11-11').map((r) => r.pdlName), ['Ana', 'Ben', 'Cy']);
  // Ben is closed and Cy has no day: only Ana, so Home skips the PDL choice.
  assert.deepEqual(relationshipsAvailableOn([ana, ben, cy], '2026-11-10').map((r) => r.relationshipId), ['1']);
  assert.deepEqual(relationshipsAvailableOn([ana, ben, cy], '2026-11-12'), []);
  assert.deepEqual(relationshipsAvailableOn([ana], 'not-a-date'), []);
  assert.deepEqual(relationshipsAvailableOn(null, '2026-11-10'), []);
});

test('Home calendar: each PDL shows only its own dates, and only they open Schedule', () => {
  const slot = (date, available) => ({ startTime: '09:00', endTime: '11:30', available, reason: available ? null : 'full' });
  const { relationships } = normalizeAvailabilityResponse({
    today: '2026-11-05',
    relationships: [
      {
        relationshipId: 7,
        pdlName: 'Dogie Dawg',
        days: [
          { date: '2026-11-06', slots: [slot('2026-11-06', true)] },
          { date: '2026-11-07', slots: [slot('2026-11-07', false)] },
        ],
      },
      {
        relationshipId: 9,
        pdlName: 'Other PDL',
        days: [
          { date: '2026-11-06', slots: [slot('2026-11-06', false)] },
          { date: '2026-11-07', slots: [slot('2026-11-07', true)] },
        ],
      },
    ],
  });
  const [dogie, other] = relationships;

  // Switching PDL switches the dates: no date leaks from the other PDL.
  assert.equal(dogie.daysByDate['2026-11-06'].available, true);
  assert.equal(dogie.daysByDate['2026-11-07'].available, false);
  assert.equal(other.daysByDate['2026-11-06'].available, false);
  assert.equal(other.daysByDate['2026-11-07'].available, true);

  assert.deepEqual(calendarDateHandoff(dogie, '2026-11-06'), {
    tab: 'schedule',
    date: '2026-11-06',
    relationshipId: '7',
  });
  assert.deepEqual(calendarDateHandoff(other, '2026-11-07'), {
    tab: 'schedule',
    date: '2026-11-07',
    relationshipId: '9',
  });
  // Unavailable for the selected PDL (even if another PDL is free that day).
  assert.equal(calendarDateHandoff(dogie, '2026-11-07'), null);
  assert.equal(calendarDateHandoff(other, '2026-11-06'), null);
  // Dates outside the data, bad input, no relationship.
  assert.equal(calendarDateHandoff(dogie, '2026-11-20'), null);
  assert.equal(calendarDateHandoff(dogie, 'not-a-date'), null);
  assert.equal(calendarDateHandoff(dogie, null), null);
  assert.equal(calendarDateHandoff(null, '2026-11-06'), null);
  assert.equal(calendarDateHandoff({ daysByDate: dogie.daysByDate }, '2026-11-06'), null);
});

test('weekly slot wording: singular, and what counts', async () => {
  const { WEEKLY_SLOTS_COUNTED_NOTE } = await import('./scheduleAvailability.js');
  assert.equal(weeklyUsageLabel({ used: 1, limit: 1 }), '1 of 1 weekly slot used');
  assert.equal(
    WEEKLY_SLOTS_COUNTED_NOTE,
    'Pending requests count toward the limit. Rejected, declined and cancelled ones don’t.',
  );
});

test('weeklyLimitReachedNotice names the visit date’s week, not "this week"', () => {
  assert.equal(
    weeklyLimitReachedNotice(2, '2026-11-14'),
    "You've already used your 2 allowed visits for the week of November 9–15 (Monday–Sunday), " +
      'across all your PDLs. Choose a date in another week.',
  );
  // A future week is named as such.
  assert.match(weeklyLimitReachedNotice(2, '2026-11-28'), /week of November 23–29/);
  assert.doesNotMatch(weeklyLimitReachedNotice(2, '2026-11-14'), /this week|next weekly period/);
});

test('weeklyLimitReachedNotice uses the backend limit, falling back to 2', () => {
  assert.match(weeklyLimitReachedNotice(null), /your 2 allowed visits for that week/);
  assert.match(weeklyLimitReachedNotice(1), /your 1 allowed visit for/);
  // 0 allows no visits at all: never "your 2 allowed visits".
  assert.equal(
    weeklyLimitReachedNotice(0, '2026-11-14'),
    'The facility is not accepting visit requests right now. Please check again later.',
  );
});

test('weeklyLimitNote: visits per week, all PDLs, pending counts, no Mon–Sun', () => {
  assert.equal(
    weeklyLimitNote(2),
    'Up to 2 visits per week, across all your PDLs. Pending requests count too.',
  );
  assert.match(weeklyLimitNote(1), /^Up to 1 visit per week,/);
  assert.match(weeklyLimitNote(3), /^Up to 3 visits per week,/);
  assert.doesNotMatch(weeklyLimitNote(2), /Mon|Sun/);
});

test('firstBookableDate is the earliest available date', () => {
  assert.equal(
    firstBookableDate({
      '2026-11-21': { available: true },
      '2026-11-14': { available: false },
      '2026-11-19': { available: true },
    }),
    '2026-11-19',
  );
  assert.equal(firstBookableDate({ '2026-11-14': { available: false } }), null);
  assert.equal(firstBookableDate(null), null);
});

test('isWeeklyLimitDay is true only for unavailable days blocked by weekly_limit', () => {
  assert.equal(isWeeklyLimitDay({ available: false, slots: [{ reason: 'weekly_limit' }] }), true);
  assert.equal(isWeeklyLimitDay({ available: false, slots: [{ reason: 'full' }] }), false);
  assert.equal(isWeeklyLimitDay({ available: true, slots: [{ reason: 'weekly_limit' }] }), false);
  assert.equal(isWeeklyLimitDay(null), false);
});

test('isWeeklyLimitError matches the backend weekly-limit rejection', () => {
  // By code (current backend), whatever the text.
  const coded = Object.assign(new Error('Anything'), { code: 'weekly_limit' });
  assert.equal(isWeeklyLimitError(coded), true);
  // By text: the new wording, and the old one from a backend not yet updated.
  assert.equal(
    isWeeklyLimitError(
      new Error('You have reached the maximum number of visits allowed for the week (Monday–Sunday) of the selected date.'),
    ),
    true,
  );
  assert.equal(
    isWeeklyLimitError(new Error('You have reached the maximum number of visits allowed for this calendar week.')),
    true,
  );
  assert.equal(isWeeklyLimitError(Object.assign(new Error('Full'), { code: 'service_unavailable' })), false);
  assert.equal(isWeeklyLimitError(new Error('This visiting slot is full.')), false);
  assert.equal(isWeeklyLimitError(null), false);
});
