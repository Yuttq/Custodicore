import moment from 'moment';

/**
 * @param {string} timeString - time-only or datetime string
 * @returns {string} e.g. "9:00 AM"
 */
export function formatTime(timeString) {
  if (!timeString) return '';
  const m = moment(timeString, [moment.ISO_8601, 'HH:mm', 'HH:mm:ss'], true);
  const parsed = m.isValid() ? m : moment(timeString);
  return parsed.isValid() ? parsed.format('h:mm A') : '';
}

export default formatTime;
