/**
 * The optional "name of the PDL you want to visit" from registration step 2.
 * Sent as `intendedPdlName` and stored on the visitor profile as an
 * unverified hint for the Record Officer, who links the PDL by hand. The app
 * never looks up PDLs. Pure (no React Native imports) for node --test.
 *
 * Rules mirror AuthController::profileRegistrationRules (the server still
 * validates): optional, 2–150 characters, letters/spaces/. ' -
 */

import { normalizeName, stripControlChars } from './registrationValidation.js';

export const INTENDED_PDL_NAME_LIMITS = { min: 2, max: 150 };

export const INTENDED_PDL_NAME_PLACEHOLDER = 'Enter the name of the PDL you want to visit.';

const NAME_RE = /^\p{L}[\p{L}\p{M} .'’-]*$/u;

/** onChangeText filter. */
export function filterIntendedPdlName(value) {
  return stripControlChars(value).slice(0, INTENDED_PDL_NAME_LIMITS.max);
}

/**
 * Error for the field, or null. Empty is fine (the field is optional).
 * @param {string | null | undefined} value
 */
export function getIntendedPdlNameError(value) {
  const n = normalizeName(value ?? '');
  if (!n) return null;
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
