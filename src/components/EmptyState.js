import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { colors, layout, typography } from '../constants';

/**
 * Shared empty / informational panel with illustration or icon, title, message, and optional action.
 * @param {object} props
 * @param {string} props.title
 * @param {string} props.message
 * @param {'default' | 'error'} [props.emphasis] — `error` styles the message in **danger** (e.g. load failures).
 * @param {keyof typeof Ionicons.glyphMap} [props.iconName] — when set, shows icon instead of illustration.
 * @param {string} [props.iconColor]
 * @param {import('react-native').StyleProp<import('react-native').ViewStyle>} [props.style]
 * @param {React.ReactNode} [props.children] — e.g. Retry button
 * @param {import('react-native').AccessibilityProps['accessibilityRole']} [props.accessibilityRole]
 */
export default function EmptyState({
  title,
  message,
  emphasis = 'default',
  iconName,
  iconColor = colors.primary,
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
      <Text style={[typography.title, styles.title]}>{title}</Text>
      <Text style={messageStyle}>{message}</Text>
      {children ? <View style={styles.actions}>{children}</View> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: layout.spacing.xl,
    paddingHorizontal: layout.spacing.lg,
  },
  img: {
    width: 160,
    height: 160,
    marginBottom: layout.spacing.md,
  },
  iconCircle: {
    width: 96,
    height: 96,
    borderRadius: 48,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.borderLight,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: layout.spacing.md,
  },
  title: {
    color: colors.textPrimary,
    textAlign: 'center',
    marginBottom: layout.spacing.sm,
  },
  message: {
    color: colors.textSecondary,
    textAlign: 'center',
    lineHeight: 22,
    maxWidth: 320,
  },
  messageError: {
    color: colors.danger,
  },
  actions: {
    marginTop: layout.spacing.md,
    alignSelf: 'stretch',
    maxWidth: 280,
    width: '100%',
  },
});
