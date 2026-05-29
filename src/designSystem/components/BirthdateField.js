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
const DEFAULT_BIRTHDATE = new Date(2000, 0, 26);

function getPickerDate(isoValue) {
  return parseIsoDateString(isoValue) ?? DEFAULT_BIRTHDATE;
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
  const [iosDraft, setIosDraft] = useState(() => getPickerDate(value));

  const displayText = useMemo(() => formatBirthdateDisplay(value), [value]);
  const maximumDate = useMemo(() => new Date(), []);

  const openPicker = useCallback(() => {
    if (Platform.OS === 'android') {
      DateTimePickerAndroid.open({
        value: getPickerDate(value),
        mode: 'date',
        display: 'default',
        maximumDate,
        minimumDate: MIN_BIRTHDATE,
        onChange: (event, selectedDate) => {
          if (event.type === 'set' && selectedDate) {
            onChange(toIsoDateString(selectedDate));
          }
        },
      });
      return;
    }

    setIosDraft(getPickerDate(value));
    setIosVisible(true);
  }, [maximumDate, onChange, value]);

  const confirmIos = useCallback(() => {
    onChange(toIsoDateString(iosDraft));
    setIosVisible(false);
  }, [iosDraft, onChange]);

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
                <Pressable onPress={confirmIos} hitSlop={8}>
                  <Text style={styles.modalAction}>Done</Text>
                </Pressable>
              </View>
              <DateTimePicker
                value={iosDraft}
                mode="date"
                display="spinner"
                maximumDate={maximumDate}
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
  wrap: { marginBottom: spacing[12] },
  label: {
    ...typography.caption,
    color: colors.textPrimary,
    marginBottom: spacing[8],
  },
  field: {
    height: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 12,
    paddingHorizontal: spacing[12],
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
    ...typography.caption,
    color: colors.danger,
    marginTop: spacing[8],
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
    paddingBottom: spacing[24],
  },
  modalHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing[20],
    paddingVertical: spacing[12],
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
  iosPicker: {
    height: 216,
  },
});

export default BirthdateField;
