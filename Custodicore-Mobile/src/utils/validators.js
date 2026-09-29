const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function validateEmail(email) {
  return EMAIL_REGEX.test(String(email || '').trim());
}

export function validatePassword(password) {
  return String(password || '').length >= 6;
}

export function validateRequired(value) {
  return String(value ?? '').trim().length > 0;
}
