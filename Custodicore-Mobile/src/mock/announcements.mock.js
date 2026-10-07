/**
 * TEMPORARY — development-only announcements. Shape matches
 * GET /api/announcements (backend config/visitation.php).
 * Used only when `USE_MOCK_ANNOUNCEMENTS` is true.
 */
export const MOCK_ANNOUNCEMENTS = [
  {
    id: 'visiting-days',
    title: 'Visiting Days',
    body: 'Visits for drug-related PDLs are on Thursdays and Saturdays. Visits for non-drug-related PDLs are on Fridays and Sundays.',
    category: 'schedule',
    pinned: true,
    createdAt: '2026-10-07T00:00:00+08:00',
  },
  {
    id: 'visiting-hours',
    title: 'Visiting Hours',
    body: 'Morning visits are from 9:00 AM to 11:30 AM. Afternoon visits are from 1:00 PM to 4:30 PM.',
    category: 'schedule',
    pinned: true,
    createdAt: '2026-10-07T00:00:00+08:00',
  },
  {
    id: 'visiting-areas',
    title: 'Visiting Areas',
    body: 'Visits are held at the Main Building. Visitors of PDLs housed in the secondary (DOB) area will be directed by the Front Desk officer on arrival.',
    category: 'facility',
    pinned: false,
    createdAt: '2026-10-07T00:00:00+08:00',
  },
];
