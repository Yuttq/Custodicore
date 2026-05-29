import React from 'react';
import {
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { colors, layout, typography } from '../constants';

/**
 * @param {object} props
 * @param {string} props.label
 * @param {string} props.value
 * @param {(t: string) => void} props.onChangeText
 * @param {string} [props.placeholder]
 * @param {boolean} [props.secureTextEntry]
 * @param {string} [props.error]
 * @param {import('react-native').TextInputProps['keyboardType']} [props.keyboardType]
 * @param {import('react-native').TextInputProps['autoCapitalize']} [props.autoCapitalize]
 * @param {boolean} [props.autoCorrect]
 * @param {import('react-native').ViewStyle} [props.style] — outer container (e.g. spacing in dense forms)
 */
export default function CustomInput({
  label,
  value,
  onChangeText,
  placeholder,
  secureTextEntry,
  error,
  keyboardType = 'default',
  autoCapitalize = 'sentences',
  autoCorrect = true,
  labelStyle,
  style,
}) {
  const inputId = label?.replace(/\s+/g, '-') ?? 'input';

  return (
    <View style={[styles.wrap, style]}>
      <Text
        accessibilityRole="text"
        nativeID={`${inputId}-label`}
        style={[typography.caption, styles.label, labelStyle]}
      >
        {label}
      </Text>
      <TextInput
        accessibilityLabel={label}
        accessibilityHint={error || undefined}
        value={value}
        onChangeText={onChangeText}
        placeholder={placeholder}
        placeholderTextColor={colors.textSecondary}
        secureTextEntry={secureTextEntry}
        keyboardType={keyboardType}
        autoCapitalize={autoCapitalize}
        autoCorrect={autoCorrect}
        style={[
          styles.input,
          typography.body,
          error ? styles.inputError : null,
        ]}
      />
      {error ? (
        <Text
          accessibilityRole="alert"
          style={[typography.caption, styles.error]}
        >
          {error}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: layout.spacing.md },
  label: { color: colors.textPrimary, marginBottom: layout.spacing.xs },
  input: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.borderRadiusSm,
    paddingHorizontal: layout.spacing.md,
    paddingVertical: layout.spacing.sm,
    minHeight: layout.minTouchHeight,
    color: colors.textPrimary,
    backgroundColor: colors.white,
  },
  inputError: { borderColor: colors.danger },
  error: { color: colors.danger, marginTop: layout.spacing.xs },
});
