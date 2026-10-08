import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { fetchScheduleAvailability } from '../repositories/scheduleRepository';
import { toVisitRequestParams } from '../utils/scheduleAvailability';

/**
 * Loads visit-slot availability (GET /api/schedules/availability) and holds
 * the visitor's relationship / date / time-slot selection for the Home
 * calendar. A slot is one session; a day's `wholeDay` option is all of its
 * sessions as ONE visit. Selecting either reserves nothing; `requestParams` is the body
 * the Schedule tab submits to POST /api/visit-requests.
 *
 * @param {object} [options]
 * @param {boolean} [options.enabled=true] — false skips loading (e.g. not approved yet)
 * @param {string} [options.from] — YYYY-MM-DD; backend defaults to today
 * @param {string} [options.to] — YYYY-MM-DD; backend defaults to today + 30 days
 * @param {string} [options.relationshipId] — load only this verified relationship
 */
export default function useScheduleAvailability({
  enabled = true,
  from,
  to,
  relationshipId,
} = {}) {
  const [data, setData] = useState(
    /** @type {ReturnType<typeof import('../utils/scheduleAvailability').normalizeAvailabilityResponse> | null} */ (null),
  );
  const [loading, setLoading] = useState(Boolean(enabled));
  const [error, setError] = useState(/** @type {string | null} */ (null));
  const [selectedRelationshipId, setSelectedRelationshipId] = useState(
    /** @type {string | null} */ (relationshipId ?? null),
  );
  const [selectedDate, setSelectedDate] = useState(/** @type {string | null} */ (null));
  const [selectedSlotKey, setSelectedSlotKey] = useState(/** @type {string | null} */ (null));
  const requestId = useRef(0);

  const load = useCallback(async () => {
    const current = ++requestId.current;
    if (!enabled) {
      setData(null);
      setError(null);
      setLoading(false);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const result = await fetchScheduleAvailability({ from, to, relationshipId });
      if (current === requestId.current) setData(result);
    } catch (e) {
      if (current === requestId.current) {
        setError(
          e instanceof Error && e.message.trim() ? e.message : 'Could not load visit availability.',
        );
      }
    } finally {
      if (current === requestId.current) setLoading(false);
    }
  }, [enabled, from, to, relationshipId]);

  useEffect(() => {
    load();
  }, [load]);

  const relationships = useMemo(() => data?.relationships ?? [], [data]);

  const relationship = useMemo(
    () =>
      relationships.find((r) => r.relationshipId === selectedRelationshipId) ??
      relationships[0] ??
      null,
    [relationships, selectedRelationshipId],
  );

  const daysByDate = useMemo(() => relationship?.daysByDate ?? {}, [relationship]);
  const selectedDay = selectedDate ? (daysByDate[selectedDate] ?? null) : null;
  const selectedDaySlots = useMemo(() => selectedDay?.slots ?? [], [selectedDay]);
  const selectedDayWholeDay = selectedDay?.wholeDay ?? null;
  const selectedSlot =
    selectedDaySlots.find((s) => s.slotKey === selectedSlotKey) ??
    (selectedDayWholeDay?.slotKey === selectedSlotKey ? selectedDayWholeDay : null);

  // A reload can make the chosen date/slot unavailable — drop it then.
  useEffect(() => {
    if (selectedDate && !daysByDate[selectedDate]?.available) {
      setSelectedDate(null);
      setSelectedSlotKey(null);
    }
  }, [daysByDate, selectedDate]);

  useEffect(() => {
    if (selectedSlotKey && !selectedSlot?.available) setSelectedSlotKey(null);
  }, [selectedSlot, selectedSlotKey]);

  const selectRelationship = useCallback((id) => {
    setSelectedRelationshipId(id == null ? null : String(id));
    setSelectedDate(null);
    setSelectedSlotKey(null);
  }, []);

  const selectDate = useCallback(
    (date) => {
      if (!daysByDate[date]?.available) return;
      setSelectedDate(date);
      setSelectedSlotKey(null);
    },
    [daysByDate],
  );

  const selectSlot = useCallback(
    (slotKey) => {
      const slot =
        selectedDaySlots.find((s) => s.slotKey === slotKey) ??
        (selectedDayWholeDay?.slotKey === slotKey ? selectedDayWholeDay : null);
      if (!slot?.available) return;
      setSelectedSlotKey(slotKey);
    },
    [selectedDaySlots, selectedDayWholeDay],
  );

  const clearSelection = useCallback(() => {
    setSelectedDate(null);
    setSelectedSlotKey(null);
  }, []);

  const selection = useMemo(
    () =>
      selectedSlot && relationship
        ? {
            relationshipId: relationship.relationshipId,
            date: selectedSlot.date,
            startTime: selectedSlot.startTime,
            endTime: selectedSlot.endTime,
            period: selectedSlot.period,
            slotKey: selectedSlot.slotKey,
            startTimes: selectedSlot.startTimes ?? [selectedSlot.startTime],
          }
        : null,
    [selectedSlot, relationship],
  );

  const requestParams = useMemo(() => toVisitRequestParams(selection), [selection]);

  return {
    loading,
    error,
    reload: load,
    message: data?.message ?? null,
    today: data?.today ?? null,
    from: data?.from ?? null,
    to: data?.to ?? null,
    relationships,
    relationship,
    selectRelationship,
    daysByDate,
    selectedDate,
    selectDate,
    selectedDaySlots,
    selectedDayWholeDay,
    selectedSlotKey: selectedSlot ? selectedSlotKey : null,
    selectSlot,
    clearSelection,
    selection,
    requestParams,
  };
}
