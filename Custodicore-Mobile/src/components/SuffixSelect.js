import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, formStyles, spacing, typography } from '../designSystem';
import { PDL_NAME_SUFFIXES } from '../utils/intendedPdlName';

/**
 * Optional name suffix (Jr., Sr., II, III, IV, V) picked from a bottom sheet.
 * Same values as the backend's Sanitizer::SUFFIXES. Used by the PDL name
 * fields and the visitor's own name (registration, Personal Information).
 *
 * @param {object} props
 * @param {string} props.value — '' or one of PDL_NAME_SUFFIXES
 * @param {(suffix: string) => void} props.onChange
 * @param {string} [props.error]
 * @param {string} [props.label]
 * @param {any} [props.fieldStyle]
 * @param {any} [props.labelStyle]
 * @param {any} [props.inputStyle]
 */
export default function SuffixSelect({
  value,
  onChange,
  error,
  label = 'Suffix (optional)',
  fieldStyle,
  labelStyle,
  inputStyle,
}) {
  const [open, setOpen] = useState(false);

  return (
    <View style={[formStyles.field, fieldStyle]}>
      <Text style={[formStyles.label, labelStyle]}>{label}</Text>
      <Pressable
        onPress={() => setOpen(true)}
        style={[formStyles.input, inputStyle, styles.select, error ? formStyles.inputError : null]}
        accessibilityRole="button"
        accessibilityLabel={`Suffix: ${value || 'None'}. Tap to change.`}
      >
        <Text style={styles.selectText}>{value || 'None'}</Text>
        <Ionicons name="chevron-down" size={18} color={colors.textSecondary} />
      </Pressable>
      {error ? <Text style={formStyles.error}>{error}</Text> : null}

      <Modal visible={open} transparent animationType="fade" onRequestClose={() => setOpen(false)}>
        <Pressable style={styles.modalOverlay} onPress={() => setOpen(false)}>
          <View style={styles.modalSheet}>
            <Text style={styles.modalTitle}>Select suffix</Text>
            {['', ...PDL_NAME_SUFFIXES].map((s) => (
              <Pressable
                key={s || 'none'}
                style={styles.modalOption}
                onPress={() => {
                  onChange(s);
                  setOpen(false);
                }}
                accessibilityRole="button"
                accessibilityState={{ selected: value === s }}
              >
                <Text style={[styles.modalOptionText, value === s && styles.modalOptionSelected]}>
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
  select: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  selectText: {
    ...typography.body,
    color: colors.textPrimary,
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
