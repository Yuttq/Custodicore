import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { Button, Card, colors, layout, spacing, typography } from '../designSystem';
import TimeSlotList from './TimeSlotList';
import VisitCalendar from './VisitCalendar';
import {
  formatDateLong,
  formatSlotRange,
  formatVisitingDays,
  periodLabel,
  visitingWindows,
} from '../utils/scheduleAvailability';

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
 * date calendar and the time slots for the chosen date. Choosing (and
 * confirming) a slot reserves nothing — confirmation is local UI state only;
 * `schedule.requestParams` is handed to the Phase 4 visit-request screen.
 * @param {object} props
 * @param {ReturnType<typeof import('../hooks/useScheduleAvailability').default>} props.schedule
 */
export default function VisitationScheduleSection({ schedule }) {
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
    selectedSlotKey,
    selectSlot,
    selection,
  } = schedule;

  const windows = useMemo(() => visitingWindows(relationship), [relationship]);

  // Local, on-screen confirmation of the chosen slot. No API call and nothing
  // reserved; any change of slot/date/PDL drops it so a new choice must be
  // confirmed again.
  const selectionKey = selection
    ? `${selection.relationshipId}|${selection.date}|${selection.slotKey}`
    : null;
  const [confirmedKey, setConfirmedKey] = useState(/** @type {string | null} */ (null));
  useEffect(() => {
    if (confirmedKey && confirmedKey !== selectionKey) setConfirmedKey(null);
  }, [confirmedKey, selectionKey]);
  const isConfirmed = selectionKey !== null && confirmedKey === selectionKey;
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
                  selectedSlotKey={selectedSlotKey}
                  onSelectSlot={selectSlot}
                />
              </>
            ) : (
              <Text style={styles.noVisit}>Select an available date to see its time slots.</Text>
            )}
          </Card>

          {selection ? (
            <Card style={[styles.visitCard, isConfirmed && styles.selectionCardConfirmed]}>
              <Text style={styles.sectionLabel}>Selected Visit Slot</Text>
              <Text style={styles.visitDate}>{formatDateLong(selection.date)}</Text>
              <Text style={styles.visitTime}>
                {periodLabel(selection.period, selection.startTime)} ·{' '}
                {formatSlotRange(selection.startTime, selection.endTime)}
              </Text>
              {isConfirmed ? (
                <View
                  style={styles.selectionConfirmed}
                  accessibilityLiveRegion="polite"
                  accessibilityLabel="Selection confirmed on this screen. Not reserved."
                >
                  <Ionicons name="checkmark-circle" size={20} color={colors.success} />
                  <Text style={styles.selectionConfirmedText}>
                    Selection confirmed on this screen — not reserved
                  </Text>
                </View>
              ) : (
                <View style={styles.selectionAction}>
                  <Button
                    title="Confirm Selection"
                    onPress={() => setConfirmedKey(selectionKey)}
                    accessibilityLabel={`Confirm selection: ${formatDateLong(selection.date)}, ${periodLabel(selection.period, selection.startTime)}`}
                  />
                </View>
              )}
              <Text style={[styles.noVisit, styles.selectionNote]}>
                {isConfirmed
                  ? 'Your choice is confirmed on this screen only. The slot is not reserved and no visit request has been sent. Tap a different time slot to change it — you will need to confirm again. Visit requests will be available in an upcoming update.'
                  : 'Confirming only confirms your choice on this screen. It does not reserve the slot or send a visit request. Visit requests will be available in an upcoming update.'}
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
  pressed: { opacity: 0.88 },
});
