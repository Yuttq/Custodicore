/**
 * Visit status → `StatusChip` variant, shared by My Visits, Home, Visit
 * Details and Visit History Detail so one visit reads the same everywhere.
 *
 * Pure functions only (no React / React Native imports) so they can be unit
 * tested with `node --test`.
 */

/** Maps visit status to StatusChip keys for the BJMP visitation workflow. */
const VISIT_STATUS_CHIP = {
  pending_confirmation: 'pending_confirmation',
  assigned: 'assigned',
  scheduled: 'pending_confirmation',
  confirmed: 'confirmed',
  qr_ready: 'qr_ready',
  checked_in: 'checked_in',
  checked_out: 'completed',
  completed: 'completed',
  cancelled: 'cancelled',
  declined: 'declined',
  no_show: 'no_show',
  unable_to_attend: 'declined',
};

/** Chip for unknown or missing statuses (matches StatusChip's own fallback). */
const FALLBACK_CHIP = 'pending';

/**
 * @param {unknown} status raw visit status, e.g. "checked_out" or "Checked-Out"
 * @returns {string} a StatusChip `status` key
 */
export function resolveVisitStatusChip(status) {
  if (typeof status !== 'string') return FALLBACK_CHIP;
  const key = status.trim().toLowerCase().replace(/[-\s]+/g, '_');
  return Object.prototype.hasOwnProperty.call(VISIT_STATUS_CHIP, key)
    ? VISIT_STATUS_CHIP[key]
    : FALLBACK_CHIP;
}
