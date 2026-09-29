import React, {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
} from 'react';
import { MOCK_ASSIGNED_VISITS } from '../mock/assignedVisits.mock';

const VisitsContext = createContext(null);

/**
 * Local mock visit state shared across My Assigned Visits, Visit Details, and Unable To Attend.
 * Replace with API + cache when backend is available.
 */
export function VisitsProvider({ children }) {
  const [visits, setVisits] = useState(() =>
    MOCK_ASSIGNED_VISITS.map((v) => ({ ...v })),
  );

  const getVisitById = useCallback(
    (id) => visits.find((v) => v.id === id) ?? null,
    [visits],
  );

  const updateVisit = useCallback((id, patch) => {
    setVisits((prev) =>
      prev.map((v) => (v.id === id ? { ...v, ...patch } : v)),
    );
  }, []);

  const confirmVisit = useCallback(
    async (id) => {
      await new Promise((r) => setTimeout(r, 300));
      updateVisit(id, { status: 'confirmed' });
    },
    [updateVisit],
  );

  const submitUnableToAttend = useCallback(
    async (id, { reason, notes }) => {
      await new Promise((r) => setTimeout(r, 350));
      updateVisit(id, {
        status: 'unable_to_attend',
        unableReason: reason,
        unableNotes: notes?.trim() || null,
      });
    },
    [updateVisit],
  );

  const refreshVisits = useCallback(async () => {
    await new Promise((r) => setTimeout(r, 400));
  }, []);

  const value = useMemo(
    () => ({
      visits,
      getVisitById,
      confirmVisit,
      submitUnableToAttend,
      refreshVisits,
    }),
    [visits, getVisitById, confirmVisit, submitUnableToAttend, refreshVisits],
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
