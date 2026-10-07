/**
 * Client-side registration field rules (RegisterScreen step 1).
 *
 * Limits mirror the backend schema / AuthController::register() rules so the
 * app never sends something the server would reject on length:
 *   visitor_profiles.full_name      varchar(150)  → first + " " + last ≤ 150
 *   accounts.email                  varchar(100)
 *   visitor_profiles.contact_number varchar(20)
 *   visitor_profiles.address        max:255
 *   password                        min:6 (bcrypt only uses the first 72 bytes)
 *
 * This is a usability layer, NOT a security boundary — the backend must keep
 * validating the same data.
 */

export const REGISTRATION_LIMITS = {
  nameMax: 70,
  ageMin: 1,
  ageMax: 120,
  ageInputMax: 3,
  emailMax: 100,
  emailLocalMax: 64,
  passwordMin: 6,
  passwordMax: 72,
  addressMax: 255,
  /** Allows "+63 912 345 6789" while typing; stored digits-only. */
  mobileInputMax: 16,
  guardianInfoMax: 300,
};

/**
 * C0/C1 control characters plus invisible formatting characters (zero-width
 * space, bidi overrides/isolates, BOM) that have no place in form input.
 */
const CONTROL_CHARS_RE = /[\u0000-\u0008\u000B-\u001F\u007F-\u009F​‎‏‪-‮⁦-⁩﻿]/g;
const LINE_BREAKS_RE = /[\t\n\r]/g;

/**
 * Letters (any script, incl. ñ and accents) separated by single spaces,
 * hyphens, apostrophes or periods — e.g. "Ma. Clara", "dela Cruz",
 * "O'Neil", "Santos-Reyes", "Jr.". Must start with a letter.
 */
const NAME_RE = /^\p{L}\p{M}*(?:[ .'’-]{0,2}\p{L}\p{M}*)*\.?$/u;

/** local@domain.tld — no spaces, no consecutive/edge dots, alphabetic TLD. */
const EMAIL_RE =
  /^[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\.[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+)*@(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+[A-Za-z]{2,}$/;

/** Philippine mobile: 09XXXXXXXXX, 639XXXXXXXXX or +639XXXXXXXXX. */
const PH_MOBILE_DIGITS_RE = /^(?:09|639)\d{9}$/;
const MOBILE_CHARS_RE = /^\+?[\d\s-]+$/;

/** Removes control/invisible characters (single-line inputs also lose line breaks). */
export function stripControlChars(value, { multiline = false } = {}) {
  let s = String(value ?? '').replace(CONTROL_CHARS_RE, '');
  if (!multiline) s = s.replace(LINE_BREAKS_RE, ' ');
  return s;
}

/** onChangeText filters — restrict what can be typed/pasted per field. */
export const inputFilters = {
  name: (v) => stripControlChars(v).slice(0, REGISTRATION_LIMITS.nameMax),
  age: (v) => String(v ?? '').replace(/\D/g, '').slice(0, REGISTRATION_LIMITS.ageInputMax),
  email: (v) => stripControlChars(v).replace(/\s/g, '').slice(0, REGISTRATION_LIMITS.emailMax),
  mobile: (v) => {
    // mobileInputMax caps characters (incl. spaces/dashes); also cap digits so
    // "09…" stops at 11 and "63…"/"+63…" at 12.
    let digits = 0;
    let maxDigits = 11;
    let out = '';
    for (const ch of String(v ?? '').replace(/[^\d+\s-]/g, '')) {
      if (/\d/.test(ch)) {
        if (digits === 0 && ch === '6') maxDigits = 12;
        if (digits >= maxDigits) continue;
        digits += 1;
      }
      out += ch;
    }
    return out.slice(0, REGISTRATION_LIMITS.mobileInputMax);
  },
  address: (v) =>
    stripControlChars(v, { multiline: true }).slice(0, REGISTRATION_LIMITS.addressMax),
  password: (v) => stripControlChars(v).slice(0, REGISTRATION_LIMITS.passwordMax),
  guardianInfo: (v) =>
    stripControlChars(v, { multiline: true }).slice(0, REGISTRATION_LIMITS.guardianInfoMax),
};

/** Trim + collapse internal whitespace runs. Case and punctuation are kept. */
export function normalizeName(value) {
  return stripControlChars(value).trim().replace(/\s+/g, ' ');
}

/** Trim; lowercase only the domain (the local part is left exactly as typed). */
export function normalizeEmail(value) {
  const s = stripControlChars(value).trim();
  const at = s.lastIndexOf('@');
  if (at < 0) return s;
  return s.slice(0, at + 1) + s.slice(at + 1).toLowerCase();
}

/** Any accepted PH format → 09XXXXXXXXX (the format the form has always shown). */
export function normalizeMobile(value) {
  const digits = String(value ?? '').replace(/\D/g, '');
  if (/^639\d{9}$/.test(digits)) return `0${digits.slice(2)}`;
  return digits;
}

export function normalizeAddress(value) {
  return stripControlChars(value, { multiline: true })
    .split('\n')
    .map((line) => line.trim().replace(/[^\S\n]+/g, ' '))
    .filter(Boolean)
    .join('\n');
}

/** Whole years between an ISO YYYY-MM-DD birthdate and today; null if unparsable. */
export function ageFromBirthdate(iso, today = new Date()) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso ?? ''));
  if (!m) return null;
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
  let age = today.getFullYear() - y;
  const beforeBirthday =
    today.getMonth() + 1 < mo || (today.getMonth() + 1 === mo && today.getDate() < d);
  if (beforeBirthday) age -= 1;
  return age;
}

/** @returns {string|null} error message */
function validateName(value, label) {
  const name = normalizeName(value);
  if (!name) return `${label} is required.`;
  if (name.length > REGISTRATION_LIMITS.nameMax) {
    return `${label} must be ${REGISTRATION_LIMITS.nameMax} characters or fewer.`;
  }
  if (!NAME_RE.test(name)) return `Please enter a valid ${label.toLowerCase()}.`;
  return null;
}

export function validateFirstName(value) {
  return validateName(value, 'First name');
}

export function validateLastName(value) {
  return validateName(value, 'Last name');
}

/**
 * @param {string} value digits as typed
 * @param {string} [birthdate] ISO date; when set, the age must agree with it
 */
export function validateAge(value, birthdate) {
  const s = String(value ?? '').trim();
  if (!s) return 'Age is required.';
  if (!/^\d+$/.test(s)) return 'Age must be a whole number.';
  const age = Number(s);
  if (age < REGISTRATION_LIMITS.ageMin || age > REGISTRATION_LIMITS.ageMax) {
    return 'Please enter a valid age.';
  }
  const expected = ageFromBirthdate(birthdate);
  if (expected !== null && expected !== age) {
    return 'Age does not match your birthdate.';
  }
  return null;
}

export function validateRegistrationEmail(value) {
  const email = normalizeEmail(value);
  if (!email) return 'Email address is required.';
  if (email.length > REGISTRATION_LIMITS.emailMax) {
    return `Email address must be ${REGISTRATION_LIMITS.emailMax} characters or fewer.`;
  }
  const local = email.slice(0, email.lastIndexOf('@'));
  if (!EMAIL_RE.test(email) || local.length > REGISTRATION_LIMITS.emailLocalMax) {
    return 'Please enter a valid email address.';
  }
  return null;
}

/** Passwords are never trimmed or altered — only checked. */
export function validateRegistrationPassword(value) {
  const pw = String(value ?? '');
  if (!pw) return 'Password is required.';
  if (pw.length < REGISTRATION_LIMITS.passwordMin) {
    return `Password must be at least ${REGISTRATION_LIMITS.passwordMin} characters.`;
  }
  if (pw.length > REGISTRATION_LIMITS.passwordMax) {
    return `Password must be ${REGISTRATION_LIMITS.passwordMax} characters or fewer.`;
  }
  if (!pw.trim()) return 'Password cannot be only spaces.';
  if (pw !== pw.trim()) return 'Password cannot start or end with a space.';
  if (stripControlChars(pw) !== pw) return 'Password contains characters that are not allowed.';
  return null;
}

export function validatePasswordConfirmation(password, confirmation) {
  if (!String(confirmation ?? '')) return 'Please confirm your password.';
  if (confirmation !== password) return 'Passwords do not match.';
  return null;
}

export function validateMobileNumber(value) {
  const s = String(value ?? '').trim();
  if (!s) return 'Mobile number is required.';
  if (!MOBILE_CHARS_RE.test(s)) return 'Please enter a valid mobile number.';
  if (!PH_MOBILE_DIGITS_RE.test(s.replace(/\D/g, ''))) {
    return 'Please enter a valid mobile number (e.g. 0917 123 4567).';
  }
  return null;
}

export function validateAddress(value) {
  const address = normalizeAddress(value);
  if (!address) return 'Address is required.';
  if (address.length > REGISTRATION_LIMITS.addressMax) {
    return `Address must be ${REGISTRATION_LIMITS.addressMax} characters or fewer.`;
  }
  return null;
}
