import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { ActivityIndicator, Platform, Pressable, StyleSheet, Text } from 'react-native';
import { colors, layout, spacing, typography } from '../designSystem';

const PRESS_FEEDBACK = Platform.select({
  android: { opacity: 0.92 },
  ios: { opacity: 0.94 },
  default: { opacity: 0.94 },
});

/**
 * Full-width Google Sign-In button — brand-neutral styling per CustodiCore design system.
 * @param {object} props
 * @param {() => void} props.onPress
 * @param {boolean} [props.loading]
 * @param {boolean} [props.disabled]
 * @param {string} [props.accessibilityLabel]
 */
export default function GoogleSignInButton({
  onPress,
  loading = false,
  disabled = false,
  accessibilityLabel = 'Continue with Google',
}) {
  const isDisabled = disabled || loading;

  return (
    <Pressable
      onPress={onPress}
      disabled={isDisabled}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
      accessibilityState={{ disabled: isDisabled, busy: loading }}
      android_ripple={isDisabled ? undefined : { color: 'rgba(15, 61, 122, 0.08)' }}
      style={({ pressed }) => [
        styles.button,
        pressed && !isDisabled && PRESS_FEEDBACK,
        isDisabled && styles.disabled,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={colors.primaryNavy} />
      ) : (
        <>
          <Ionicons name="logo-google" size={20} color="#4285F4" style={styles.icon} />
          <Text style={styles.label}>Continue with Google</Text>
        </>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: {
    alignSelf: 'stretch',
    width: '100%',
    height: layout.buttonHeight,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingHorizontal: spacing.md,
  },
  icon: {
    position: 'absolute',
    left: spacing.md,
  },
  label: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  disabled: {
    opacity: 0.55,
  },
});
