import Ionicons from '@expo/vector-icons/Ionicons';
import DateTimePicker, { DateTimePickerAndroid } from '@react-native-community/datetimepicker';
import React, { useCallback, useMemo, useState } from 'react';
import {
  Modal,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {
  formatBirthdateDisplay,
  parseIsoDateString,
  toIsoDateString,
} from '../../utils/formatDate';
import { colors } from '../tokens/colors';
import { layout, spacing } from '../tokens/spacing';
import { typography } from '../tokens/typography';

const MIN_BIRTHDATE = new Date(1920, 0, 1);
const DEFAULT_PICKER_AGE_YEARS = 18;

/** Only dates before today (local calendar) can be saved. */
function isBeforeToday(date, today) {
  return toIsoDateString(date) < toIsoDateString(today);
}

/** Latest selectable birthdate: yesterday, so the picker never offers today. */
function latestBirthdate(today) {
  return new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);
}

/**
 * An empty field opens the picker 18 years back rather than on today, so
 * Done/OK is usable on the first tap. The separate Age field must match the
 * chosen birthdate, which catches an untouched default being submitted.
 */
function defaultPickerDate(today) {
  return new Date(
    today.getFullYear() - DEFAULT_PICKER_AGE_YEARS,
    today.getMonth(),
    today.getDate(),
  );
}

/**
 * Tap-to-pick birthdate field (native picker, no manual entry).
 * @param {object} props
 * @param {string} props.label
 * @param {string} props.value ISO date YYYY-MM-DD
 * @param {(iso: string) => void} props.onChange
 * @param {string} [props.error]
 * @param {string} [props.placeholder]
 */
export function BirthdateField({
  label,
  value,
  onChange,
  error,
  placeholder = 'Select birthdate',
}) {
  const [iosVisible, setIosVisible] = useState(false);
  // iOS: "today" as of when the picker opened (start date + maximum date).
  const [iosToday, setIosToday] = useState(() => new Date());
  const [iosDraft, setIosDraft] = useState(iosToday);
  const iosCanConfirm = isBeforeToday(iosDraft, iosToday);

  const displayText = useMemo(() => formatBirthdateDisplay(value), [value]);

  const openPicker = useCallback(() => {
    const today = new Date();
    const startDate = parseIsoDateString(value) ?? defaultPickerDate(today);

    if (Platform.OS === 'android') {
      DateTimePickerAndroid.open({
        value: startDate,
        mode: 'date',
        display: 'default',
        maximumDate: latestBirthdate(today),
        minimumDate: MIN_BIRTHDATE,
        onChange: (event, selectedDate) => {
          // Cancel/dismiss: keep the current value.
          if (event.type === 'set' && selectedDate && isBeforeToday(selectedDate, today)) {
            onChange(toIsoDateString(selectedDate));
          }
        },
      });
      return;
    }

    setIosToday(today);
    setIosDraft(startDate);
    setIosVisible(true);
  }, [onChange, value]);

  const confirmIos = useCallback(() => {
    if (!iosCanConfirm) return;
    onChange(toIsoDateString(iosDraft));
    setIosVisible(false);
  }, [iosCanConfirm, iosDraft, onChange]);

  const cancelIos = useCallback(() => {
    setIosVisible(false);
  }, []);

  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{label}</Text>
      <Pressable
        onPress={openPicker}
        accessibilityRole="button"
        accessibilityLabel={`${label}, ${displayText || placeholder}`}
        accessibilityHint="Opens date picker"
        style={({ pressed }) => [
          styles.field,
          error ? styles.fieldError : null,
          pressed && styles.fieldPressed,
        ]}
      >
        <Text style={[styles.valueText, !displayText && styles.placeholderText]}>
          {displayText || placeholder}
        </Text>
        <Ionicons name="calendar-outline" size={22} color={colors.primaryTeal} />
      </Pressable>
      {error ? <Text style={styles.error}>{error}</Text> : null}

      {Platform.OS === 'ios' ? (
        <Modal visible={iosVisible} transparent animationType="slide">
          <Pressable style={styles.modalOverlay} onPress={cancelIos}>
            <Pressable style={styles.modalSheet} onPress={(e) => e.stopPropagation()}>
              <View style={styles.modalHeader}>
                <Pressable onPress={cancelIos} hitSlop={8}>
                  <Text style={styles.modalActionMuted}>Cancel</Text>
                </Pressable>
                <Text style={styles.modalTitle}>Birthdate</Text>
                <Pressable
                  onPress={confirmIos}
                  disabled={!iosCanConfirm}
                  accessibilityRole="button"
                  accessibilityState={{ disabled: !iosCanConfirm }}
                  hitSlop={8}
                >
                  <Text style={iosCanConfirm ? styles.modalAction : styles.modalActionMuted}>
                    Done
                  </Text>
                </Pressable>
              </View>
              {!iosCanConfirm ? (
                <Text style={styles.modalHint}>Select your birthdate.</Text>
              ) : null}
              <DateTimePicker
                value={iosDraft}
                mode="date"
                display="spinner"
                maximumDate={latestBirthdate(iosToday)}
                minimumDate={MIN_BIRTHDATE}
                onChange={(_, selectedDate) => {
                  if (selectedDate) setIosDraft(selectedDate);
                }}
                style={styles.iosPicker}
                themeVariant="light"
              />
            </Pressable>
          </Pressable>
        </Modal>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: spacing.sm },
  label: {
    ...typography.metadata,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  field: {
    height: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingHorizontal: spacing.sm,
    backgroundColor: colors.white,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  fieldPressed: {
    backgroundColor: 'rgba(13, 165, 138, 0.06)',
  },
  fieldError: {
    borderColor: colors.danger,
  },
  valueText: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
  },
  placeholderText: {
    color: colors.textSecondary,
  },
  error: {
    ...typography.metadata,
    color: colors.danger,
    marginTop: spacing.sm,
  },
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(15, 61, 122, 0.45)',
    justifyContent: 'flex-end',
  },
  modalSheet: {
    backgroundColor: colors.white,
    borderTopLeftRadius: layout.cardRadius,
    borderTopRightRadius: layout.cardRadius,
    paddingBottom: spacing.lg,
  },
  modalHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  modalTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
  },
  modalAction: {
    ...typography.body,
    fontWeight: '600',
    color: colors.primaryTeal,
  },
  modalActionMuted: {
    ...typography.body,
    color: colors.textSecondary,
  },
  modalHint: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
    paddingTop: spacing.sm,
  },
  iosPicker: {
    height: 216,
  },
});

export default BirthdateField;
