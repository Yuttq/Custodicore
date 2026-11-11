/**
 * Help Center contact from GET /api/facility-contact (set by the Admin on
 * the web Settings page). The backend only accepts digits, spaces, + ( )
 * and -, but the app checks again before building a tel: link.
 */

/**
 * The contact to show, or null when there is none (no phone number yet,
 * or an unexpected response).
 * @param {unknown} response
 * @returns {{ name: string | null; phone: string; hours: string | null } | null}
 */
export function normalizeFacilityContact(response) {
  const c = /** @type {any} */ (response)?.contact;
  if (!c || typeof c.phone !== 'string' || !c.phone.trim()) return null;
  const text = (v) => (typeof v === 'string' && v.trim() ? v.trim() : null);
  return { name: text(c.name), phone: c.phone.trim(), hours: text(c.hours) };
}

/**
 * `tel:` link for a phone number: digits and a leading + only. Null when the
 * number has characters the backend would not allow or too few digits.
 * @param {string | null | undefined} phone
 * @returns {string | null}
 */
export function telLink(phone) {
  const raw = String(phone ?? '').trim();
  if (!/^[+(0-9][0-9 ()+-]*$/.test(raw)) return null;
  const digits = raw.replace(/\D/g, '');
  if (digits.length < 7) return null;
  return `tel:${raw.startsWith('+') ? '+' : ''}${digits}`;
}
