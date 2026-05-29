const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
/** Digits, spaces, +, -, parentheses only (common phone formats). */
const PHONE_CHARS_RE = /^[\d\s+()\-]+$/;

/**
 * @param {string} s
 */
function isValidEmail(s) {
  return EMAIL_RE.test(String(s).trim());
}

/**
 * @param {string} s
 */
function phoneDigitsOk(s) {
  const digits = String(s).replace(/\D/g, '');
  return digits.length >= 10 && digits.length <= 15;
}

/**
 * @param {{ fullName?: string; email?: string; phone?: string }} fields
 * @returns {{ valid: boolean; errors: Record<string, string> }}
 */
export function validateProfileFields(fields) {
  const errors = /** @type {Record<string, string>} */ ({});

  const fullName = String(fields.fullName ?? '').trim();
  if (!fullName) {
    errors.fullName = 'Name cannot be empty';
  }

  const email = String(fields.email ?? '').trim();
  if (!email) {
    errors.email = 'Email is required';
  } else if (!isValidEmail(email)) {
    errors.email = 'Enter a valid email address';
  }

  const phone = String(fields.phone ?? '').trim();
  if (!phone) {
    errors.phone = 'Contact number is required';
  } else if (!PHONE_CHARS_RE.test(phone)) {
    errors.phone = 'Use only digits, spaces, +, -, and parentheses';
  } else if (!phoneDigitsOk(phone)) {
    errors.phone = 'Enter a valid number (10–15 digits)';
  }

  return { valid: Object.keys(errors).length === 0, errors };
}
