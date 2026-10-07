/**
 * TEMPORARY — development-only availability payload for the Home calendar.
 * Shape matches GET /api/schedules/availability (see ScheduleAvailabilityService
 * on the backend). Used only when `USE_MOCK_SCHEDULE` is true.
 */
import { addDaysIso, dayOfWeekIso, manilaTodayIso } from '../utils/scheduleAvailability';

const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
const WINDOWS = [
  ['09:00', '11:30'],
  ['13:00', '16:30'],
];

/**
 * One drug-related relationship (Thu/Sat), 30 visitors per slot; a couple of
 * slots are full / closed so every UI state is visible.
 * @param {{ from?: string; to?: string }} [range]
 */
export function buildMockScheduleAvailability(range = {}) {
  const today = manilaTodayIso();
  const from = range.from ?? today;
  const to = range.to ?? addDaysIso(from, 30);
  const relationshipId = 'mock-rel-1';

  const days = [];
  let eligibleCount = 0;
  for (let date = from; date <= to; date = addDaysIso(date, 1)) {
    const dayKey = DAY_KEYS[dayOfWeekIso(date)];
    const eligible = dayKey === 'thu' || dayKey === 'sat';
    if (eligible && date >= today) eligibleCount += 1;

    const slots = WINDOWS.map(([startTime, endTime], i) => {
      let reason = null;
      if (date < today) reason = 'past';
      else if (!eligible) reason = 'not_eligible';
      else if (eligibleCount === 2 && i === 0) reason = 'full';
      else if (eligibleCount === 3) reason = 'closed';

      return {
        slotKey: `${date}|${startTime}`,
        date,
        period: i === 0 ? 'morning' : 'afternoon',
        startTime,
        endTime,
        available: reason === null,
        reason,
        capacity: eligible ? 30 : 0,
        slotsRemaining: reason === null ? 30 - ((eligibleCount * 7) % 25) : 0,
        relationshipId,
      };
    });

    days.push({ date, dayOfWeek: dayKey, available: slots.some((s) => s.available), slots });
  }

  return {
    timezone: 'Asia/Manila',
    today,
    from,
    to,
    message: null,
    relationships: [
      {
        relationshipId,
        pdlId: 'mock-pdl-1',
        pdlName: 'Juan Dela Cruz',
        relationshipLabel: 'Immediate Family',
        classification: 'drug_related',
        classificationLabel: 'Drug-related',
        visitingDays: ['thu', 'sat'],
        pdlAvailable: true,
        message: null,
        days,
      },
    ],
  };
}
