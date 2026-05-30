import React from 'react';
import {
  ActivityIndicator,
  Platform,
  Pressable,
  StyleSheet,
  Text,
} from 'react-native';
import { colors, layout, typography } from '../constants';

const PRESS_FEEDBACK = Platform.select({
  android: { opacity: 0.88 },
  ios: { opacity: 0.9 },
  default: { opacity: 0.9 },
});

/**
 * @param {object} props
 * @param {string} props.title
 * @param {() => void} props.onPress
 * @param {'primary' | 'danger'} [props.variant]
 * @param {boolean} [props.loading]
 * @param {boolean} [props.disabled]
 * @param {string} [props.accessibilityLabel]
 */
export default function CustomButton({
  title,
  onPress,
  variant = 'primary',
  loading = false,
  disabled = false,
  accessibilityLabel,
}) {
  const isDanger = variant === 'danger';
  const bg = isDanger ? colors.danger : colors.primary;
  const isDisabled = disabled || loading;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? title}
      accessibilityState={{ disabled: isDisabled }}
      onPress={onPress}
      disabled={isDisabled}
      android_ripple={
        isDisabled ? undefined : { color: 'rgba(255,255,255,0.25)' }
      }
      style={({ pressed }) => [
        styles.base,
        { backgroundColor: bg },
        pressed && !isDisabled && PRESS_FEEDBACK,
        isDisabled && styles.disabled,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={colors.white} />
      ) : (
        <Text style={[typography.button, styles.label]}>{title}</Text>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: {
    minHeight: layout.minTouchHeight,
    borderRadius: layout.buttonRadius,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: layout.spacing.md,
    paddingVertical: layout.spacing.sm,
  },
  label: { color: colors.white },
  disabled: { opacity: 0.5 },
});
