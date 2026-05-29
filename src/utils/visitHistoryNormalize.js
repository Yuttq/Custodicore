/**
 * Maps API visit history records to list rows (field names vary by backend).
 * @param {Record<string, unknown>} raw
 * @param {number} index
 */
export function normalizeVisitHistoryRecord(raw, index) {
  const id = String(
    raw.id ?? raw.scheduleId ?? raw.visitId ?? `visit-${index}`,
  ).trim();
  const scheduledAt =
    (typeof raw.scheduledAt === 'string' && raw.scheduledAt) ||
    (typeof raw.visitDate === 'string' && raw.visitDate) ||
    (typeof raw.date === 'string' && raw.date) ||
    (typeof raw.visitedAt === 'string' && raw.visitedAt) ||
    null;
  const pdlName =
    (typeof raw.pdlName === 'string' && raw.pdlName) ||
    (typeof raw.pdl?.name === 'string' && raw.pdl.name) ||
    (typeof raw.prisonerName === 'string' && raw.prisonerName) ||
    '—';
  const facility =
    (typeof raw.facility === 'string' && raw.facility) ||
    (typeof raw.facilityName === 'string' && raw.facilityName) ||
    '—';
  const referenceNumber =
    (typeof raw.referenceNumber === 'string' && raw.referenceNumber) ||
    (typeof raw.reference === 'string' && raw.reference) ||
    '—';
  const statusRaw = raw.status ?? raw.visitStatus ?? 'pending';
  const status = String(statusRaw)
    .toLowerCase()
    .replace(/-/g, '_')
    .replace(/\s+/g, '_');
  const cancellationReason =
    (typeof raw.cancellationReason === 'string' && raw.cancellationReason) ||
    (typeof raw.cancelReason === 'string' && raw.cancelReason) ||
    null;

  let dateDisplay = typeof raw.dateDisplay === 'string' ? raw.dateDisplay : '';
  let timeLabel = typeof raw.timeLabel === 'string' ? raw.timeLabel : '';
  if (scheduledAt && !dateDisplay) {
    const d = new Date(scheduledAt);
    if (!Number.isNaN(d.getTime())) {
      dateDisplay = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
  }

  return {
    id,
    scheduledAt,
    dateDisplay: dateDisplay || '—',
    timeLabel: timeLabel || '—',
    pdlName,
    facility,
    referenceNumber,
    status,
    cancellationReason,
  };
}

/**
 * @param {unknown} data
 * @returns {ReturnType<typeof normalizeVisitHistoryRecord>[]}
 */
export function normalizeVisitHistoryResponse(data) {
  if (Array.isArray(data)) {
    return data.map((row, i) => normalizeVisitHistoryRecord(row, i));
  }
  if (data && typeof data === 'object') {
    const o = /** @type {Record<string, unknown>} */ (data);
    if (Array.isArray(o.visits)) return o.visits.map((row, i) => normalizeVisitHistoryRecord(row, i));
    if (Array.isArray(o.data)) return o.data.map((row, i) => normalizeVisitHistoryRecord(row, i));
    if (Array.isArray(o.results)) return o.results.map((row, i) => normalizeVisitHistoryRecord(row, i));
  }
  return [];
}

/** History screen only shows terminal visitation outcomes. */
export function filterVisitationHistoryRecords(records) {
  return records.filter((r) => r.status === 'completed' || r.status === 'cancelled');
}
