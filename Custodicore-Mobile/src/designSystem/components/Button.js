import React from 'react';
import {
    ActivityIndicator,
    Platform,
    Pressable,
    StyleSheet,
    Text,
} from 'react-native';
import { colors } from '../tokens/colors';
import { layout, spacing } from '../tokens/spacing';
import { typography } from '../tokens/typography';

const PRESS_FEEDBACK = Platform.select({
  android: { opacity: 0.88 },
  ios: { opacity: 0.9 },
  default: { opacity: 0.9 },
});

/** Per-variant colors; container/label styles are defined below. */
const VARIANTS = {
  primary: {
    container: 'primary',
    label: 'labelPrimary',
    ripple: 'rgba(255,255,255,0.25)',
    spinner: colors.white,
  },
  secondary: {
    container: 'secondary',
    label: 'labelSecondary',
    ripple: 'rgba(10,122,103,0.12)',
    spinner: colors.primaryTealDark,
  },
  destructive: {
    container: 'destructive',
    label: 'labelDestructive',
    ripple: 'rgba(255,255,255,0.25)',
    spinner: colors.white,
  },
};

/**
 * v2.1 Button
 * @param {object} props
 * @param {string} props.title
 * @param {() => void} props.onPress
 * @param {'primary'|'secondary'|'destructive'} [props.variant]
 * @param {boolean} [props.loading]
 * @param {boolean} [props.disabled]
 * @param {string} [props.accessibilityLabel]
 */
export function Button({
  title,
  onPress,
  variant = 'primary',
  loading = false,
  disabled = false,
  accessibilityLabel,
}) {
  const isDisabled = disabled || loading;
  // Unknown variants render as secondary, as before. Own-property check keeps
  // inherited keys such as "toString" or "__proto__" from matching.
  const v = Object.prototype.hasOwnProperty.call(VARIANTS, variant)
    ? VARIANTS[variant]
    : VARIANTS.secondary;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? title}
      accessibilityState={{ disabled: isDisabled }}
      onPress={onPress}
      disabled={isDisabled}
      android_ripple={isDisabled ? undefined : { color: v.ripple }}
      style={({ pressed }) => [
        styles.base,
        styles[v.container],
        pressed && !isDisabled && PRESS_FEEDBACK,
        isDisabled && styles.disabled,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={v.spinner} />
      ) : (
        <Text style={[styles.label, styles[v.label]]}>{title}</Text>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: {
    height: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.md,
  },
  primary: {
    backgroundColor: colors.primaryTealDark,
  },
  secondary: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border,
  },
  destructive: {
    backgroundColor: colors.dangerStrong,
  },
  label: {
    ...typography.body,
    fontWeight: '600', // SemiBold per spec for buttons
  },
  labelPrimary: { color: colors.white },
  labelSecondary: { color: colors.textPrimary },
  labelDestructive: { color: colors.white },
  disabled: { opacity: 0.55 },
});

export default Button;
