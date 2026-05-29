import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, spacing, typography } from '../designSystem';
import { formatDate, formatTime } from '../utils';

/**
 * Single row in the BJMP visit tracking timeline (v2.1).
 * @param {object} props
 * @param {'completed' | 'current' | 'pending'} props.stepState
 * @param {string} props.title
 * @param {string} props.description
 * @param {string | null} [props.occurredAt]
 * @param {string | null} [props.officerNote]
 * @param {boolean} [props.isLast]
 */
export default function VisitTimelineStep({
  stepState,
  title,
  description,
  occurredAt,
  officerNote,
  isLast = false,
}) {
  const isCompleted = stepState === 'completed';
  const isCurrent = stepState === 'current';
  const isPending = stepState === 'pending';

  const whenLine = (() => {
    if (!occurredAt) return '';
    const datePart = formatDate(occurredAt);
    const timePart = formatTime(occurredAt);
    return [datePart, timePart].filter(Boolean).join(' · ');
  })();

  const lineColor = isCompleted ? colors.success : colors.border;
  const dotCompleted = isCompleted;
  const dotCurrent = isCurrent;

  return (
    <View style={styles.row} accessibilityRole="summary">
      <View style={styles.track}>
        {dotCompleted ? (
          <View style={styles.dotCompleted}>
            <Ionicons name="checkmark" size={14} color={colors.white} />
          </View>
        ) : dotCurrent ? (
          <View style={styles.dotCurrentRing}>
            <View style={styles.dotCurrentInner} />
          </View>
        ) : (
          <View style={styles.dotPending} />
        )}
        {!isLast ? <View style={[styles.line, { backgroundColor: lineColor }]} /> : null}
      </View>

      <View style={styles.body}>
        <Text
          style={[
            styles.title,
            isPending && styles.titlePending,
            isCurrent && styles.titleCurrent,
          ]}
        >
          {title}
        </Text>

        {whenLine ? (
          <Text style={[styles.timestamp, isPending && styles.textMuted]}>{whenLine}</Text>
        ) : isPending ? (
          <Text style={styles.awaiting}>Awaiting update</Text>
        ) : null}

        <Text style={[styles.description, isPending && styles.textMuted]}>{description}</Text>

        {officerNote ? (
          <View style={styles.noteWrap}>
            <Text style={styles.noteLabel}>Officer Note</Text>
            <Text style={styles.noteBody}>{officerNote}</Text>
          </View>
        ) : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
  },
  track: {
    width: 32,
    alignItems: 'center',
    marginRight: spacing[12],
  },
  dotCompleted: {
    width: 26,
    height: 26,
    borderRadius: 13,
    backgroundColor: colors.success,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dotCurrentRing: {
    width: 26,
    height: 26,
    borderRadius: 13,
    borderWidth: 2,
    borderColor: colors.primaryTeal,
    backgroundColor: colors.white,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dotCurrentInner: {
    width: 10,
    height: 10,
    borderRadius: 5,
    backgroundColor: colors.primaryTeal,
  },
  dotPending: {
    width: 26,
    height: 26,
    borderRadius: 13,
    borderWidth: 2,
    borderColor: colors.border,
    backgroundColor: colors.background,
  },
  line: {
    flex: 1,
    width: 2,
    marginTop: spacing[4],
    minHeight: spacing[16],
  },
  body: {
    flex: 1,
    paddingBottom: spacing[20],
  },
  title: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  titleCurrent: {
    color: colors.primaryNavy,
  },
  titlePending: {
    color: colors.textSecondary,
    fontWeight: '500',
  },
  timestamp: {
    ...typography.caption,
    color: colors.textSecondary,
    marginTop: spacing[4],
  },
  awaiting: {
    ...typography.caption,
    color: colors.textSecondary,
    marginTop: spacing[4],
    fontStyle: 'italic',
  },
  description: {
    ...typography.caption,
    color: colors.textPrimary,
    marginTop: spacing[8],
    lineHeight: 18,
  },
  textMuted: {
    color: colors.textSecondary,
  },
  noteWrap: {
    marginTop: spacing[12],
    padding: spacing[12],
    borderRadius: 12,
    backgroundColor: colors.background,
    borderWidth: 1,
    borderColor: colors.border,
  },
  noteLabel: {
    ...typography.statusLabel,
    color: colors.textSecondary,
    textTransform: 'uppercase',
    letterSpacing: 0.4,
    marginBottom: spacing[4],
  },
  noteBody: {
    ...typography.caption,
    color: colors.textPrimary,
    lineHeight: 18,
  },
});
