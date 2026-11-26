import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { colors, formStyles, spacing, typography } from '../designSystem';
import {
  PDL_NAME_PART_LIMITS,
  PDL_NAME_SUFFIXES,
  composeIntendedPdlName,
  filterPdlNamePart,
} from '../utils/intendedPdlName';

/**
 * The PDL's name in parts: First, Middle (or "No middle name / Not sure"),
 * Last and an optional Suffix, with the joined full name shown underneath —
 * the one string the app sends. Used by registration step 2 and Add PDL.
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
  const [suffixOpen, setSuffixOpen] = useState(false);
  const fullName = composeIntendedPdlName(value);

  const renderInput = (label, key, errorKey, maxLength, placeholder) => (
    <View style={[formStyles.field, fieldStyle]}>
      <Text style={[formStyles.label, labelStyle]}>{label}</Text>
      <TextInput
        value={value[key]}
        onChangeText={(v) => onChange({ [key]: filterPdlNamePart(v) }, errorKey)}
        placeholder={placeholder}
        placeholderTextColor={colors.textSecondary}
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
      {renderInput("PDL's First Name", 'first', 'pdlFirstName', PDL_NAME_PART_LIMITS.first, 'e.g. Juan')}

      {value.noMiddleName
        ? null
        : renderInput("PDL's Middle Name", 'middle', 'pdlMiddleName', PDL_NAME_PART_LIMITS.middle, 'e.g. Santos')}
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

      {renderInput("PDL's Last Name", 'last', 'pdlLastName', PDL_NAME_PART_LIMITS.last, 'e.g. Dela Cruz')}
      <Text style={styles.hint}>Include every part of the last name, e.g. Dela Cruz, De los Santos.</Text>

      <View style={[formStyles.field, fieldStyle]}>
        <Text style={[formStyles.label, labelStyle]}>Suffix (optional)</Text>
        <Pressable
          onPress={() => setSuffixOpen(true)}
          style={[formStyles.input, inputStyle, styles.select, errors.pdlSuffix ? formStyles.inputError : null]}
          accessibilityRole="button"
          accessibilityLabel={`Suffix: ${value.suffix || 'None'}. Tap to change.`}
        >
          <Text style={styles.selectText}>{value.suffix || 'None'}</Text>
          <Ionicons name="chevron-down" size={18} color={colors.textSecondary} />
        </Pressable>
        {errors.pdlSuffix ? <Text style={formStyles.error}>{errors.pdlSuffix}</Text> : null}
      </View>

      <View style={styles.preview} accessibilityRole="text">
        <Text style={styles.previewLabel}>Full name staff will see</Text>
        <Text style={[styles.previewValue, !fullName && styles.previewEmpty]}>
          {fullName || 'Enter the name above'}
        </Text>
      </View>

      <Modal visible={suffixOpen} transparent animationType="fade" onRequestClose={() => setSuffixOpen(false)}>
        <Pressable style={styles.modalOverlay} onPress={() => setSuffixOpen(false)}>
          <View style={styles.modalSheet}>
            <Text style={styles.modalTitle}>Select suffix</Text>
            {['', ...PDL_NAME_SUFFIXES].map((s) => (
              <Pressable
                key={s || 'none'}
                style={styles.modalOption}
                onPress={() => {
                  onChange({ suffix: s }, 'pdlSuffix');
                  setSuffixOpen(false);
                }}
                accessibilityRole="button"
                accessibilityState={{ selected: value.suffix === s }}
              >
                <Text style={[styles.modalOptionText, value.suffix === s && styles.modalOptionSelected]}>
                  {s || 'None'}
                </Text>
              </Pressable>
            ))}
          </View>
        </Pressable>
      </Modal>
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
  hint: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: -spacing.xs,
    marginBottom: spacing.sm,
  },
  select: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  selectText: {
    ...typography.body,
    color: colors.textPrimary,
  },
  preview: {
    borderRadius: 8,
    backgroundColor: colors.background,
    padding: spacing.sm,
    marginBottom: spacing.sm,
  },
  previewLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  previewValue: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  previewEmpty: {
    fontWeight: '400',
    color: colors.textSecondary,
  },
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(15, 61, 122, 0.45)',
    justifyContent: 'flex-end',
  },
  modalSheet: {
    backgroundColor: colors.white,
    borderTopLeftRadius: 16,
    borderTopRightRadius: 16,
    padding: spacing.md,
    paddingBottom: spacing.xl,
  },
  modalTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  modalOption: {
    paddingVertical: spacing.sm,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  modalOptionText: {
    ...typography.body,
    color: colors.textPrimary,
  },
  modalOptionSelected: {
    fontWeight: '700',
    color: colors.primaryTeal,
  },
});
