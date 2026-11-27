/**
 * The visitor's own name in parts: Last Name, First Name, optional Middle
 * Name, optional Suffix (the system's standard input order).
 *
 * The backend stores and returns `fullName` in natural order
 * ("Juan Santos Dela Cruz Jr."), and every older server or client only knows
 * fullName / firstName / lastName, so the app always sends fullName joined
 * First Middle Last Suffix (like VisitorProfile::composeFullName) alongside
 * the parts. Pure (no React Native imports) for node --test.
 */

import { canonicalPdlSuffix, splitPdlNameForPrefill } from './intendedPdlName.js';
import { normalizeName, validateName } from './registrationValidation.js';

/** visitor_profiles.full_name is varchar(150). */
export const VISITOR_FULL_NAME_MAX = 150;

/**
 * @typedef {object} VisitorNameParts
 * @property {string} last
 * @property {string} first
 * @property {string} middle — optional, '' when none
 * @property {string} suffix — '' or one of PDL_NAME_SUFFIXES
 */

/** @returns {VisitorNameParts} */
export function emptyVisitorNameParts() {
  return { last: '', first: '', middle: '', suffix: '' };
}

/** "Juan Santos Dela Cruz Jr." — natural order, empty parts left out. */
export function composeVisitorFullName(parts) {
  const p = { ...emptyVisitorNameParts(), ...(parts ?? {}) };
  return [p.first, p.middle, p.last, canonicalPdlSuffix(p.suffix) ?? '']
    .map((part) => normalizeName(part ?? ''))
    .filter(Boolean)
    .join(' ');
}

/** Optional middle name: null when empty or valid. */
export function validateMiddleName(value) {
  if (!normalizeName(value ?? '')) return null;
  return validateName(value, 'Middle name');
}

/**
 * Errors keyed lastName / firstName / middleName / suffix ({} when valid),
 * in the order the fields are shown.
 * @param {Partial<VisitorNameParts> | null | undefined} parts
 * @returns {Record<string, string>}
 */
export function getVisitorNameErrors(parts) {
  const p = { ...emptyVisitorNameParts(), ...(parts ?? {}) };
  /** @type {Record<string, string>} */
  const errors = {};
  const put = (key, message) => {
    if (message) errors[key] = message;
  };
  put('lastName', validateName(p.last, 'Last name'));
  put('firstName', validateName(p.first, 'First name'));
  put('middleName', validateMiddleName(p.middle));
  if (String(p.suffix ?? '').trim() && !canonicalPdlSuffix(p.suffix)) {
    errors.suffix = 'Choose a suffix from the list.';
  }
  if (!errors.lastName && composeVisitorFullName(p).length > VISITOR_FULL_NAME_MAX) {
    errors.lastName = `The full name is too long (${VISITOR_FULL_NAME_MAX} characters at most in total).`;
  }
  return errors;
}

/**
 * API body fields for the name: fullName (natural order, read by every
 * server version) plus the parts. middleName / suffix are left out when
 * empty, so the body for a name without them is exactly as before.
 * @param {Partial<VisitorNameParts>} parts
 */
export function toVisitorNameFields(parts) {
  const p = { ...emptyVisitorNameParts(), ...(parts ?? {}) };
  const middle = normalizeName(p.middle ?? '');
  const suffix = canonicalPdlSuffix(p.suffix);
  return {
    fullName: composeVisitorFullName(p),
    firstName: normalizeName(p.first ?? ''),
    lastName: normalizeName(p.last ?? ''),
    ...(middle ? { middleName: middle } : {}),
    ...(suffix ? { suffix } : {}),
  };
}

/**
 * Editable parts for a signed-in visitor. Uses the stored parts when the
 * server sent them; an older profile with only fullName is split as a guess
 * (splitPdlNameForPrefill: suffix, first word, surname with particles) for
 * the visitor to check.
 * @param {{ fullName?: string | null; firstName?: string | null; middleName?: string | null; lastName?: string | null; suffix?: string | null } | null | undefined} user
 * @returns {VisitorNameParts}
 */
export function visitorNamePartsFromUser(user) {
  const u = user ?? {};
  if ((u.firstName ?? '').trim() || (u.lastName ?? '').trim()) {
    return {
      last: u.lastName ?? '',
      first: u.firstName ?? '',
      middle: u.middleName ?? '',
      suffix: canonicalPdlSuffix(u.suffix) ?? '',
    };
  }
  const guess = splitPdlNameForPrefill(u.fullName ?? '');
  return { last: guess.last, first: guess.first, middle: guess.middle, suffix: guess.suffix };
}

/** True when the parts differ from the user's stored name (normalized). */
export function visitorNameChanged(parts, user) {
  const before = toVisitorNameFields(visitorNamePartsFromUser(user));
  const after = toVisitorNameFields(parts);
  return (
    before.fullName !== after.fullName ||
    before.firstName !== after.firstName ||
    before.lastName !== after.lastName ||
    (before.middleName ?? '') !== (after.middleName ?? '') ||
    (before.suffix ?? '') !== (after.suffix ?? '')
  );
}
