import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, layout, typography } from '../constants';

const LABELS = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  suspended: 'Suspended',
  confirmed: 'Confirmed',
  cancelled: 'Cancelled',
  no_show: 'No show',
  checked_in: 'Checked in',
  checked_out: 'Checked out',
};

const STATUS_STYLES = {
  pending: { bg: colors.warning, fg: colors.textPrimary },
  approved: { bg: colors.success, fg: colors.white },
  rejected: { bg: colors.danger, fg: colors.white },
  suspended: { bg: colors.restricted, fg: colors.white },
  confirmed: { bg: colors.primary, fg: colors.white },
  cancelled: { bg: colors.textSecondary, fg: colors.white },
  no_show: { bg: colors.danger, fg: colors.white },
  checked_in: { bg: colors.success, fg: colors.white },
  checked_out: { bg: colors.primary, fg: colors.white },
};

/**
 * @param {{ status: keyof typeof LABELS }} props
 */
export default function StatusBadge({ status }) {
  const key = status in LABELS ? status : 'pending';
  const { bg, fg } = STATUS_STYLES[key];
  const label = LABELS[key];

  return (
    <View
      accessibilityRole="text"
      accessibilityLabel={`Status: ${label}`}
      style={[styles.badge, { backgroundColor: bg }]}
    >
      <Text style={[typography.caption, styles.text, { color: fg }]}>
        {label}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: {
    alignSelf: 'flex-start',
    paddingHorizontal: layout.spacing.sm + 2,
    paddingVertical: layout.spacing.xs + 1,
    borderRadius: layout.borderRadiusSm,
    borderWidth: 1,
    borderColor: 'rgba(15, 23, 42, 0.12)',
  },
  text: { fontWeight: '600', letterSpacing: 0.2 },
});
