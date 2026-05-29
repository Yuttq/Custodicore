/**
 * TEMPORARY — development-only static notification payloads.
 * Shape matches what `NotificationsScreen` `normalizeList` / `normalizeNotification` expect.
 * Replace with live `/notifications` responses when the backend exists; keep samples for fixtures/tests if useful.
 */

export const MOCK_NOTIFICATIONS_RAW = [
  {
    id: 'mock-n-visit-approved',
    title: 'Visit Approved',
    body: 'Your visit for June 16, 2026 has been approved. Review the Schedule tab for time and facility details.',
    read: false,
    createdAt: '2026-05-12T09:15:00.000Z',
  },
  {
    id: 'mock-n-visit-pending',
    title: 'Visit Pending',
    body: 'Your visit request is awaiting facility review. You will receive another alert when the status changes.',
    read: false,
    createdAt: '2026-05-11T14:40:00.000Z',
  },
  {
    id: 'mock-n-schedule-updated',
    title: 'Visitor Schedule Updated',
    body: 'A slot on your assigned schedule was adjusted. Open Schedule to confirm the new date and time.',
    read: true,
    createdAt: '2026-05-10T11:05:00.000Z',
  },
  {
    id: 'mock-n-announcement',
    title: 'New Announcement',
    body: 'Facility reminder: valid government ID is required at the gate. Arrive 15 minutes before your window.',
    read: true,
    createdAt: '2026-05-09T08:00:00.000Z',
  },
];
