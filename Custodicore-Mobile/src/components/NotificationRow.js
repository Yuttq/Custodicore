import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, spacing, typography } from '../designSystem';

/** @typedef {'success' | 'warning' | 'information'} NotificationTone */

const UNREAD_BLUE = colors.info;

const TONE_CONFIG = {
  success: {
    icon: 'checkmark-circle',
    color: colors.success,
    bg: 'rgba(22, 163, 74, 0.12)',
  },
  warning: {
    icon: 'warning',
    color: colors.warning,
    bg: 'rgba(245, 158, 11, 0.14)',
  },
  information: {
    icon: 'information-circle',
    color: colors.info,
    bg: 'rgba(37, 99, 235, 0.12)',
  },
};

/**
 * Compact notification list row — entire row opens details on tap. A
 * rejection shows the staff reason in place of the preview, and an optional
 * small inline action (e.g. "Replace Document") under it.
 *
 * @param {object} props
 * @param {string} props.title
 * @param {string} props.description — short preview (1 line)
 * @param {string} props.dateLabel
 * @param {NotificationTone} props.tone
 * @param {boolean} props.read
 * @param {() => void} props.onPress
 * @param {string | null} [props.iconName] — type icon; defaults to the tone icon
 * @param {string | null} [props.reason] — staff reason (rejections)
 * @param {{ label: string; onPress: () => void } | null} [props.action]
 */
export default function NotificationRow({
  title,
  description,
  dateLabel,
  tone,
  read,
  onPress,
  iconName = null,
  reason = null,
  action = null,
}) {
  const toneStyle = TONE_CONFIG[tone] ?? TONE_CONFIG.information;
  const summary = reason ? `Reason: ${reason}` : description;

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
      accessibilityRole="button"
      accessibilityLabel={`${read ? '' : 'Unread. '}${title}. ${summary}${
        dateLabel ? ` ${dateLabel}` : ''
      }. Tap for details.`}
    >
      {!read ? <View style={styles.unreadBar} accessibilityLabel="Unread" /> : null}

      <View style={[styles.iconWrap, { backgroundColor: toneStyle.bg }]}>
        <Ionicons name={iconName || toneStyle.icon} size={18} color={toneStyle.color} />
      </View>

      <View style={styles.content}>
        <View style={styles.topLine}>
          <Text style={[styles.title, !read && styles.titleUnread]} numberOfLines={1}>
            {title}
          </Text>
          {dateLabel ? (
            <Text style={styles.date} numberOfLines={1}>
              {dateLabel}
            </Text>
          ) : null}
        </View>
        {reason ? (
          <Text style={styles.description} numberOfLines={2}>
            <Text style={styles.reasonLabel}>Reason: </Text>
            {reason}
          </Text>
        ) : (
          <Text style={styles.description} numberOfLines={1}>
            {description}
          </Text>
        )}
        {action ? (
          <Pressable
            onPress={action.onPress}
            hitSlop={8}
            style={({ pressed }) => [styles.action, pressed && styles.actionPressed]}
            accessibilityRole="button"
            accessibilityLabel={`${action.label}: ${title}`}
          >
            <Text style={styles.actionLabel}>{action.label}</Text>
            <Ionicons name="chevron-forward" size={14} color={colors.primaryTeal} />
          </Pressable>
        ) : null}
      </View>

      {!read ? <View style={styles.unreadDot} /> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.sm + spacing.xs,
    paddingHorizontal: spacing.md,
    backgroundColor: colors.card,
    gap: spacing.sm,
    minHeight: 56,
  },
  rowPressed: {
    backgroundColor: colors.background,
  },
  unreadBar: {
    position: 'absolute',
    left: 0,
    top: spacing.sm,
    bottom: spacing.sm,
    width: 3,
    borderRadius: 2,
    backgroundColor: UNREAD_BLUE,
  },
  iconWrap: {
    width: 32,
    height: 32,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    marginLeft: spacing.xs,
  },
  content: {
    flex: 1,
    minWidth: 0,
  },
  topLine: {
    flexDirection: 'row',
    alignItems: 'baseline',
    justifyContent: 'space-between',
    gap: spacing.sm,
    marginBottom: 2,
  },
  title: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  titleUnread: {
    color: colors.primaryNavy,
    fontWeight: '700',
  },
  date: {
    ...typography.metadata,
    color: colors.textSecondary,
    flexShrink: 0,
  },
  description: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  reasonLabel: {
    fontWeight: '600',
    color: colors.textPrimary,
  },
  action: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 2,
    marginTop: spacing.xs,
    paddingVertical: 2,
  },
  actionPressed: {
    opacity: 0.7,
  },
  actionLabel: {
    ...typography.metadata,
    fontWeight: '700',
    color: colors.primaryTeal,
  },
  unreadDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: UNREAD_BLUE,
    flexShrink: 0,
  },
});
