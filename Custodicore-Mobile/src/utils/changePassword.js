/**
 * Security Settings → Change Password: the checks run before POST
 * /api/me/password. The backend (Api\PasswordChangeController with
 * VisitorPasswordPolicy) checks everything again; this only saves a round
 * trip and shows the same messages next to the right field.
 */

import { validatePasswordConfirmation, validateRegistrationPassword } from './registrationValidation.js';

/** Backend field → form field, for 422 validation messages. */
export const CHANGE_PASSWORD_SERVER_FIELDS = {
  currentPassword: 'currentPassword',
  password: 'password',
  password_confirmation: 'confirmation',
};

/**
 * @param {{ currentPassword: string; password: string; confirmation: string; email?: string | null }} form
 * @returns {Record<string, string>} field → message; empty when the form can be sent
 */
export function validateChangePasswordForm({ currentPassword, password, confirmation, email }) {
  /** @type {Record<string, string>} */
  const errors = {};

  if (!String(currentPassword ?? '')) errors.currentPassword = 'Enter your current password.';

  const passwordError = validateRegistrationPassword(password);
  if (passwordError) {
    errors.password = passwordError;
  } else if (currentPassword && password === currentPassword) {
    errors.password = 'Your new password must be different from your current password.';
  } else if (email && password.toLowerCase() === String(email).trim().toLowerCase()) {
    errors.password = 'Your password cannot be the same as your email address.';
  }

  const confirmationError = validatePasswordConfirmation(password, confirmation);
  if (confirmationError) errors.confirmation = confirmationError;

  return errors;
}

/**
 * Laravel 422 `errors` → form field messages (first message per field).
 * @param {Record<string, unknown> | null | undefined} serverErrors
 * @returns {Record<string, string>}
 */
export function mapChangePasswordErrors(serverErrors) {
  /** @type {Record<string, string>} */
  const mapped = {};
  if (!serverErrors || typeof serverErrors !== 'object') return mapped;
  for (const [key, messages] of Object.entries(serverErrors)) {
    const field = CHANGE_PASSWORD_SERVER_FIELDS[key];
    if (field && Array.isArray(messages) && typeof messages[0] === 'string' && !mapped[field]) {
      mapped[field] = messages[0];
    }
  }
  return mapped;
}
