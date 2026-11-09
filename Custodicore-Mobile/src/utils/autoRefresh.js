/**
 * Auto-refresh rules (useAutoRefresh). Pure functions so they can be unit
 * tested with `node --test`.
 */

/** How often an open screen quietly reloads while the app is in front. */
export const AUTO_REFRESH_MS = 30_000;

/**
 * True when the app comes back to the front (background/inactive → active).
 * On web, react-native-web reports a hidden tab as `background`.
 *
 * @param {string | null | undefined} previous
 * @param {string | null | undefined} next
 */
export function cameToForeground(previous, next) {
  return /inactive|background/.test(String(previous)) && next === 'active';
}

/**
 * Whether the timer should run: only while the screen is focused and the
 * app is in front, so nothing polls from the background.
 *
 * @param {boolean} focused
 * @param {string | null | undefined} appState
 * @param {boolean} [enabled]
 */
export function shouldPoll(focused, appState, enabled = true) {
  return Boolean(enabled && focused && appState === 'active');
}
