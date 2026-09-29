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
 * Compact notification list row — entire row opens details on tap.
 *
 * @param {object} props
 * @param {string} props.title
 * @param {string} props.description — short preview (1 line)
 * @param {string} props.dateLabel
 * @param {NotificationTone} props.tone
 * @param {boolean} props.read
 * @param {() => void} props.onPress
 */
export default function NotificationRow({
  title,
  description,
  dateLabel,
  tone,
  read,
  onPress,
}) {
  const toneStyle = TONE_CONFIG[tone] ?? TONE_CONFIG.information;

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
      accessibilityRole="button"
      accessibilityLabel={
        read
          ? `${title}. ${description}${dateLabel ? ` ${dateLabel}` : ''}. Tap for details.`
          : `Unread. ${title}. ${description}${dateLabel ? ` ${dateLabel}` : ''}. Tap for details.`
      }
    >
      {!read ? <View style={styles.unreadBar} accessibilityLabel="Unread" /> : null}

      <View style={[styles.iconWrap, { backgroundColor: toneStyle.bg }]}>
        <Ionicons name={toneStyle.icon} size={18} color={toneStyle.color} />
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
        <Text style={styles.description} numberOfLines={1}>
          {description}
        </Text>
      </View>

      {!read ? <View style={styles.unreadDot} /> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.md,
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
    marginBottom: spacing.xs,
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
  unreadDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: UNREAD_BLUE,
    flexShrink: 0,
  },
});
