import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { Button, Card, colors, layout, spacing, typography } from '../designSystem';
import TimeSlotList from './TimeSlotList';
import VisitCalendar from './VisitCalendar';
import {
  formatDateLong,
  formatSlotRange,
  formatVisitingDays,
  middayBreakLabel,
  periodLabel,
  visitingWindows,
  WHOLE_DAY_PERIOD,
} from '../utils/scheduleAvailability';

/** What booking the whole day means at the gate. */
function wholeDayNote(slots) {
  const breakLabel = middayBreakLabel(slots);
  return (
    'Morning and afternoon sessions on the same day are one visit and count once toward ' +
    'your weekly limit. You leave during the midday break' +
    (breakLabel ? ` (${breakLabel})` : '') +
    ' and re-enter for the afternoon with the same QR pass.'
  );
}

function TextLink({ label, onPress, accessibilityLabel }) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.textLink, pressed && styles.pressed]}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
    >
      <Text style={styles.textLinkLabel}>{label}</Text>
      <Ionicons name="chevron-forward" size={16} color={colors.primaryTeal} />
    </Pressable>
  );
}

/**
 * Visiting days/hours for the visitor's verified PDL relationship(s), the
 * date calendar and the time slots for the chosen date. Choosing a slot
 * reserves nothing; the submit button sends `schedule.requestParams` to
 * `onSubmitRequest` (POST /api/visit-requests). The resulting request awaits
 * staff review (`assigned`) — it is never confirmed here.
 * @param {object} props
 * @param {ReturnType<typeof import('../hooks/useScheduleAvailability').default>} props.schedule
 * @param {(params: { relationshipId: string; date: string; startTime: string; startTimes?: string[] }) => Promise<unknown>} props.onSubmitRequest
 * @param {() => void} [props.onViewPending] — shows the Pending tab
 */
export default function VisitationScheduleSection({ schedule, onSubmitRequest, onViewPending }) {
  const {
    loading,
    error,
    reload,
    message,
    today,
    from,
    to,
    relationships,
    relationship,
    selectRelationship,
    daysByDate,
    selectedDate,
    selectDate,
    selectedDaySlots,
    selectedDayWholeDay,
    selectedSlotKey,
    selectSlot,
    clearSelection,
    selection,
    requestParams,
  } = schedule;

  const windows = useMemo(() => visitingWindows(relationship), [relationship]);

  const selectionKey = selection
    ? `${selection.relationshipId}|${selection.date}|${selection.slotKey}`
    : null;

  // Visit request submission. The ref blocks a second POST from a fast
  // double tap before `submitting` re-renders the button as disabled.
  const submittingRef = useRef(false);
  const [submitting, setSubmitting] = useState(false);
  // An API error belongs to the slot it was raised for; another choice hides it.
  const [submitError, setSubmitError] = useState(
    /** @type {{ key: string; message: string } | null} */ (null),
  );
  // The last successfully submitted slot, shown until a new slot is chosen.
  const [submitted, setSubmitted] = useState(
    /** @type {{ pdlName: string | null; date: string; period: string; startTime: string; endTime: string } | null} */ (null),
  );
  useEffect(() => {
    if (selectionKey) setSubmitted(null);
  }, [selectionKey]);
  const errorMessage =
    submitError && submitError.key === selectionKey ? submitError.message : null;

  const handleSubmit = async () => {
    if (submittingRef.current || !requestParams || !selection) return;
    submittingRef.current = true;
    setSubmitting(true);
    setSubmitError(null);
    const key = selectionKey;
    const chosen = {
      pdlName: relationship?.pdlName ?? null,
      date: selection.date,
      period: selection.period,
      startTime: selection.startTime,
      endTime: selection.endTime,
    };
    try {
      await onSubmitRequest({
        relationshipId: requestParams.relationshipId,
        date: requestParams.date,
        startTime: requestParams.startTime,
        // Whole day: both sessions, booked as one visit.
        ...(requestParams.startTimes ? { startTimes: requestParams.startTimes } : {}),
      });
      clearSelection();
      setSubmitted(chosen);
      // The slot's remaining capacity changed; show the backend's view of it.
      reload();
    } catch (e) {
      setSubmitError({
        key,
        message:
          e instanceof Error && e.message.trim()
            ? e.message
            : 'Could not submit your visit request. Please try again.',
      });
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  };
  const hasAvailableDate = useMemo(
    () => Object.values(daysByDate).some((d) => d.available),
    [daysByDate],
  );

  let info;
  if (loading && !relationship) {
    info = (
      <View style={styles.scheduleLoading}>
        <ActivityIndicator color={colors.primaryTeal} />
        <Text style={styles.noVisit}>Loading visit availability…</Text>
      </View>
    );
  } else if (error && !relationship) {
    info = (
      <>
        <Text style={styles.noVisit}>{error}</Text>
        <TextLink label="Try Again" onPress={reload} accessibilityLabel="Reload visit availability" />
      </>
    );
  } else if (!relationship) {
    info = (
      <View style={styles.scheduleNotice}>
        <Ionicons name="information-circle-outline" size={20} color={colors.primaryNavy} />
        <Text style={styles.scheduleNoticeText}>
          {message ??
            'A verified PDL relationship is required to view visit availability.'}
        </Text>
      </View>
    );
  } else {
    info = (
      <>
        {relationships.length > 1 ? (
          <View style={styles.chipRow}>
            {relationships.map((r) => {
              const active = r.relationshipId === relationship.relationshipId;
              return (
                <Pressable
                  key={r.relationshipId}
                  onPress={() => selectRelationship(r.relationshipId)}
                  style={({ pressed }) => [
                    styles.chip,
                    active && styles.chipActive,
                    pressed && styles.pressed,
                  ]}
                  accessibilityRole="button"
                  accessibilityState={{ selected: active }}
                  accessibilityLabel={`Show schedule for ${r.pdlName ?? 'PDL'}`}
                >
                  <Text style={[styles.chipText, active && styles.chipTextActive]}>
                    {r.pdlName ?? 'PDL'}
                  </Text>
                </Pressable>
              );
            })}
          </View>
        ) : null}
        {relationship.pdlName ? (
          <Text style={styles.visitPdl}>{relationship.pdlName}</Text>
        ) : null}
        {relationship.visitingDays.length > 0 ? (
          <Text style={styles.scheduleLine}>
            Visiting days: {formatVisitingDays(relationship.visitingDays)}
          </Text>
        ) : null}
        {windows.map((w) => (
          <Text key={`${w.startTime}-${w.endTime}`} style={styles.scheduleLine}>
            {periodLabel(w.period, w.startTime)}: {formatSlotRange(w.startTime, w.endTime)}
          </Text>
        ))}
        {relationship.message ? (
          <Text style={styles.scheduleWarning}>{relationship.message}</Text>
        ) : !hasAvailableDate && !loading ? (
          <Text style={styles.scheduleWarning}>
            No visit dates are available in the coming weeks. Please check again later.
          </Text>
        ) : null}
      </>
    );
  }

  return (
    <View style={styles.section}>
      <Text style={styles.sectionLabel}>Visitation Schedule</Text>
      <Card style={styles.visitCard}>{info}</Card>

      {relationship ? (
        <>
          <Text style={styles.sectionLabel}>Choose a Visit Date</Text>
          <Card style={styles.visitCard}>
            <VisitCalendar
              key={relationship.relationshipId}
              daysByDate={daysByDate}
              today={today}
              minDate={from}
              maxDate={to}
              selectedDate={selectedDate}
              onSelectDate={selectDate}
            />
          </Card>

          <Text style={styles.sectionLabel}>Available Time Slots</Text>
          <Card style={styles.visitCard}>
            {selectedDate ? (
              <>
                <Text style={styles.slotDate}>{formatDateLong(selectedDate)}</Text>
                <TimeSlotList
                  slots={selectedDaySlots}
                  wholeDay={selectedDayWholeDay}
                  selectedSlotKey={selectedSlotKey}
                  onSelectSlot={selectSlot}
                />
              </>
            ) : (
              <Text style={styles.noVisit}>Select an available date to see its time slots.</Text>
            )}
          </Card>

          {selection ? (
            <Card style={styles.visitCard}>
              <Text style={styles.sectionLabel}>Selected Visit Slot</Text>
              <Text style={styles.visitDate}>{formatDateLong(selection.date)}</Text>
              <Text style={styles.visitTime}>
                {periodLabel(selection.period, selection.startTime)} ·{' '}
                {formatSlotRange(selection.startTime, selection.endTime)}
              </Text>
              {selection.period === WHOLE_DAY_PERIOD ? (
                <Text style={[styles.noVisit, styles.selectionNote]}>
                  {wholeDayNote(selectedDaySlots)}
                </Text>
              ) : null}
              <View style={styles.selectionAction}>
                <Button
                  title="Submit Visit Request"
                  onPress={handleSubmit}
                  loading={submitting}
                  accessibilityLabel={`Submit visit request: ${formatDateLong(selection.date)}, ${periodLabel(selection.period, selection.startTime)}`}
                />
              </View>
              {errorMessage ? (
                <View
                  style={styles.selectionError}
                  accessibilityLiveRegion="polite"
                  accessibilityRole="alert"
                >
                  <Ionicons name="alert-circle" size={20} color={colors.danger} />
                  <Text style={styles.selectionErrorText}>{errorMessage}</Text>
                </View>
              ) : null}
              <Text style={[styles.noVisit, styles.selectionNote]}>
                Your request will be sent to the facility for review. The visit is not
                confirmed until staff approve it.
              </Text>
            </Card>
          ) : submitted ? (
            <Card style={[styles.visitCard, styles.selectionCardConfirmed]}>
              <Text style={styles.sectionLabel}>Visit Request Submitted</Text>
              <Text style={styles.visitDate}>{formatDateLong(submitted.date)}</Text>
              <Text style={styles.visitTime}>
                {periodLabel(submitted.period, submitted.startTime)} ·{' '}
                {formatSlotRange(submitted.startTime, submitted.endTime)}
              </Text>
              <View
                style={styles.selectionConfirmed}
                accessibilityLiveRegion="polite"
                accessibilityLabel="Visit request submitted. Awaiting staff review."
              >
                <Ionicons name="checkmark-circle" size={20} color={colors.success} />
                <Text style={styles.selectionConfirmedText}>
                  Request submitted — awaiting staff review
                </Text>
              </View>
              <Text style={[styles.noVisit, styles.selectionNote]}>
                {submitted.pdlName ? `Your request to visit ${submitted.pdlName}` : 'Your request'}{' '}
                is now under review by facility staff. You will be notified once it is approved
                or rejected. You can follow it in the Pending tab.
              </Text>
              {onViewPending ? (
                <TextLink
                  label="View Pending Requests"
                  onPress={onViewPending}
                  accessibilityLabel="View pending visit requests"
                />
              ) : null}
            </Card>
          ) : null}
        </>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  section: {
    marginBottom: spacing.sm,
  },
  sectionLabel: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  visitCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.cardGap,
  },
  visitDate: {
    ...typography.cardTitle,
    color: colors.primaryNavy,
  },
  visitTime: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    marginTop: spacing.xs,
  },
  visitPdl: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  noVisit: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  textLink: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    paddingVertical: spacing.xs,
    gap: spacing.xs,
  },
  textLinkLabel: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.primaryTeal,
  },
  scheduleLoading: {
    alignItems: 'center',
    gap: spacing.sm,
  },
  scheduleNotice: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
  },
  scheduleNoticeText: {
    ...typography.metadata,
    color: colors.textPrimary,
    flex: 1,
  },
  scheduleLine: {
    ...typography.metadata,
    color: colors.textPrimary,
    marginBottom: spacing.xs,
  },
  scheduleWarning: {
    ...typography.metadata,
    color: colors.warning,
    fontWeight: '600',
    marginTop: spacing.sm,
  },
  chipRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    marginBottom: spacing.md,
  },
  chip: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderRadius: layout.chipRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
  },
  chipActive: {
    borderColor: colors.primaryNavy,
    backgroundColor: colors.primaryNavy,
  },
  chipText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  chipTextActive: {
    color: colors.white,
  },
  slotDate: {
    ...typography.body,
    fontWeight: '600',
    color: colors.primaryNavy,
    marginBottom: spacing.sm,
  },
  selectionNote: {
    marginTop: spacing.sm,
    marginBottom: 0,
  },
  selectionAction: {
    marginTop: spacing.md,
  },
  selectionCardConfirmed: {
    borderWidth: 2,
    borderColor: colors.success,
  },
  selectionConfirmed: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderRadius: layout.buttonRadius,
    backgroundColor: 'rgba(22, 163, 74, 0.1)',
  },
  selectionConfirmedText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.success,
    flexShrink: 1,
  },
  selectionError: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    marginTop: spacing.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderRadius: layout.buttonRadius,
    backgroundColor: 'rgba(220, 38, 38, 0.08)',
  },
  selectionErrorText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.danger,
    flexShrink: 1,
  },
  pressed: { opacity: 0.88 },
});
