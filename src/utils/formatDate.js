import moment from 'moment';

/**
 * @param {string} dateString - ISO or parseable date string
 * @returns {string} e.g. "May 20, 2026"
 */
export function formatDate(dateString) {
  if (!dateString) return '';
  const m = moment(dateString);
  return m.isValid() ? m.format('MMMM D, YYYY') : '';
}

/** Registration / profile birthdate display — e.g. Jan 26, 2005 */
export function formatBirthdateDisplay(isoDate) {
  if (!isoDate) return '';
  const m = moment(isoDate, 'YYYY-MM-DD', true);
  return m.isValid() ? m.format('MMM D, YYYY') : '';
}

/**
 * @param {string} isoDate YYYY-MM-DD
 * @returns {Date | null}
 */
export function parseIsoDateString(isoDate) {
  if (!isoDate) return null;
  const m = moment(isoDate, 'YYYY-MM-DD', true);
  return m.isValid() ? m.toDate() : null;
}

/**
 * @param {Date} date
 * @returns {string} YYYY-MM-DD
 */
export function toIsoDateString(date) {
  return moment(date).format('YYYY-MM-DD');
}

export default formatDate;
