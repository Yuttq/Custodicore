import React from 'react';
import {
  ActivityIndicator,
  Platform,
  Pressable,
  StyleSheet,
  Text,
} from 'react-native';
import { Button as DSButton } from '../designSystem';

/**
 * @deprecated Prefer `Button` from `src/designSystem`.
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
  if (variant === 'danger') {
    return (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={accessibilityLabel ?? title}
        accessibilityState={{ disabled: disabled || loading }}
        onPress={onPress}
        disabled={disabled || loading}
        style={({ pressed }) => [
          styles.dangerBase,
          pressed && !disabled && !loading && styles.pressed,
          (disabled || loading) && styles.disabled,
        ]}
      >
        {loading ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <Text style={styles.dangerLabel}>{title}</Text>
        )}
      </Pressable>
    );
  }

  return (
    <DSButton
      title={title}
      onPress={onPress}
      loading={loading}
      disabled={disabled}
      accessibilityLabel={accessibilityLabel}
    />
  );
}

const PRESS_FEEDBACK = Platform.select({
  android: { opacity: 0.88 },
  ios: { opacity: 0.9 },
  default: { opacity: 0.9 },
});

const styles = StyleSheet.create({
  dangerBase: {
    minHeight: 48,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 16,
    backgroundColor: '#ef4444',
  },
  dangerLabel: {
    fontSize: 16,
    fontWeight: '600',
    color: '#fff',
  },
  pressed: PRESS_FEEDBACK,
  disabled: { opacity: 0.5 },
});
