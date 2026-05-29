/**
 * Officer-assigned visits (mock). Visitors do not create schedules.
 * Replace with GET /visitor/visits?tab=… when API is available.
 */
export const MOCK_ASSIGNED_VISITS = [
  {
    id: 'visit-001',
    scheduledAt: '2026-06-16T14:00:00',
    endAt: '2026-06-16T16:30:00',
    dateDisplay: 'Jun 16',
    timeLabel: '2:00 PM – 4:30 PM',
    pdlName: 'Juan D. Rivera',
    facility: 'BJMP Facility — Visiting Area',
    referenceNumber: 'VIS-2026-0616-001',
    visitType: 'Standard Visitation',
    status: 'confirmed',
  },
  {
    id: 'visit-002',
    scheduledAt: '2026-06-22T09:00:00',
    endAt: '2026-06-22T11:00:00',
    dateDisplay: 'Jun 22',
    timeLabel: '9:00 AM – 11:00 AM',
    pdlName: 'Reyes, Juan D.',
    facility: 'BJMP Facility — Visiting Area',
    referenceNumber: 'VIS-2026-0622-002',
    visitType: 'Standard Visitation',
    status: 'pending_confirmation',
  },
  {
    id: 'visit-003',
    scheduledAt: '2026-07-03T13:30:00',
    endAt: '2026-07-03T15:30:00',
    dateDisplay: 'Jul 3',
    timeLabel: '1:30 PM – 3:30 PM',
    pdlName: 'Santos, Maria L.',
    facility: 'BJMP Facility — Block B',
    referenceNumber: 'VIS-2026-0703-003',
    visitType: 'Special Visitation',
    status: 'scheduled',
  },
  {
    id: 'visit-004',
    scheduledAt: '2026-05-10T10:00:00',
    endAt: '2026-05-10T12:00:00',
    dateDisplay: 'May 10',
    timeLabel: '10:00 AM – 12:00 PM',
    pdlName: 'Cruz, Roberto M.',
    facility: 'BJMP Facility — Visiting Area',
    referenceNumber: 'VIS-2026-0510-004',
    visitType: 'Standard Visitation',
    status: 'completed',
  },
  {
    id: 'visit-005',
    scheduledAt: '2026-04-28T14:00:00',
    endAt: '2026-04-28T16:00:00',
    dateDisplay: 'Apr 28',
    timeLabel: '2:00 PM – 4:00 PM',
    pdlName: 'Dela Rosa, Ana K.',
    facility: 'BJMP Facility — Visiting Area',
    referenceNumber: 'VIS-2026-0428-005',
    visitType: 'Standard Visitation',
    status: 'completed',
  },
  {
    id: 'visit-006',
    scheduledAt: '2026-05-02T08:30:00',
    endAt: '2026-05-02T10:30:00',
    dateDisplay: 'May 2',
    timeLabel: '8:30 AM – 10:30 AM',
    pdlName: 'Garcia, Pedro S.',
    facility: 'BJMP Facility — Block A',
    referenceNumber: 'VIS-2026-0502-006',
    visitType: 'Standard Visitation',
    status: 'cancelled',
    cancellationReason: 'Visitor unable to attend — schedule conflict',
  },
  {
    id: 'visit-007',
    scheduledAt: '2026-03-18T09:00:00',
    endAt: '2026-03-18T11:00:00',
    dateDisplay: 'Mar 18',
    timeLabel: '9:00 AM – 11:00 AM',
    pdlName: 'Juan D. Rivera',
    facility: 'BJMP Facility — Visiting Area',
    referenceNumber: 'VIS-2026-0318-007',
    visitType: 'Standard Visitation',
    status: 'completed',
  },
  {
    id: 'visit-008',
    scheduledAt: '2026-02-14T13:00:00',
    endAt: '2026-02-14T15:00:00',
    dateDisplay: 'Feb 14',
    timeLabel: '1:00 PM – 3:00 PM',
    pdlName: 'Reyes, Juan D.',
    facility: 'BJMP Facility — Visiting Area',
    referenceNumber: 'VIS-2026-0214-008',
    visitType: 'Standard Visitation',
    status: 'completed',
  },
  {
    id: 'visit-009',
    scheduledAt: '2026-01-22T10:30:00',
    endAt: '2026-01-22T12:30:00',
    dateDisplay: 'Jan 22',
    timeLabel: '10:30 AM – 12:30 PM',
    pdlName: 'Santos, Maria L.',
    facility: 'BJMP Facility — Block B',
    referenceNumber: 'VIS-2026-0122-009',
    visitType: 'Special Visitation',
    status: 'completed',
  },
  {
    id: 'visit-010',
    scheduledAt: '2026-04-05T08:00:00',
    endAt: '2026-04-05T10:00:00',
    dateDisplay: 'Apr 5',
    timeLabel: '8:00 AM – 10:00 AM',
    pdlName: 'Cruz, Roberto M.',
    facility: 'BJMP Facility — Visiting Area',
    referenceNumber: 'VIS-2026-0405-010',
    visitType: 'Standard Visitation',
    status: 'cancelled',
    cancellationReason: 'Facility security review — visit rescheduled by officer',
  },
];

export const FACILITY_RULES = [
  'Bring valid government-issued ID',
  'Arrive at least 30 minutes early',
  'No prohibited items',
  'Follow officer instructions',
];

export const UNABLE_TO_ATTEND_REASONS = [
  'Personal Conflict',
  'Medical Reason',
  'Transportation Issue',
  'Family Emergency',
  'Other',
];

/** @param {string} status */
export function getVisitListTab(status) {
  if (status === 'completed') return 'completed';
  if (status === 'cancelled' || status === 'unable_to_attend') return 'cancelled';
  return 'assigned';
}

export function canRespondToVisit(status) {
  return status === 'pending_confirmation' || status === 'scheduled';
}
