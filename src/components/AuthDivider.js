import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, spacing, typography } from '../designSystem';

/**
 * Horizontal "OR" divider for stacked auth methods.
 */
export default function AuthDivider({ label = 'OR' }) {
  return (
    <View style={styles.wrap} accessibilityRole="text" accessibilityLabel={label}>
      <View style={styles.line} />
      <Text style={styles.label}>{label}</Text>
      <View style={styles.line} />
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: spacing.md,
    gap: spacing.md,
  },
  line: {
    flex: 1,
    height: StyleSheet.hairlineWidth,
    backgroundColor: colors.border,
    minWidth: spacing.lg,
  },
  label: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textSecondary,
    letterSpacing: 1,
    paddingHorizontal: spacing.sm,
    flexShrink: 0,
  },
});
