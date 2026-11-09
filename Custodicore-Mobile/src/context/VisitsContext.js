import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react';
import { useAuth } from '../hooks/useAuth';
import {
  confirmAssignedVisit,
  declineAssignedVisit,
  fetchAssignedVisits,
  submitVisitRequest as submitVisitRequestApi,
} from '../repositories/visitsRepository';

const VisitsContext = createContext(null);

/**
 * Visit state for My Assigned Visits, Visit Details, and Unable To Attend.
 * Phase 2: loads from GET /api/visits when USE_MOCK_VISITS is false.
 */
export function VisitsProvider({ children }) {
  // Visits need a staff-approved visitor; the API refuses them otherwise.
  const { token: sessionToken, isApprovedVisitor } = useAuth();
  const token = isApprovedVisitor ? sessionToken : null;
  const [visits, setVisits] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const clearVisits = useCallback(() => {
    setVisits([]);
    setError(null);
  }, []);

  // `silent` (auto-refresh): no loading state, and a failure keeps the
  // current list and error (it still throws, for the caller to ignore).
  const refreshVisits = useCallback(async (options) => {
    const silent = options?.silent === true;
    if (!token) {
      clearVisits();
      return [];
    }

    if (!silent) {
      setLoading(true);
      setError(null);
    }
    try {
      const list = await fetchAssignedVisits();
      setVisits(list);
      if (silent) setError(null);
      return list;
    } catch (e) {
      if (!silent) setError(e?.message ?? 'Could not load visits.');
      throw e;
    } finally {
      if (!silent) setLoading(false);
    }
  }, [token, clearVisits]);

  // Load visits when session appears; clear when logged out
  useEffect(() => {
    if (!token) {
      clearVisits();
      return;
    }
    refreshVisits().catch(() => {
      // error already stored in state
    });
  }, [token, refreshVisits, clearVisits]);

  const getVisitById = useCallback(
    (id) => visits.find((v) => String(v.id) === String(id)) ?? null,
    [visits],
  );

  const upsertVisit = useCallback((visit) => {
    if (!visit?.id) return;
    setVisits((prev) => {
      const idx = prev.findIndex((v) => String(v.id) === String(visit.id));
      if (idx === -1) return [visit, ...prev];
      const next = [...prev];
      next[idx] = { ...next[idx], ...visit };
      return next;
    });
  }, []);

  const confirmVisit = useCallback(
    async (id) => {
      const existing = getVisitById(id);
      const scheduleId = existing?.scheduleId || id;
      const updated = await confirmAssignedVisit(scheduleId);
      if (updated) {
        upsertVisit(updated);
        return updated;
      }
      // Fallback if mock returned minimal payload
      upsertVisit({ ...(existing || { id }), status: 'confirmed' });
      return { id, status: 'confirmed' };
    },
    [getVisitById, upsertVisit],
  );

  /**
   * Submits a visit request, then reloads the list so it shows up (status
   * `assigned`, awaiting staff review). Throws the API error unchanged;
   * nothing is added locally unless the backend accepted the request.
   */
  const submitVisitRequest = useCallback(
    async (params) => {
      const created = await submitVisitRequestApi(params);
      try {
        await refreshVisits();
      } catch {
        // The request exists server-side; show it even if the reload failed.
        if (created) upsertVisit(created);
      }
      return created;
    },
    [refreshVisits, upsertVisit],
  );

  const submitUnableToAttend = useCallback(
    async (id, { reason, notes }) => {
      const existing = getVisitById(id);
      const scheduleId = existing?.scheduleId || id;
      const updated = await declineAssignedVisit(scheduleId, { reason, notes });
      if (updated) {
        upsertVisit({
          ...updated,
          status: updated.status || 'declined',
          unableReason: reason,
          unableNotes: notes?.trim() || null,
          cancellationReason:
            updated.cancellationReason ||
            [reason, notes?.trim()].filter(Boolean).join(' — ') ||
            null,
        });
        return updated;
      }
      upsertVisit({
        ...(existing || { id }),
        status: 'declined',
        unableReason: reason,
        unableNotes: notes?.trim() || null,
      });
      return { id, status: 'declined' };
    },
    [getVisitById, upsertVisit],
  );

  const value = useMemo(
    () => ({
      visits,
      loading,
      error,
      getVisitById,
      confirmVisit,
      submitVisitRequest,
      submitUnableToAttend,
      refreshVisits,
      clearVisits,
    }),
    [
      visits,
      loading,
      error,
      getVisitById,
      confirmVisit,
      submitVisitRequest,
      submitUnableToAttend,
      refreshVisits,
      clearVisits,
    ],
  );

  return (
    <VisitsContext.Provider value={value}>{children}</VisitsContext.Provider>
  );
}

export function useVisits() {
  const ctx = useContext(VisitsContext);
  if (!ctx) {
    throw new Error('useVisits must be used within a VisitsProvider');
  }
  return ctx;
}
