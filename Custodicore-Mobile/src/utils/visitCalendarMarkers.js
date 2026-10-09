/**
 * Pending / confirmed visit markers for the Home calendar.
 *
 * Pure functions only (no React / React Native imports) so they can be unit
 * tested with `node --test`. Availability is per relationship, so markers are
 * too. GET /api/visits carries no relationship or PDL id, only `pdlName`;
 * both it and GET /api/schedules/availability build that name from the same
 * PDL record, so a visit belongs to a relationship when the names match. When
 * two relationships share a PDL name the owner cannot be told apart, and no
 * markers are shown rather than guessing.
 *
 * Known limitation: the shared-name check only sees the relationships that
 * availability lists (verified ones). A visit tied to an unlisted
 * relationship whose PDL has the same name as a listed one is marked on the
 * listed one's calendar. Fixing that needs a relationship or PDL id on
 * GET /api/visits; none is invented here.
 */

import { isUpcomingVisit, scheduledDateIso } from './activeVisits.js';

/** @typedef {'pending' | 'confirmed'} VisitMarker */

/** Statuses still waiting on staff review or the visitor's confirmation. */
const PENDING_STATUSES = ['assigned', 'pending_confirmation'];

/**
 * @param {unknown} status raw visit status
 * @returns {VisitMarker | null}
 */
export function visitMarkerKind(status) {
  if (status === 'confirmed') return 'confirmed';
  if (PENDING_STATUSES.includes(/** @type {string} */ (status))) return 'pending';
  return null;
}

/** @param {unknown} name */
function pdlKey(name) {
  return typeof name === 'string' ? name.trim() : '';
}

/**
 * The visits that belong to `relationship` (matched by PDL name), or none
 * when its PDL name is missing or shared with another relationship.
 * @template {{ pdlName?: string | null }} T
 * @param {T[] | null | undefined} visits
 * @param {{ pdlName?: string | null } | null | undefined} relationship
 * @param {{ pdlName?: string | null }[] | null | undefined} relationships every relationship the visitor has
 * @returns {T[]}
 */
export function visitsForRelationship(visits, relationship, relationships) {
  const name = pdlKey(relationship?.pdlName);
  if (!name || !Array.isArray(visits) || !Array.isArray(relationships)) return [];
  const sharing = relationships.filter((r) => pdlKey(r?.pdlName) === name).length;
  if (sharing !== 1) return [];
  return visits.filter((v) => pdlKey(v?.pdlName) === name);
}

/**
 * Calendar markers for upcoming visits: `pending` for requests awaiting staff
 * review or the visitor's confirmation, `confirmed` only when every visit on
 * that date is confirmed. Past and closed visits are left out.
 * @param {{ status?: string; scheduledAt?: unknown }[] | null | undefined} visits
 * @param {string} today Manila "YYYY-MM-DD"
 * @returns {Record<string, VisitMarker>}
 */
export function buildVisitDateMarkers(visits, today) {
  /** @type {Record<string, VisitMarker>} */
  const markers = {};
  if (!Array.isArray(visits)) return markers;
  for (const visit of visits) {
    const kind = visitMarkerKind(visit?.status);
    if (!kind || !isUpcomingVisit(visit, today)) continue;
    const date = /** @type {string} */ (scheduledDateIso(visit));
    if (markers[date] !== 'pending') markers[date] = kind;
  }
  return markers;
}
