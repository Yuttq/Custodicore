import React from 'react';
import { StyleSheet, Text, TextInput, View } from 'react-native';
import { colors, layout, spacing, typography } from '../designSystem';

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
 * @param {import('react-native').ViewStyle} [props.style]
 * @param {import('react-native').TextStyle} [props.labelStyle]
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
        style={[styles.label, labelStyle]}
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
        style={[styles.input, typography.body, error ? styles.inputError : null]}
      />
      {error ? (
        <Text accessibilityRole="alert" style={styles.error}>
          {error}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: spacing.md },
  label: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing.xs,
  },
  input: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    backgroundColor: colors.white,
    color: colors.textPrimary,
    minHeight: layout.buttonHeight,
  },
  inputError: { borderColor: colors.danger },
  error: {
    ...typography.metadata,
    color: colors.danger,
    marginTop: spacing.xs,
  },
});
