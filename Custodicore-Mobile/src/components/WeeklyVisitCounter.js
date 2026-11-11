import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, layout, spacing, typography } from '../designSystem';
import {
  formatWeekRange,
  WEEKLY_SLOTS_COUNTED_NOTE,
  weeklyLimitReachedMessage,
  weeklyUsageFor,
  weeklyUsageLabel,
} from '../utils/scheduleAvailability';

/**
 * "Week of November 16–22 · 1 of 2 weekly slots used" for the Monday–Sunday week
 * holding `date`. The count is the backend's (GET /api/schedules/availability
 * `weeklyLimit`) and covers ALL the visitor's linked PDLs together, so it
 * refreshes whenever availability reloads (e.g. after a request is sent).
 * At the limit it explains why no date that week can be chosen. Renders
 * nothing when the backend reported no usage for that week.
 *
 * @param {object} props
 * @param {{ limit: number; weeks: Record<string, number> } | null | undefined} props.weeklyLimit
 * @param {string | null | undefined} props.date YYYY-MM-DD — the week to show
 */
export default function WeeklyVisitCounter({ weeklyLimit, date }) {
  const usage = weeklyUsageFor(weeklyLimit, date);
  if (!usage) return null;

  const label = weeklyUsageLabel(usage);
  const range = formatWeekRange(usage.weekStart);

  return (
    <View
      style={[styles.box, usage.reached && styles.boxReached]}
      accessible
      accessibilityLabel={
        `Weekly slots, ${range}: ${label}, across all your linked PDLs.` +
        (usage.reached
          ? ` ${weeklyLimitReachedMessage(usage)} ${WEEKLY_SLOTS_COUNTED_NOTE}`
          : ` Up to ${usage.limit} a week, Monday to Sunday.`)
      }
    >
      <View style={styles.row}>
        <Ionicons
          name={usage.reached ? 'alert-circle-outline' : 'calendar-outline'}
          size={18}
          color={usage.reached ? colors.warningText : colors.primaryNavy}
        />
        <Text style={styles.week}>Week of {range}</Text>
        <Text style={[styles.count, usage.reached && styles.countReached]}>{label}</Text>
      </View>
      {usage.reached ? (
        <>
          <Text style={styles.reached}>{weeklyLimitReachedMessage(usage)}</Text>
          {/* Only here: a visitor at the limit may not have visited yet. */}
          <Text style={styles.note}>{WEEKLY_SLOTS_COUNTED_NOTE}</Text>
        </>
      ) : (
        <Text style={styles.note}>
          Up to {usage.limit} a week (Mon–Sun), across all your PDLs.
        </Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  box: {
    borderRadius: layout.buttonRadius,
    backgroundColor: 'rgba(15, 23, 42, 0.04)',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    marginBottom: spacing.sm,
  },
  boxReached: {
    backgroundColor: 'rgba(245, 158, 11, 0.12)',
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    flexWrap: 'wrap',
    gap: spacing.xs,
  },
  week: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  count: {
    ...typography.metadata,
    fontWeight: '700',
    color: colors.primaryNavy,
  },
  countReached: {
    color: colors.warningText,
  },
  note: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.xs,
  },
  reached: {
    ...typography.metadata,
    color: colors.warningText,
    marginTop: spacing.xs,
  },
});
