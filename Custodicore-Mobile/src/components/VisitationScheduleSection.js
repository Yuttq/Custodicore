import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { Button, Card, colors, layout, spacing, typography } from '../designSystem';
import RelationshipPicker from './RelationshipPicker';
import TimeSlotList from './TimeSlotList';
import VisitCalendar from './VisitCalendar';
import WeeklyVisitCounter from './WeeklyVisitCounter';
import {
  firstBookableDate,
  formatDateLong,
  formatSlotRange,
  formatVisitingDays,
  isWeeklyLimitDay,
  isWeeklyLimitError,
  middayBreakLabel,
  WEEKLY_LIMIT_REACHED_TITLE,
  weeklyLimitReachedNotice,
  weeklyUsageFor,
  periodLabel,
  visitingWindows,
  WHOLE_DAY_PERIOD,
} from '../utils/scheduleAvailability';
import { noAvailabilityNotice, noAvailabilityReason } from '../utils/noAvailabilityMessage';
import { RELATIONSHIP_NOT_VERIFIED_SCHEDULE } from '../utils/visitGuidance';

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
 * reserves nothing; Schedule Visit sends `schedule.requestParams` to
 * `onSubmitRequest` (POST /api/visit-requests). The backend books the visit
 * as `confirmed` at once, or refuses it (422) with the reason.
 * @param {object} props
 * @param {ReturnType<typeof import('../hooks/useScheduleAvailability').default>} props.schedule
 * @param {(params: { relationshipId: string; date: string; startTime: string; startTimes?: string[] }) => Promise<{ id?: string; status?: string } | null>} props.onSubmitRequest
 *   resolves to the created visit (normalized), or null when the response could not be read
 * @param {(visitId: string) => void} [props.onViewQrPass] — opens the QR pass of that visit
 * @param {() => void} [props.onViewUpcoming] — shows the Upcoming tab
 */
export default function VisitationScheduleSection({
  schedule,
  onSubmitRequest,
  onViewQrPass,
  onViewUpcoming,
}) {
  const {
    loading,
    error,
    reload,
    today,
    from,
    to,
    weeklyLimit,
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

  // Booking. The ref blocks a second POST from a fast double tap before
  // `submitting` re-renders the button as disabled.
  const submittingRef = useRef(false);
  const [submitting, setSubmitting] = useState(false);
  // An API error belongs to the slot it was raised for; another choice hides it.
  const [submitError, setSubmitError] = useState(
    /** @type {{ key: string; title: string | null; message: string } | null} */ (null),
  );
  // The last successfully booked slot, shown until a new slot is chosen.
  // `visitId` / `confirmed` come from the backend's response only.
  const [submitted, setSubmitted] = useState(
    /** @type {{ pdlName: string | null; date: string; period: string; startTime: string; endTime: string; visitId: string | null; confirmed: boolean } | null} */ (null),
  );
  useEffect(() => {
    if (selectionKey) setSubmitted(null);
  }, [selectionKey]);
  const shownError = submitError && submitError.key === selectionKey ? submitError : null;
  const errorMessage = shownError?.message ?? null;

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
      const created = await onSubmitRequest({
        relationshipId: requestParams.relationshipId,
        date: requestParams.date,
        startTime: requestParams.startTime,
        // Whole day: both sessions, booked as one visit.
        ...(requestParams.startTimes ? { startTimes: requestParams.startTimes } : {}),
      });
      clearSelection();
      setSubmitted({
        ...chosen,
        visitId: created?.id ? String(created.id) : null,
        // Said only when the backend's own answer is `confirmed`.
        confirmed: created?.status === 'confirmed',
      });
      // The slot's remaining capacity changed; show the backend's view of it.
      reload();
    } catch (e) {
      // The backend refused a booking past the weekly limit: say so plainly.
      setSubmitError(
        isWeeklyLimitError(e)
          ? {
              key,
              title: WEEKLY_LIMIT_REACHED_TITLE,
              message: weeklyLimitReachedNotice(weeklyLimit?.limit, chosen.date),
            }
          : {
              key,
              title: null,
              message:
                e instanceof Error && e.message.trim()
                  ? e.message
                  : 'Could not schedule your visit. Please try again.',
            },
      );
      // A rejection (e.g. the weekly limit was reached meanwhile) means the
      // loaded availability is stale; refresh the calendar and weekly counter.
      reload({ silent: true });
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  };
  // Null while a date is bookable; same rule as the Home calendar. Read from
  // the loaded days only (not `loading`), so it matches the calendar shown.
  const availabilityNotice = useMemo(
    () => noAvailabilityNotice(relationship, weeklyLimit),
    [relationship, weeklyLimit],
  );
  // The week the counter shows: the chosen date's, else the first bookable
  // date's (a full current week would hide that the next one is open), else
  // this week's.
  const counterDate = selectedDate ?? firstBookableDate(daysByDate) ?? today;
  // The weekly counter under "Choose a Visit Date" already explains this week's limit.
  const weeklyLimitAlreadyShown =
    Boolean(weeklyUsageFor(weeklyLimit, counterDate)?.reached) &&
    noAvailabilityReason(relationship) === 'weekly_limit';
  // As on Home: dates blocked only by the weekly limit stay tappable and say why.
  const weeklyLimitDates = useMemo(() => {
    /** @type {Record<string, boolean>} */
    const out = {};
    for (const [date, day] of Object.entries(daysByDate)) {
      if (isWeeklyLimitDay(day)) out[date] = true;
    }
    return out;
  }, [daysByDate]);
  const onWeeklyLimitDatePress = (date) => {
    Alert.alert(WEEKLY_LIMIT_REACHED_TITLE, weeklyLimitReachedNotice(weeklyLimit?.limit, date), [
      { text: 'OK' },
    ]);
  };

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
          {RELATIONSHIP_NOT_VERIFIED_SCHEDULE}
        </Text>
      </View>
    );
  } else {
    info = (
      <>
        <RelationshipPicker
          relationships={relationships}
          selectedId={relationship.relationshipId}
          onSelect={selectRelationship}
        />
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
        ) : availabilityNotice && !weeklyLimitAlreadyShown ? (
          <Text style={styles.scheduleWarning}>{availabilityNotice}</Text>
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
            {/* Visitor-wide weekly usage for the chosen (else first bookable, else this) week. */}
            <WeeklyVisitCounter weeklyLimit={weeklyLimit} date={counterDate} />
            <VisitCalendar
              key={relationship.relationshipId}
              daysByDate={daysByDate}
              today={today}
              minDate={from}
              maxDate={to}
              selectedDate={selectedDate}
              onSelectDate={selectDate}
              blockedDates={weeklyLimitDates}
              onBlockedDatePress={onWeeklyLimitDatePress}
              compact
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
                  title="Schedule Visit"
                  onPress={handleSubmit}
                  loading={submitting}
                  accessibilityLabel={`Schedule visit: ${formatDateLong(selection.date)}, ${periodLabel(selection.period, selection.startTime)}`}
                />
              </View>
              {errorMessage ? (
                <View
                  style={styles.selectionError}
                  accessibilityLiveRegion="polite"
                  accessibilityRole="alert"
                >
                  <Ionicons name="alert-circle" size={20} color={colors.danger} />
                  <View style={styles.selectionErrorBody}>
                    {shownError?.title ? (
                      <Text style={styles.selectionErrorTitle}>{shownError.title}</Text>
                    ) : null}
                    <Text style={styles.selectionErrorText}>{errorMessage}</Text>
                  </View>
                </View>
              ) : null}
              <Text style={[styles.noVisit, styles.selectionNote]}>
                Your visit will be confirmed as soon as you schedule it, provided the selected
                slot is still available and you meet the visiting requirements.
              </Text>
            </Card>
          ) : submitted?.confirmed ? (
            <Card style={[styles.visitCard, styles.selectionCardConfirmed]}>
              <Text style={styles.sectionLabel}>Visit Confirmed!</Text>
              <Text style={styles.visitDate}>{formatDateLong(submitted.date)}</Text>
              <Text style={styles.visitTime}>
                {periodLabel(submitted.period, submitted.startTime)} ·{' '}
                {formatSlotRange(submitted.startTime, submitted.endTime)}
              </Text>
              <View
                style={styles.selectionConfirmed}
                accessibilityLiveRegion="polite"
                accessibilityLabel="Visit confirmed. Your visit has been scheduled."
              >
                <Ionicons name="checkmark-circle" size={20} color={colors.success} />
                <Text style={styles.selectionConfirmedText}>Visit Confirmed!</Text>
              </View>
              <Text style={[styles.noVisit, styles.selectionNote]}>
                {submitted.pdlName ? `Your visit with ${submitted.pdlName}` : 'Your visit'} has been
                successfully scheduled. Arrive 15 minutes early with a valid ID and your QR pass.
              </Text>
              {onViewQrPass && submitted.visitId ? (
                <TextLink
                  label="View QR Pass"
                  onPress={() => onViewQrPass(submitted.visitId)}
                  accessibilityLabel="View the QR pass for this visit"
                />
              ) : null}
              {onViewUpcoming ? (
                <TextLink
                  label="View in Upcoming"
                  onPress={onViewUpcoming}
                  accessibilityLabel="View this visit in Upcoming"
                />
              ) : null}
            </Card>
          ) : submitted ? (
            // Accepted, but the response did not say `confirmed` (unexpected):
            // no success claim, just where to check.
            <Card style={styles.visitCard}>
              <Text style={styles.sectionLabel}>Booking Received</Text>
              <Text style={styles.visitDate}>{formatDateLong(submitted.date)}</Text>
              <Text style={[styles.noVisit, styles.selectionNote]}>
                Check My Visits for the status of this visit.
              </Text>
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
    marginBottom: spacing.sm + spacing.xs,
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
  selectionErrorBody: {
    flexShrink: 1,
  },
  selectionErrorTitle: {
    ...typography.metadata,
    fontWeight: '700',
    color: colors.danger,
    marginBottom: spacing.xs,
  },
  selectionErrorText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.danger,
    flexShrink: 1,
  },
  pressed: { opacity: 0.88 },
});
