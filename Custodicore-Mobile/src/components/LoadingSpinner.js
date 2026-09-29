import React from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { colors, layout, spacing, typography } from '../designSystem';

/**
 * @param {object} [props]
 * @param {string} [props.message]
 * @param {boolean} [props.compact]
 */
export default function LoadingSpinner({ message, compact = false }) {
  return (
    <View
      style={[styles.center, compact && styles.compact]}
      accessibilityRole="progressbar"
      accessibilityLabel={message ?? 'Loading'}
    >
      <ActivityIndicator size="large" color={colors.primaryTeal} />
      {message ? (
        <Text style={[typography.metadata, styles.message]}>{message}</Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: layout.screenPadding,
    paddingVertical: spacing.lg,
    gap: spacing.md,
    minHeight: spacing.xl * 6,
  },
  compact: {
    minHeight: spacing.xl * 4,
    paddingVertical: spacing.md,
  },
  message: {
    color: colors.textSecondary,
    textAlign: 'center',
  },
});
