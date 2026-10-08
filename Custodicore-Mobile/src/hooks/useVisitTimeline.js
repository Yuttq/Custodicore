import { useCallback, useEffect, useState } from 'react';
import { fetchVisitTimeline } from '../repositories/timelineRepository';
import { buildCompactVisitStepsFromTimeline } from '../utils/visitProgressSnapshot';

/**
 * Loads the real timeline for one visit and builds the compact progress steps.
 * Returns no steps (not mock steps) when there is no visit or no events.
 *
 * @param {string | null | undefined} visitId — visit_requests.visit_request_id
 * @param {string | undefined} visitStatus — refetches when it changes; `assigned`
 *   shows the "Awaiting Staff Review" step
 */
export default function useVisitTimeline(visitId, visitStatus) {
  const [steps, setSteps] = useState(
    /** @type {import('../utils/visitProgressSnapshot').CompactVisitStep[]} */ ([]),
  );
  const [loading, setLoading] = useState(Boolean(visitId));
  const [error, setError] = useState(/** @type {string | null} */ (null));

  const load = useCallback(async () => {
    if (!visitId) {
      setSteps([]);
      setError(null);
      setLoading(false);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const events = await fetchVisitTimeline(String(visitId), visitStatus);
      setSteps(events.length > 0 ? buildCompactVisitStepsFromTimeline(events, visitStatus) : []);
    } catch (e) {
      setError(e instanceof Error && e.message.trim() ? e.message : 'Could not load timeline.');
      setSteps([]);
    } finally {
      setLoading(false);
    }
  }, [visitId, visitStatus]);

  useEffect(() => {
    load();
  }, [load]);

  return { steps, loading, error, reload: load };
}
