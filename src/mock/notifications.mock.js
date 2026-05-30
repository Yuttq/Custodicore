/**
 * TEMPORARY — development-only static notification payloads.
 * Shape matches what `NotificationsScreen` `normalizeList` / `normalizeNotification` expect.
 * Replace with live `/notifications` responses when the backend exists; keep samples for fixtures/tests if useful.
 */

/**
 * @param {number} daysAgo
 * @param {number} [hours=10]
 */
function isoDaysAgo(daysAgo, hours = 10) {
  const date = new Date();
  date.setDate(date.getDate() - daysAgo);
  date.setHours(hours, 30, 0, 0);
  return date.toISOString();
}

export const MOCK_NOTIFICATIONS_RAW = [
  {
    id: 'mock-n-visit-confirmed-today',
    title: 'Visit Confirmed',
    body: 'Your attendance for today’s visit window has been recorded. Arrive 15 minutes early with valid ID.',
    category: 'visits',
    read: false,
    createdAt: isoDaysAgo(0, 8),
  },
  {
    id: 'mock-n-visit-approved-today',
    title: 'Visit Approved',
    body: 'Your visit request for next week has been approved. Review the Schedule tab for time and facility details.',
    category: 'visits',
    read: false,
    createdAt: isoDaysAgo(0, 13),
  },
  {
    id: 'mock-n-schedule-updated-yesterday',
    title: 'Visitor Schedule Updated',
    body: 'A slot on your assigned schedule was adjusted. Open Schedule to confirm the new date and time.',
    category: 'updates',
    read: false,
    createdAt: isoDaysAgo(1, 11),
  },
  {
    id: 'mock-n-system-maintenance-yesterday',
    title: 'Scheduled System Maintenance',
    body: 'CustodiCore will undergo brief maintenance tonight from 11:00 PM to 1:00 AM. Visit QR passes remain valid offline.',
    category: 'system',
    read: true,
    createdAt: isoDaysAgo(1, 17),
  },
  {
    id: 'mock-n-visit-pending-week',
    title: 'Visit Pending Review',
    body: 'Your visit request is awaiting facility review. You will receive another alert when the status changes.',
    category: 'visits',
    read: true,
    createdAt: isoDaysAgo(3, 14),
  },
  {
    id: 'mock-n-announcement-week',
    title: 'New Facility Announcement',
    body: 'Updated visiting hours take effect Monday. Check facility rules before your next assigned visit.',
    category: 'updates',
    read: true,
    createdAt: isoDaysAgo(4, 9),
  },
  {
    id: 'mock-n-profile-security-earlier',
    title: 'Security Reminder',
    body: 'Keep your account secure. Contact facility staff if you notice unfamiliar activity on your visitor profile.',
    category: 'system',
    read: true,
    createdAt: isoDaysAgo(12, 10),
  },
  {
    id: 'mock-n-visit-cancelled-earlier',
    title: 'Visit Cancelled',
    body: 'A previously assigned visit was cancelled by facility administration. See Visitation History for details.',
    category: 'visits',
    read: true,
    createdAt: isoDaysAgo(18, 15),
  },
];
