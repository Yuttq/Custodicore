/**
 * The required "name of the PDL you want to visit" from registration step 2
 * (typed in parts, joined below; the PDL's date of birth is at the bottom).
 * Sent as `intendedPdlName` and stored on the visitor profile as an
 * unverified hint for the Record Officer, who links the PDL by hand. The app
 * never looks up PDLs. Pure (no React Native imports) for node --test.
 *
 * Rules mirror AuthController::profileRegistrationRules (the server still
 * validates): required, 2–150 characters, letters/spaces/. ' -
 */

import { normalizeName, stripControlChars } from './registrationValidation.js';

export const INTENDED_PDL_NAME_LIMITS = { min: 2, max: 150 };

export const INTENDED_PDL_NAME_PLACEHOLDER = 'Full name of the PDL';

export const INTENDED_PDL_NAME_REQUIRED_MESSAGE = 'Please enter the name of the PDL you want to visit.';

const NAME_RE = /^\p{L}[\p{L}\p{M} .'’-]*$/u;

/** onChangeText filter. */
export function filterIntendedPdlName(value) {
  return stripControlChars(value).slice(0, INTENDED_PDL_NAME_LIMITS.max);
}

/**
 * Error for the field, or null. Empty or spaces only is an error (the field is required).
 * @param {string | null | undefined} value
 */
export function getIntendedPdlNameError(value) {
  const n = normalizeName(value ?? '');
  if (!n) return INTENDED_PDL_NAME_REQUIRED_MESSAGE;
  if (n.length < INTENDED_PDL_NAME_LIMITS.min) return 'Enter the full name of the PDL you want to visit.';
  if (n.length > INTENDED_PDL_NAME_LIMITS.max) {
    return `The PDL name must be ${INTENDED_PDL_NAME_LIMITS.max} characters or fewer.`;
  }
  if (!NAME_RE.test(n)) {
    return 'The PDL name can only contain letters, spaces, periods, apostrophes and hyphens.';
  }
  return null;
}

/** Registration body field: `{ intendedPdlName }`, or {} when left empty. */
export function toIntendedPdlNameField(value) {
  const n = normalizeName(value ?? '');
  return n ? { intendedPdlName: n } : {};
}

// --- Separate name parts (post-defense revision) ---------------------------
//
// The visitor types the PDL's name in parts; the app joins them into the one
// `intendedPdlName` / `pdlName` string the backend already takes, in the same
// order as Pdl::composeFullName(): First Middle Last Suffix.

/** Same values (and spelling) as the backend's Sanitizer::SUFFIXES. */
export const PDL_NAME_SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

/** 50 + 40 + 50 + suffix + 3 spaces stays under the backend's 150. */
export const PDL_NAME_PART_LIMITS = { first: 50, middle: 40, last: 50 };

/**
 * @typedef {object} PdlNameParts
 * @property {string} first
 * @property {string} middle
 * @property {string} last
 * @property {string} suffix — '' or one of PDL_NAME_SUFFIXES
 * @property {boolean} noMiddleName — "No middle name / Not sure" ticked
 */

/** @returns {PdlNameParts} */
export function emptyPdlNameParts() {
  return { first: '', middle: '', last: '', suffix: '', noMiddleName: false };
}

/**
 * onChangeText filter for one part. No length cap here: the input's own
 * maxLength stops typing, and a longer prefilled value is shown (and flagged)
 * rather than cut.
 */
export function filterPdlNamePart(value) {
  return stripControlChars(value);
}

/** "jr", "JR.", "Jr." → "Jr."; "iii" → "III"; anything else → null. */
export function canonicalPdlSuffix(value) {
  const key = String(value ?? '').trim().toLowerCase().replace(/\.$/, '');
  if (!key) return null;
  return PDL_NAME_SUFFIXES.find((s) => s.toLowerCase().replace(/\.$/, '') === key) ?? null;
}

function partError(value, limit, emptyMessage) {
  const n = normalizeName(value ?? '');
  if (!n) return emptyMessage;
  if (n.length > limit) return `Use ${limit} characters or fewer.`;
  if (!NAME_RE.test(n)) return 'Use letters, spaces, periods, apostrophes and hyphens only.';
  return null;
}

/**
 * Errors for the name parts, keyed pdlFirstName / pdlMiddleName /
 * pdlLastName / pdlSuffix; {} when valid. The middle name is required unless
 * "No middle name / Not sure" is ticked (then it is ignored).
 * @param {Partial<PdlNameParts> | null | undefined} parts
 * @returns {Record<string, string>}
 */
export function getPdlNamePartsErrors(parts) {
  const p = { ...emptyPdlNameParts(), ...(parts ?? {}) };
  /** @type {Record<string, string>} */
  const errors = {};
  const put = (key, message) => {
    if (message) errors[key] = message;
  };
  put('pdlFirstName', partError(p.first, PDL_NAME_PART_LIMITS.first, "Enter the PDL's first name."));
  if (!p.noMiddleName) {
    put(
      'pdlMiddleName',
      partError(
        p.middle,
        PDL_NAME_PART_LIMITS.middle,
        "Enter the PDL's middle name, or tick \"No middle name / Not sure\".",
      ),
    );
  }
  put('pdlLastName', partError(p.last, PDL_NAME_PART_LIMITS.last, "Enter the PDL's last name."));
  if (String(p.suffix ?? '').trim() && !PDL_NAME_SUFFIXES.includes(p.suffix)) {
    errors.pdlSuffix = 'Choose a suffix from the list.';
  }
  return errors;
}

/**
 * The full name sent to the backend: First Middle Last Suffix, single
 * spaces, empty parts left out. The middle name is left out (never replaced)
 * when "No middle name / Not sure" is ticked.
 * @param {Partial<PdlNameParts> | null | undefined} parts
 */
export function composeIntendedPdlName(parts) {
  const p = { ...emptyPdlNameParts(), ...(parts ?? {}) };
  return [p.first, p.noMiddleName ? '' : p.middle, p.last, canonicalPdlSuffix(p.suffix) ?? '']
    .map((part) => normalizeName(part ?? ''))
    .filter(Boolean)
    .join(' ');
}

/** Words that belong to the surname that follows them: Dela Cruz, De los Santos. */
const SURNAME_PARTICLES = new Set([
  'de', 'del', 'dela', 'delas', 'delos', 'la', 'las', 'los', 'di',
  'san', 'santa', 'sta.', 'sto.', 'van', 'von',
]);

/**
 * "Request Again": a stored full name back into editable parts. Every word
 * lands in exactly one part, in order, so composeIntendedPdlName() of the
 * result gives the same name back when nothing is edited (a trailing suffix
 * is only written in its usual form, e.g. "Iii" → "III").
 *
 * Rules: a known suffix at the end → suffix; the first word → first; the last
 * word, with any surname particles right before it → last; the rest → middle.
 * "No middle name / Not sure" is ticked only when there is a last name and
 * nothing is left for the middle. It is a guess the visitor can correct: a
 * two-word first name ("Maria Clara Santos") shows "Clara" as the middle name.
 * @param {string | null | undefined} fullName
 * @returns {PdlNameParts}
 */
export function splitPdlNameForPrefill(fullName) {
  const words = normalizeName(fullName ?? '').split(' ').filter(Boolean);
  const parts = emptyPdlNameParts();
  if (!words.length) return parts;

  if (words.length >= 2) {
    const suffix = canonicalPdlSuffix(words[words.length - 1]);
    if (suffix) {
      parts.suffix = suffix;
      words.pop();
    }
  }

  parts.first = /** @type {string} */ (words.shift());
  if (!words.length) return parts;

  const last = [/** @type {string} */ (words.pop())];
  while (words.length && SURNAME_PARTICLES.has(words[words.length - 1].toLowerCase())) {
    last.unshift(/** @type {string} */ (words.pop()));
  }
  parts.last = last.join(' ');
  parts.middle = words.join(' ');
  parts.noMiddleName = parts.middle === '';
  return parts;
}

// --- PDL's date of birth (registration only) -------------------------------

const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Error for the PDL's date of birth, or null. Required unless "I don't know
 * the exact date" is ticked.
 * @param {string | null | undefined} value — YYYY-MM-DD from BirthdateField
 * @param {boolean} unknown
 */
export function getPdlBirthDateError(value, unknown) {
  if (unknown) return null;
  const v = String(value ?? '').trim();
  if (!v) return "Choose the PDL's date of birth, or tick \"I don't know the exact date\".";
  if (!ISO_DATE_RE.test(v)) return "Choose the PDL's date of birth again.";
  return null;
}

/**
 * Registration body field: `{ intendedPdlBirthDate }` for a real YYYY-MM-DD
 * date, otherwise {} — an unknown date is never sent, never made up.
 * @param {string | null | undefined} value
 */
export function toIntendedPdlBirthDateField(value) {
  const v = String(value ?? '').trim();
  return ISO_DATE_RE.test(v) ? { intendedPdlBirthDate: v } : {};
}
