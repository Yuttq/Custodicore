/**
 * POST /auth/register request body (also the base of /auth/google/register,
 * see useAuth). Pure — no React Native imports — so the contract with
 * AuthController::register() can be unit tested.
 */

import { toIntendedPdlNameField } from './intendedPdlName.js';

/**
 * Map registration gender UI labels (Male / Female) onto the Laravel
 * contract (male / female — the only values the backend accepts).
 * @param {string} gender
 */
function mapGenderForApi(gender) {
  const g = String(gender || '').trim().toLowerCase();
  return g || undefined;
}

/**
 * Build POST /api/auth/register body from RegisterScreen payload.
 * Does NOT send PDL IDs or create visitor-PDL relationships. The required
 * PDL name is sent as intendedPdlName, a hint staff use to link the PDL
 * themselves; without a name the body is exactly as before.
 * @param {Record<string, unknown>} payload
 */
export function buildRegisterBody(payload) {
  const body = {
    fullName: String(payload.fullName || '').trim(),
    // Phase 1 First/Last Name, stored separately as well as in fullName.
    firstName: payload.firstName ? String(payload.firstName).trim() : undefined,
    lastName: payload.lastName ? String(payload.lastName).trim() : undefined,
    email: String(payload.email || '').trim(),
    password: payload.password,
    // Sent exactly as typed — never filled in from `password`, so the
    // backend's required confirmation check stays meaningful.
    password_confirmation: payload.password_confirmation,
    dateOfBirth: payload.dateOfBirth || payload.birthdate,
    gender: mapGenderForApi(payload.gender),
    address: payload.address ? String(payload.address).trim() : undefined,
    contactNumber: payload.contactNumber
      ? String(payload.contactNumber).trim()
      : undefined,
    relationshipHint:
      payload.relationshipHint ||
      payload.relationshipLabel ||
      payload.relationship ||
      undefined,
    ...toIntendedPdlNameField(payload.intendedPdlName),
    // The claimed relationship (spouse, live_in_partner, ...). The backend
    // accepts only the relationshipDocuments this relationship asks for.
    relationshipType: payload.relationship || undefined,
    // Consent given on the first registration screen (Terms & Conditions +
    // Privacy Policy). The backend rejects registration without both.
    acceptedTerms: payload.acceptedTerms === true ? true : undefined,
    acceptedPrivacy: payload.acceptedPrivacy === true ? true : undefined,
    consentVersion: payload.consentVersion,
  };

  Object.keys(body).forEach((key) => {
    if (body[key] === undefined || body[key] === '') delete body[key];
  });

  return body;
}

/**
 * Relationship documents picked in registration step 3, as
 * `[requirementKey, file]` pairs for the multipart
 * `relationshipDocuments[requirementKey]` fields. Only the keys listed in
 * `payload.relationshipDocumentKeys` (the documents the selected
 * relationship asks for) are sent, so a file left over from an earlier
 * answer is never submitted.
 * @param {Record<string, any>} payload
 * @returns {[string, { uri: string, fileName?: string | null, mimeType?: string | null, fileSize?: number | null }][]}
 */
export function getRegistrationRelationshipFiles(payload) {
  const keys = Array.isArray(payload?.relationshipDocumentKeys) ? payload.relationshipDocumentKeys : [];
  const documents = payload?.documents ?? {};
  return keys
    .filter((key) => typeof key === 'string' && documents[key]?.uri)
    .map((key) => [key, documents[key]]);
}
