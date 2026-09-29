import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, shadows, spacing, typography } from '../designSystem';
import { formatDate, formatTime } from '../utils';

const GRAY_UPCOMING = '#9CA3AF';

/**
 * Single row in the BJMP visit tracking timeline (v2.1).
 * Courier-style vertical step — Shopee / Lazada order tracking inspired.
 *
 * @param {object} props
 * @param {'completed' | 'current' | 'pending'} props.stepState
 * @param {string} props.title
 * @param {string} props.description
 * @param {string | null} [props.occurredAt]
 * @param {string | null} [props.officerNote]
 * @param {boolean} [props.isLast]
 * @param {boolean} [props.carded] — per-step white card (courier / parcel tracking layout)
 */
export default function VisitTimelineStep({
  stepState,
  title,
  description,
  occurredAt,
  officerNote,
  isLast = false,
  carded = false,
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

  const content = (
    <>
      <View style={styles.titleRow}>
        <Text
          style={[
            styles.title,
            isPending && styles.titlePending,
            isCurrent && styles.titleCurrent,
            isCompleted && styles.titleCompleted,
          ]}
        >
          {title}
        </Text>
        {isCurrent ? (
          <View style={styles.currentBadge}>
            <Text style={styles.currentBadgeText}>In progress</Text>
          </View>
        ) : null}
      </View>

      {whenLine ? (
        <Text
          style={[
            styles.timestamp,
            isCompleted && styles.timestampCompleted,
            isCurrent && styles.timestampCurrent,
            isPending && styles.textMuted,
          ]}
        >
          {whenLine}
        </Text>
      ) : isPending ? (
        <Text style={styles.awaiting}>Awaiting update</Text>
      ) : null}

      {description ? (
        <Text
          style={[
            styles.description,
            isPending && styles.textMuted,
            isCurrent && styles.descriptionCurrent,
          ]}
        >
          {description}
        </Text>
      ) : null}

      {officerNote ? (
        <View style={styles.noteWrap}>
          <View style={styles.noteHeader}>
            <Ionicons name="document-text-outline" size={14} color={colors.textSecondary} />
            <Text style={styles.noteLabel}>Officer Note</Text>
          </View>
          <Text style={styles.noteBody}>{officerNote}</Text>
        </View>
      ) : null}
    </>
  );

  return (
    <View style={styles.row} accessibilityRole="summary">
      <View style={[styles.track, carded && styles.trackCarded]}>
        {isCompleted ? (
          <View style={styles.dotCompleted} accessibilityLabel="Completed step">
            <Ionicons name="checkmark" size={14} color={colors.white} />
          </View>
        ) : isCurrent ? (
          <View style={styles.dotCurrentRing} accessibilityLabel="Current step">
            <View style={styles.dotCurrentInner} />
          </View>
        ) : (
          <View style={styles.dotPending} accessibilityLabel="Upcoming step" />
        )}
        {!isLast ? (
          <View
            style={[
              styles.line,
              carded && styles.lineCarded,
              { backgroundColor: lineColor },
            ]}
          />
        ) : null}
      </View>

      <View style={[styles.body, carded && styles.bodyCarded]}>
        {carded ? (
          <View
            style={[
              styles.contentCard,
              isCurrent && styles.contentCardCurrent,
              isPending && styles.contentCardPending,
              isCompleted && styles.contentCardCompleted,
            ]}
          >
            {content}
          </View>
        ) : (
          content
        )}
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
    marginRight: spacing.sm,
    alignSelf: 'stretch',
  },
  trackCarded: {
    width: 36,
    marginRight: spacing.sm,
  },
  dotCompleted: {
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: colors.success,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 1,
  },
  dotCurrentRing: {
    width: 28,
    height: 28,
    borderRadius: 14,
    borderWidth: 2.5,
    borderColor: colors.primaryTeal,
    backgroundColor: colors.white,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 1,
  },
  dotCurrentInner: {
    width: 12,
    height: 12,
    borderRadius: 6,
    backgroundColor: colors.primaryTeal,
  },
  dotPending: {
    width: 28,
    height: 28,
    borderRadius: 14,
    borderWidth: 2,
    borderColor: GRAY_UPCOMING,
    backgroundColor: colors.white,
    zIndex: 1,
  },
  line: {
    flex: 1,
    width: 3,
    marginTop: spacing.xs,
    minHeight: spacing.md,
    borderRadius: 2,
  },
  lineCarded: {
    minHeight: spacing.lg,
  },
  body: {
    flex: 1,
    paddingBottom: spacing.md,
  },
  bodyCarded: {
    paddingBottom: spacing.md,
  },
  contentCard: {
    backgroundColor: colors.card,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: colors.border,
    padding: spacing.md,
    ...shadows.card,
  },
  contentCardCompleted: {
    borderColor: 'rgba(22, 163, 74, 0.25)',
  },
  contentCardCurrent: {
    borderColor: colors.primaryTeal,
    borderWidth: 1.5,
    backgroundColor: 'rgba(13, 165, 138, 0.06)',
  },
  contentCardPending: {
    backgroundColor: colors.card,
    borderColor: colors.border,
    opacity: 0.92,
  },
  titleRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: spacing.sm,
  },
  title: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  titleCompleted: {
    color: colors.textPrimary,
  },
  titleCurrent: {
    color: colors.primaryNavy,
  },
  titlePending: {
    color: GRAY_UPCOMING,
    fontWeight: '500',
  },
  currentBadge: {
    backgroundColor: 'rgba(13, 165, 138, 0.12)',
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
    borderRadius: 999,
  },
  currentBadgeText: {
    ...typography.metadata,
    fontSize: 11,
    fontWeight: '700',
    color: colors.primaryTeal,
    textTransform: 'uppercase',
    letterSpacing: 0.3,
  },
  timestamp: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.xs,
  },
  timestampCompleted: {
    color: colors.success,
    fontWeight: '500',
  },
  timestampCurrent: {
    color: colors.primaryTeal,
    fontWeight: '500',
  },
  awaiting: {
    ...typography.metadata,
    color: GRAY_UPCOMING,
    marginTop: spacing.xs,
    fontStyle: 'italic',
  },
  description: {
    ...typography.metadata,
    color: colors.textPrimary,
    marginTop: spacing.sm,
    lineHeight: 18,
  },
  descriptionCurrent: {
    color: colors.textPrimary,
  },
  textMuted: {
    color: GRAY_UPCOMING,
  },
  noteWrap: {
    marginTop: spacing.sm,
    padding: spacing.sm,
    borderRadius: 12,
    backgroundColor: colors.background,
    borderWidth: 1,
    borderColor: colors.border,
  },
  noteHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    marginBottom: spacing.xs,
  },
  noteLabel: {
    ...typography.statusLabel,
    color: colors.textSecondary,
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  noteBody: {
    ...typography.metadata,
    color: colors.textPrimary,
    lineHeight: 18,
  },
});
