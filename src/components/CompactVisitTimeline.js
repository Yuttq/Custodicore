import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, spacing, typography } from '../designSystem';
import { formatDate, formatTime } from '../utils';

const GRAY_UPCOMING = '#9CA3AF';

/**
 * @param {string | null | undefined} officerNote
 */
function extractOfficerName(officerNote) {
  if (!officerNote?.trim()) return null;
  const byMatch = officerNote.match(/\bby\s+((?:Duty\s+)?Officer\s+[\w.\s]+)/i);
  if (byMatch?.[1]) return byMatch[1].trim().replace(/\.$/, '');
  const prefixMatch = officerNote.match(/^([\w\s]+Officer[\w\s.]*):/i);
  if (prefixMatch?.[1]) return prefixMatch[1].trim();
  return null;
}

/**
 * @param {import('../utils/visitProgressSnapshot').CompactVisitStep} step
 */
function getStepDetailFields(step) {
  const date = step.occurredAt ? formatDate(step.occurredAt) : null;
  const time = step.occurredAt ? formatTime(step.occurredAt) : null;
  const officer = extractOfficerName(step.officerNote);
  const remarkParts = [step.description?.trim(), step.officerNote?.trim()].filter(Boolean);
  const remarks = remarkParts.length > 0 ? remarkParts.join('\n\n') : null;

  return {
    date: date || '—',
    time: time || '—',
    officer: officer || '—',
    remarks: remarks || '—',
  };
}

/**
 * @param {{ label: string; value: string }} props
 */
function DetailRow({ label, value }) {
  return (
    <View style={styles.detailRow}>
      <Text style={styles.detailLabel}>{label}</Text>
      <Text style={styles.detailValue}>{value}</Text>
    </View>
  );
}

/**
 * @param {object} props
 * @param {import('../utils/visitProgressSnapshot').CompactVisitStep} props.step
 * @param {boolean} props.isLast
 * @param {boolean} props.expanded
 * @param {() => void} props.onToggle
 */
function CompactTimelineStep({ step, isLast, expanded, onToggle }) {
  const isCompleted = step.stepState === 'completed';
  const isCurrent = step.stepState === 'current';
  const isPending = step.stepState === 'pending';
  const details = getStepDetailFields(step);

  const lineColor = isCompleted ? colors.success : colors.border;

  return (
    <View style={styles.row}>
      <View style={styles.track}>
        {isCompleted ? (
          <View style={styles.dotDone}>
            <Ionicons name="checkmark" size={12} color={colors.white} />
          </View>
        ) : isCurrent ? (
          <View style={styles.dotCurrent}>
            <View style={styles.dotCurrentInner} />
          </View>
        ) : (
          <View style={styles.dotPending} />
        )}
        {!isLast ? <View style={[styles.line, { backgroundColor: lineColor }]} /> : null}
      </View>

      <View style={[styles.body, !isLast && styles.bodySpaced]}>
        <Pressable
          onPress={onToggle}
          style={({ pressed }) => [styles.headerPress, pressed && styles.pressed]}
          accessibilityRole="button"
          accessibilityState={{ expanded }}
          accessibilityLabel={`${step.label}. ${step.stepState}. Tap for details`}
        >
          <Text
            style={[
              styles.label,
              isCompleted && styles.labelDone,
              isCurrent && styles.labelCurrent,
              isPending && styles.labelPending,
            ]}
          >
            {step.label}
          </Text>
          <Ionicons
            name={expanded ? 'chevron-up' : 'chevron-down'}
            size={16}
            color={colors.textSecondary}
          />
        </Pressable>

        {expanded ? (
          <View style={styles.details}>
            <DetailRow label="Date" value={details.date} />
            <DetailRow label="Time" value={details.time} />
            <DetailRow label="Officer" value={details.officer} />
            <DetailRow label="Remarks" value={details.remarks} />
          </View>
        ) : null}
      </View>
    </View>
  );
}

/**
 * Compact Shopee-style visit progress timeline with collapsible step details.
 *
 * @param {object} props
 * @param {import('../utils/visitProgressSnapshot').CompactVisitStep[]} props.steps
 * @param {import('react-native').StyleProp<import('react-native').ViewStyle>} [props.style]
 */
export default function CompactVisitTimeline({ steps, style }) {
  const [expandedIds, setExpandedIds] = useState(() => new Set());

  const toggleStep = useCallback((id) => {
    setExpandedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);

  if (steps.length === 0) {
    return (
      <Text style={styles.empty}>Progress updates will appear here when recorded.</Text>
    );
  }

  return (
    <View style={[styles.panel, style]}>
      {steps.map((step, index) => (
        <CompactTimelineStep
          key={step.id}
          step={step}
          isLast={index === steps.length - 1}
          expanded={expandedIds.has(step.id)}
          onToggle={() => toggleStep(step.id)}
        />
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  panel: {
    backgroundColor: colors.card,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.sm,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
  },
  track: {
    width: 24,
    alignItems: 'center',
    marginRight: spacing.sm,
    alignSelf: 'stretch',
  },
  dotDone: {
    width: 22,
    height: 22,
    borderRadius: 11,
    backgroundColor: colors.success,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 1,
  },
  dotCurrent: {
    width: 22,
    height: 22,
    borderRadius: 11,
    borderWidth: 2,
    borderColor: colors.primaryTeal,
    backgroundColor: colors.white,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 1,
  },
  dotCurrentInner: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.primaryTeal,
  },
  dotPending: {
    width: 22,
    height: 22,
    borderRadius: 11,
    borderWidth: 2,
    borderColor: GRAY_UPCOMING,
    backgroundColor: colors.white,
    zIndex: 1,
  },
  line: {
    flex: 1,
    width: 2,
    marginTop: spacing.xs,
    minHeight: spacing.sm,
    borderRadius: 1,
  },
  body: {
    flex: 1,
  },
  bodySpaced: {
    paddingBottom: spacing.sm,
  },
  headerPress: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: spacing.sm,
  },
  pressed: { opacity: 0.88 },
  label: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  labelDone: {
    color: colors.textPrimary,
  },
  labelCurrent: {
    color: colors.primaryNavy,
    fontWeight: '700',
  },
  labelPending: {
    color: GRAY_UPCOMING,
    fontWeight: '500',
  },
  details: {
    marginTop: spacing.sm,
    paddingTop: spacing.sm,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
    gap: spacing.sm,
  },
  detailRow: {
    gap: spacing.xs,
  },
  detailLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
    fontWeight: '600',
  },
  detailValue: {
    ...typography.metadata,
    color: colors.textPrimary,
    lineHeight: 18,
  },
  empty: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
    paddingVertical: spacing.md,
  },
});
