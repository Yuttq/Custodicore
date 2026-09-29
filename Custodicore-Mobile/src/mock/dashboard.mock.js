/**
 * Mock dashboard data — BJMP assigns visits; visitors do not book.
 * Replace with API (GET /visitor/me, GET /visitor/visits/next) in a later phase.
 */

export const MOCK_VERIFICATION_ITEMS = [
  { id: 'identity', label: 'Identity Verified' },
  { id: 'relationship', label: 'Relationship Verified' },
  { id: 'eligible', label: 'Eligible For Visitation' },
];

/** Next officer-assigned visit (mockup reference). */
export const MOCK_NEXT_ASSIGNED_VISIT = {
  id: 'visit-001',
  scheduledAt: '2026-06-16T14:00:00',
  endAt: '2026-06-16T16:30:00',
  dateDisplay: 'Jun 16',
  timeLabel: '2:00 PM – 4:30 PM',
  pdlName: 'Juan D. Rivera',
  location: 'BJMP Facility — Visiting Area',
  facility: 'BJMP Facility — Visiting Area',
  referenceNumber: 'VIS-2026-0616-001',
  visitType: 'Standard Visitation',
  status: 'confirmed',
};
