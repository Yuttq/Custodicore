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

/**
 * v2.1 Button
 * @param {object} props
 * @param {string} props.title
 * @param {() => void} props.onPress
 * @param {'primary'|'secondary'} [props.variant]
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

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? title}
      accessibilityState={{ disabled: isDisabled }}
      onPress={onPress}
      disabled={isDisabled}
      android_ripple={
        isDisabled
          ? undefined
          : {
              color:
                variant === 'primary'
                  ? 'rgba(255,255,255,0.25)'
                  : 'rgba(13,165,138,0.12)',
            }
      }
      style={({ pressed }) => [
        styles.base,
        variant === 'primary' ? styles.primary : styles.secondary,
        pressed && !isDisabled && PRESS_FEEDBACK,
        isDisabled && styles.disabled,
      ]}
    >
      {loading ? (
        <ActivityIndicator
          color={variant === 'primary' ? colors.white : colors.primaryTeal}
        />
      ) : (
        <Text
          style={[
            styles.label,
            variant === 'primary' ? styles.labelPrimary : styles.labelSecondary,
          ]}
        >
          {title}
        </Text>
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
    backgroundColor: colors.primaryTeal,
  },
  secondary: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border,
  },
  label: {
    ...typography.body,
    fontWeight: '600', // SemiBold per spec for buttons
  },
  labelPrimary: { color: colors.white },
  labelSecondary: { color: colors.textPrimary },
  disabled: { opacity: 0.55 },
});

export default Button;
