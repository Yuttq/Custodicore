import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { colors, formStyles, spacing, typography } from '../designSystem';
import { PDL_NAME_PART_LIMITS, filterPdlNamePart } from '../utils/intendedPdlName';
import SuffixSelect from './SuffixSelect';

/**
 * The PDL's name in parts, in the system's standard order: Last, First,
 * Middle (or "No middle name / Not sure") and an optional Suffix. Used by
 * registration step 2 and Add PDL. Only the input order follows the
 * standard; the name sent is still joined First Middle Last Suffix
 * (composeIntendedPdlName), the natural order the backend stores.
 *
 * Controlled: `value` is a PdlNameParts object; `onChange(patch, errorKey)`
 * gives the changed part(s) and the error key to clear. Errors are keyed
 * pdlFirstName / pdlMiddleName / pdlLastName / pdlSuffix (getPdlNamePartsErrors).
 * `fieldStyle` / `labelStyle` / `inputStyle` let a screen keep its own look.
 *
 * @param {object} props
 * @param {import('../utils/intendedPdlName').PdlNameParts} props.value
 * @param {(patch: Partial<import('../utils/intendedPdlName').PdlNameParts>, errorKey: string) => void} props.onChange
 * @param {Record<string, string | undefined>} [props.errors]
 * @param {any} [props.fieldStyle]
 * @param {any} [props.labelStyle]
 * @param {any} [props.inputStyle]
 */
export default function PdlNameFields({ value, onChange, errors = {}, fieldStyle, labelStyle, inputStyle }) {
  const renderInput = (label, key, errorKey, maxLength) => (
    <View style={[formStyles.field, fieldStyle]}>
      <Text style={[formStyles.label, labelStyle]}>{label}</Text>
      <TextInput
        value={value[key]}
        onChangeText={(v) => onChange({ [key]: filterPdlNamePart(v) }, errorKey)}
        style={[formStyles.input, inputStyle, errors[errorKey] ? formStyles.inputError : null]}
        autoCapitalize="words"
        autoCorrect={false}
        maxLength={maxLength}
        accessibilityLabel={label}
      />
      {errors[errorKey] ? <Text style={formStyles.error}>{errors[errorKey]}</Text> : null}
    </View>
  );

  return (
    <View>
      {renderInput("PDL's Last Name", 'last', 'pdlLastName', PDL_NAME_PART_LIMITS.last)}

      {renderInput("PDL's First Name", 'first', 'pdlFirstName', PDL_NAME_PART_LIMITS.first)}

      {value.noMiddleName
        ? null
        : renderInput("PDL's Middle Name", 'middle', 'pdlMiddleName', PDL_NAME_PART_LIMITS.middle)}
      <Pressable
        onPress={() =>
          // Ticking clears the middle name: nothing is sent in its place.
          onChange({ noMiddleName: !value.noMiddleName, middle: '' }, 'pdlMiddleName')
        }
        style={styles.checkRow}
        accessibilityRole="checkbox"
        accessibilityState={{ checked: value.noMiddleName }}
        accessibilityLabel="No middle name / Not sure"
      >
        <View style={[styles.checkbox, value.noMiddleName && styles.checkboxChecked]}>
          {value.noMiddleName ? <Ionicons name="checkmark" size={14} color={colors.white} /> : null}
        </View>
        <Text style={styles.checkText}>No middle name / Not sure</Text>
      </Pressable>

      <SuffixSelect
        value={value.suffix}
        onChange={(s) => onChange({ suffix: s }, 'pdlSuffix')}
        error={errors.pdlSuffix}
        fieldStyle={fieldStyle}
        labelStyle={labelStyle}
        inputStyle={inputStyle}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  checkRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.xs,
    marginTop: -spacing.xs,
    marginBottom: spacing.sm,
  },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: 6,
    borderWidth: 2,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkboxChecked: {
    backgroundColor: colors.primaryTeal,
    borderColor: colors.primaryTeal,
  },
  checkText: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
  },
});
