/**
 * @typedef {Object} VisitorProfileLocal
 * @property {string} fullName
 * @property {string} email
 * @property {string} phone
 * @property {string} registrationStatus
 */

/**
 * TEMP MOCK DATA — Replace with backend API response later
 * (e.g. hydrate from GET /visitor/me or auth session after sign-in).
 *
 * @type {VisitorProfileLocal}
 */
export const DEFAULT_LOCAL_PROFILE = {
  fullName: 'Maria Santos',
  email: 'maria.santos@example.com',
  phone: '+63 917 000 0000',
  registrationStatus: 'approved',
  /** Used for visitor verification document requirements (mock). */
  relationshipToPdl: 'spouse',
};
