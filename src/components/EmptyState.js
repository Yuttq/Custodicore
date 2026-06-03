import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { colors, layout, spacing, typography } from '../designSystem';

/**
 * Shared empty / informational panel with illustration or icon, title, message, and optional action.
 */
export default function EmptyState({
  title,
  message,
  emphasis = 'default',
  iconName,
  iconColor = colors.primaryTeal,
  style,
  children,
  accessibilityRole = 'text',
}) {
  const messageStyle =
    emphasis === 'error'
      ? [typography.body, styles.message, styles.messageError]
      : [typography.body, styles.message];

  return (
    <View style={[styles.wrap, style]} accessibilityRole={accessibilityRole}>
      {iconName ? (
        <View style={styles.iconCircle}>
          <Ionicons name={iconName} size={44} color={iconColor} />
        </View>
      ) : (
        <Image
          source={require('../assets/empty-state.png')}
          style={styles.img}
          resizeMode="contain"
          accessibilityIgnoresInvertColors
        />
      )}
      <Text style={[typography.pageTitle, styles.title]}>{title}</Text>
      <Text style={messageStyle}>{message}</Text>
      {children ? <View style={styles.actions}>{children}</View> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing.xl,
    paddingHorizontal: spacing.lg,
  },
  img: {
    width: spacing.xl * 5,
    height: spacing.xl * 5,
    marginBottom: spacing.md,
  },
  iconCircle: {
    width: spacing.xl * 3,
    height: spacing.xl * 3,
    borderRadius: spacing.xl * 1.5,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.md,
  },
  title: {
    color: colors.textPrimary,
    textAlign: 'center',
    marginBottom: spacing.sm,
  },
  message: {
    color: colors.textSecondary,
    textAlign: 'center',
    lineHeight: 24,
    maxWidth: spacing.xl * 10,
  },
  messageError: {
    color: colors.danger,
  },
  actions: {
    marginTop: spacing.md,
    alignSelf: 'stretch',
    maxWidth: spacing.xl * 9,
    width: '100%',
  },
});
