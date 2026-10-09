/**
 * Home → My Visits → Schedule handoff: `navigate('Schedule', { tab:
 * 'schedule', relationshipId?, date? })`.
 *
 * Pure functions only (no React / React Native imports) so they can be unit
 * tested with `node --test`. MyAssignedVisitsScreen wires them to its route
 * params, `useReducer` and `useScheduleAvailability`; the React lifecycle
 * itself is not exercised by those tests.
 *
 * Flow: route params become one pending request (params are cleared at
 * once). The request waits for fresh availability, then selects the
 * relationship (only if it is still listed) and, when it carries one, the
 * date (only if still available — otherwise a notice says so). Any manual
 * relationship / date / slot choice cancels a request that is still waiting.
 */

import { parseIsoDate } from './scheduleAvailability.js';

/**
 * @typedef {{ date: string | null; relationshipId: string | null }} HandoffRequest
 * @typedef {{ request: HandoffRequest | null; notice: { date: string } | null }} HandoffState
 */

/** @type {HandoffState} */
export const INITIAL_HANDOFF = { request: null, notice: null };

/**
 * Reads the handoff route params.
 * @param {{ date?: unknown; relationshipId?: unknown } | null | undefined} params
 * @param {boolean} loading whether availability is loading right now
 * @returns {{ consumed: boolean; request: HandoffRequest | null; reload: boolean }}
 *   consumed — params were present and must be cleared;
 *   request — what to apply (an invalid date is dropped, a valid
 *   relationship alone still applies); reload — availability must be fetched
 *   again first (a load already in flight is fresh enough).
 */
export function readHandoffParams(params, loading) {
  const rawDate = params?.date;
  const rawRelationshipId = params?.relationshipId;
  if (rawDate === undefined && rawRelationshipId === undefined) {
    return { consumed: false, request: null, reload: false };
  }
  const date = typeof rawDate === 'string' && parseIsoDate(rawDate) ? rawDate : null;
  const relationshipId =
    rawRelationshipId == null || String(rawRelationshipId).trim() === ''
      ? null
      : String(rawRelationshipId).trim();
  if (!date && !relationshipId) return { consumed: true, request: null, reload: false };
  return { consumed: true, request: { date, relationshipId }, reload: !loading };
}

/**
 * @param {HandoffState} state
 * @param {{ type: 'request'; request: HandoffRequest }
 *   | { type: 'manual' }
 *   | { type: 'settle'; request: HandoffRequest; notice?: { date: string } | null }} action
 * @returns {HandoffState}
 */
export function handoffReducer(state, action) {
  switch (action.type) {
    case 'request':
      // A newer navigation replaces any request still waiting.
      return { request: action.request, notice: null };
    case 'manual':
      // The visitor chose for themselves: nothing pending may override that.
      return state.request === null && state.notice === null ? state : INITIAL_HANDOFF;
    case 'settle':
      // Only the request that was resolved may finish; a stale one is ignored.
      if (state.request !== action.request) return state;
      return { request: null, notice: action.notice ?? null };
    default:
      return state;
  }
}

/**
 * What to do next for a pending request, given the current availability.
 * @param {HandoffRequest | null} request
 * @param {{
 *   loading: boolean;
 *   error: string | null;
 *   relationships: { relationshipId: string }[];
 *   relationshipId: string | null;
 *   daysByDate: Record<string, { available: boolean }>;
 * }} schedule
 * @returns {{ type: 'idle' | 'wait' | 'drop' | 'done' }
 *   | { type: 'select_relationship'; relationshipId: string }
 *   | { type: 'select_date' | 'date_unavailable'; date: string }}
 */
export function nextHandoffStep(request, schedule) {
  if (!request) return { type: 'idle' };
  if (schedule.loading) return { type: 'wait' };
  if (schedule.error || schedule.relationships.length === 0) return { type: 'drop' };

  if (request.relationshipId) {
    const listed = schedule.relationships.some(
      (r) => r.relationshipId === request.relationshipId,
    );
    // Never fall back to another relationship's availability.
    if (!listed) return { type: 'drop' };
    if (schedule.relationshipId !== request.relationshipId) {
      return { type: 'select_relationship', relationshipId: request.relationshipId };
    }
  } else if (!schedule.relationshipId) {
    return { type: 'drop' };
  }

  if (!request.date) return { type: 'done' };
  return schedule.daysByDate[request.date]?.available
    ? { type: 'select_date', date: request.date }
    : { type: 'date_unavailable', date: request.date };
}
