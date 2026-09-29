/**
 * Mock forgot-password API — replace with POST /api/forgot-password when Laravel is ready.
 */

const MOCK_DELAY_MS = 600;

/**
 * @param {string} email
 * @returns {Promise<{ success: boolean }>}
 */
export async function requestPasswordReset(email) {
  const trimmed = String(email || '').trim();
  await delay(MOCK_DELAY_MS);
  return { success: true, email: trimmed };
}

/**
 * @param {string} email
 * @returns {Promise<{ success: boolean; message: string }>}
 */
export async function resendPasswordReset(email) {
  const trimmed = String(email || '').trim();
  await delay(400);
  return {
    success: true,
    email: trimmed,
    message: 'Reset link sent successfully.',
  };
}

function delay(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}
