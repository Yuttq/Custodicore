import React from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { colors, layout, typography } from '../constants';

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
      <ActivityIndicator size="large" color={colors.primary} />
      {message ? (
        <Text style={[typography.body, styles.message]}>{message}</Text>
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
    paddingVertical: layout.spacing.lg,
    gap: layout.spacing.md,
    minHeight: 200,
  },
  compact: {
    minHeight: 120,
    paddingVertical: layout.spacing.md,
  },
  message: {
    color: colors.textSecondary,
    textAlign: 'center',
  },
});
