import { Ionicons } from '@expo/vector-icons';
import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, layout, typography } from '../constants';
import { formatDate, formatTime } from '../utils';

/** @type {Record<string, string>} */
const STATUS_ICONS = {
  assigned: 'calendar-outline',
  pending: 'time-outline',
  confirmed: 'checkmark-done-circle-outline',
  approved: 'shield-checkmark-outline',
  checked_in: 'person-outline',
  checked_out: 'flag-outline',
  rejected: 'close-circle-outline',
  cancelled: 'ban-outline',
  no_show: 'alert-circle-outline',
  suspended: 'warning-outline',
  default: 'ellipse-outline',
};

function iconNameForStatus(status) {
  const key = String(status || '')
    .toLowerCase()
    .replace(/-/g, '_');
  return STATUS_ICONS[key] ?? STATUS_ICONS.default;
}

function iconColorForStatus(status) {
  const key = String(status || '')
    .toLowerCase()
    .replace(/-/g, '_');
  if (['checked_in', 'approved', 'confirmed', 'checked_out'].includes(key)) {
    return key === 'checked_out' ? colors.primary : colors.success;
  }
  if (['rejected', 'cancelled', 'no_show'].includes(key)) return colors.danger;
  if (['pending', 'assigned', 'suspended'].includes(key)) return colors.warning;
  return colors.primary;
}

/**
 * @param {'completed' | 'current' | 'pending'} stepState
 */
function lineStyleAfterNode(stepState) {
  if (stepState === 'completed') {
    return { backgroundColor: colors.primary };
  }
  return { backgroundColor: colors.border };
}

/**
 * @param {'default' | 'warning' | 'danger'} [tone]
 */
function toneColors(tone) {
  if (tone === 'danger') {
    return { fg: colors.danger, bg: 'rgba(239, 68, 68, 0.08)', border: 'rgba(239, 68, 68, 0.35)' };
  }
  if (tone === 'warning') {
    return { fg: colors.warning, bg: 'rgba(245, 158, 11, 0.08)', border: 'rgba(245, 158, 11, 0.4)' };
  }
  return { fg: colors.primary, bg: 'rgba(30, 58, 95, 0.06)', border: 'rgba(30, 58, 95, 0.22)' };
}

/**
 * One row in a vertical timeline.
 *
 * **Event mode** (default): icon + status-colored track for API / audit-style logs (`TimelineScreen`).
 *
 * **Progress mode**: parcel-style visit stages — navy checks + solid line for completed,
 * emphasized card for current, muted pending.
 *
 * @param {object} props
 * @param {'event' | 'progress'} [props.variant='event']
 * --- event ---
 * @param {string} props.status — backend / visit step key (e.g. `checked_in`)
 * @param {string} props.title
 * @param {string} [props.description]
 * @param {string | null} [props.occurredAt]
 * @param {boolean} [props.isLast]
 * --- progress ---
 * @param {'completed' | 'current' | 'pending'} [props.stepState]
 * @param {string | null} [props.staffNote]
 * @param {'default' | 'warning' | 'danger'} [props.markerTone] — optional emphasis for current / alerts
 */
export default function TimelineItem({
  variant = 'event',
  status,
  title,
  description,
  occurredAt,
  isLast = false,
  stepState,
  staffNote,
  markerTone = 'default',
}) {
  if (variant === 'progress') {
    const state = stepState ?? 'pending';
    const whenLine = (() => {
      if (!occurredAt) return '';
      const datePart = formatDate(occurredAt);
      const timePart = formatTime(occurredAt);
      return [datePart, timePart].filter(Boolean).join(' · ');
    })();

    const lineAfter = lineStyleAfterNode(state);
    const isMuted = state === 'pending';
    const tone = state === 'current' ? toneColors(markerTone) : toneColors('default');

    return (
      <View style={styles.progressRow} accessibilityRole="summary">
        <View style={styles.progressTrack}>
          {state === 'completed' ? (
            <View style={[styles.progressDot, styles.progressDotCompleted]} accessibilityLabel="Completed step">
              <Ionicons name="checkmark" size={14} color={colors.white} />
            </View>
          ) : state === 'current' ? (
            <View
              style={[
                styles.progressDotCurrentRing,
                markerTone !== 'default' && { borderColor: tone.fg },
              ]}
              accessibilityLabel="Current step"
            >
              <View
                style={[
                  styles.progressDotCurrentInner,
                  markerTone !== 'default' && { backgroundColor: tone.fg },
                ]}
              />
            </View>
          ) : (
            <View style={styles.progressDotPending} accessibilityLabel="Pending step" />
          )}
          {!isLast ? <View style={[styles.progressLine, lineAfter]} /> : null}
        </View>

        <View style={styles.progressBody}>
          <View
            style={[
              styles.progressCard,
              state === 'current' && [
                styles.progressCardCurrent,
                {
                  backgroundColor: tone.bg,
                  borderColor: tone.border,
                },
              ],
              isMuted && styles.progressCardPending,
            ]}
          >
            <Text
              style={[
                typography.bodyStrong,
                styles.progressTitle,
                isMuted && styles.progressTitleMuted,
              ]}
            >
              {title}
            </Text>
            {whenLine ? (
              <Text
                style={[
                  typography.caption,
                  styles.progressWhen,
                  isMuted && styles.progressWhenMuted,
                ]}
              >
                {whenLine}
              </Text>
            ) : state === 'pending' ? (
              <Text style={[typography.caption, styles.progressWhenMuted]}>Awaiting update</Text>
            ) : null}
            {description ? (
              <Text
                style={[
                  typography.caption,
                  styles.progressDesc,
                  isMuted && styles.progressDescMuted,
                ]}
              >
                {description}
              </Text>
            ) : null}
            {staffNote ? (
              <View style={styles.staffNoteWrap}>
                <Text style={[typography.meta, styles.staffNoteLabel]}>FACILITY NOTE</Text>
                <Text style={[typography.caption, styles.staffNoteBody]}>{staffNote}</Text>
              </View>
            ) : null}
          </View>
        </View>
      </View>
    );
  }

  const icon = iconNameForStatus(status);
  const iconColor = iconColorForStatus(status);
  const datePart = occurredAt ? formatDate(occurredAt) : '';
  const timePart = occurredAt ? formatTime(occurredAt) : '';
  const whenLine = [datePart, timePart].filter(Boolean).join(' · ');

  return (
    <View style={styles.row} accessibilityRole="summary">
      <View style={styles.track}>
        <View
          style={[styles.iconBubble, { borderColor: iconColor }]}
          accessibilityLabel={`Status: ${status}`}
        >
          <Ionicons name={icon} size={22} color={iconColor} />
        </View>
        {!isLast ? <View style={styles.line} /> : null}
      </View>
      <View style={styles.body}>
        <Text style={[typography.body, styles.title]}>{title}</Text>
        {whenLine ? (
          <Text style={[typography.caption, styles.when]}>{whenLine}</Text>
        ) : null}
        {description ? (
          <Text style={[typography.caption, styles.desc]}>{description}</Text>
        ) : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    minHeight: 72,
  },
  track: {
    width: 44,
    alignItems: 'center',
  },
  iconBubble: {
    width: 40,
    height: 40,
    borderRadius: 20,
    borderWidth: 2,
    backgroundColor: colors.white,
    alignItems: 'center',
    justifyContent: 'center',
  },
  line: {
    flex: 1,
    width: 2,
    marginTop: 2,
    backgroundColor: colors.border,
    minHeight: layout.spacing.md,
  },
  body: {
    flex: 1,
    paddingBottom: layout.spacing.lg,
    paddingLeft: layout.spacing.sm,
  },
  title: {
    color: colors.textPrimary,
    fontWeight: '600',
  },
  when: {
    color: colors.textSecondary,
    marginTop: layout.spacing.xs,
  },
  desc: {
    color: colors.textSecondary,
    marginTop: layout.spacing.xs,
    lineHeight: 18,
  },

  progressRow: {
    flexDirection: 'row',
    alignItems: 'stretch',
  },
  progressTrack: {
    width: 28,
    alignItems: 'center',
    marginRight: layout.spacing.sm,
  },
  progressDot: {
    width: 24,
    height: 24,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  progressDotCompleted: {
    backgroundColor: colors.primary,
  },
  progressDotCurrentRing: {
    width: 24,
    height: 24,
    borderRadius: 12,
    borderWidth: 2,
    borderColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.white,
  },
  progressDotCurrentInner: {
    width: 10,
    height: 10,
    borderRadius: 5,
    backgroundColor: colors.primary,
  },
  progressDotPending: {
    width: 24,
    height: 24,
    borderRadius: 12,
    borderWidth: 2,
    borderColor: colors.border,
    backgroundColor: colors.white,
  },
  progressLine: {
    flex: 1,
    width: 2,
    marginTop: 4,
    minHeight: layout.spacing.md + layout.spacing.xs,
  },
  progressBody: {
    flex: 1,
    paddingBottom: layout.spacing.md,
  },
  progressCard: {
    borderRadius: layout.borderRadius,
    borderWidth: 1,
    borderColor: 'transparent',
    paddingVertical: layout.spacing.sm,
    paddingHorizontal: layout.spacing.sm,
  },
  progressCardCurrent: {
    borderWidth: 1,
  },
  progressCardPending: {
    opacity: 0.92,
  },
  progressTitle: {
    color: colors.textPrimary,
  },
  progressTitleMuted: {
    color: colors.textMuted,
    fontWeight: '500',
  },
  progressWhen: {
    color: colors.textSecondary,
    marginTop: layout.spacing.xs,
  },
  progressWhenMuted: {
    color: colors.textMuted,
  },
  progressDesc: {
    color: colors.textSecondary,
    marginTop: layout.spacing.xs,
    lineHeight: 18,
  },
  progressDescMuted: {
    color: colors.textMuted,
  },
  staffNoteWrap: {
    marginTop: layout.spacing.sm,
    paddingTop: layout.spacing.sm,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.borderLight,
  },
  staffNoteLabel: {
    color: colors.textMuted,
    textTransform: 'uppercase',
    marginBottom: layout.spacing.xs,
  },
  staffNoteBody: {
    color: colors.textSecondary,
    lineHeight: 17,
    fontStyle: 'italic',
  },
});
