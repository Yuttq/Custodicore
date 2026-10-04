/**
 * Temporary feature flags for offline UI development.
 * Set each flag to `false` when the corresponding backend endpoint is ready;
 * screens should keep importing from `../repositories/*` only (no direct `api` calls for these flows).
 */

/**
 * When true, login / register / logout /me use local placeholder session (no HTTP).
 * Phase 2: false — wired to Laravel Sanctum auth.
 */
export const USE_MOCK_AUTH = false;

/**
 * When true, assigned visits / confirm / decline use local mock data (no HTTP).
 * Phase 2: false — wired to GET /api/visits + confirm/decline schedules.
 */
export const USE_MOCK_VISITS = false;

/**
 * When true, notification list / unread count / mark-read use local mock data (no HTTP).
 * Phase 4: false — wired to GET /api/notifications, unread-count, PATCH read.
 */
export const USE_MOCK_NOTIFICATIONS = false;

/**
 * When true, QR schedule resolution and gate token use local mock data (no HTTP).
 * Phase 5: false — a mock token cannot be verified by Front Desk; wired to GET /api/schedules/{id}/qr.
 */
export const USE_MOCK_QR = false;

/**
 * When true, visitation history uses local mock data (no HTTP).
 * Phase 4: false — wired to GET /api/visits/history.
 */
export const USE_MOCK_VISIT_HISTORY = false;

/**
 * When true, visit progress timeline uses local mock data (no HTTP).
 * Phase 4: false — wired to GET /api/schedules/{id}/timeline.
 */
export const USE_MOCK_TIMELINE = false;
