/**
 * Visitor-facing wording for "no visit yet" and "no availability yet" states
 * on Home, the Visitation Calendar and My Visits, kept in one place so the
 * screens say the same thing. Pure — no React Native imports — so it runs
 * under `node --test`.
 *
 * A visit can only be requested for a relationship facility staff have
 * verified (GET /api/schedules/availability lists only those, and
 * POST /api/visits rejects the rest), so the request action is offered only
 * when availability returned a verified relationship.
 */

export const NO_UPCOMING_VISIT_TITLE = "You don't have an upcoming visit yet.";

export const NO_UPCOMING_VISIT_UNVERIFIED =
  'Once your relationship is verified and a visit is scheduled, your visit will appear here.';

export const NO_UPCOMING_VISIT_CAN_REQUEST =
  'Choose an available date to request a visit. Staff will review your request.';

/** Visitation Calendar / Schedule when no relationship is verified yet. */
export const RELATIONSHIP_NOT_VERIFIED_SCHEDULE =
  'Visit schedules will be available after staff verify your relationship to the PDL.';

/**
 * Home "Upcoming Visit" card when there is no next visit.
 * @param {{ canRequestVisit: boolean }} options — true only when the visitor
 *   is approved and availability returned at least one verified relationship
 * @returns {{ title: string, message: string, showRequestAction: boolean }}
 */
export function getNoUpcomingVisitState({ canRequestVisit }) {
  return {
    title: NO_UPCOMING_VISIT_TITLE,
    message: canRequestVisit ? NO_UPCOMING_VISIT_CAN_REQUEST : NO_UPCOMING_VISIT_UNVERIFIED,
    showRequestAction: Boolean(canRequestVisit),
  };
}
