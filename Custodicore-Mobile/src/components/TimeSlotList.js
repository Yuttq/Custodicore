import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, layout, spacing, typography } from '../designSystem';
import {
  formatSlotRange,
  middayBreakLabel,
  periodLabel,
  slotReasonLabel,
} from '../utils/scheduleAvailability';

const GRAY_INACTIVE = '#9CA3AF';

/**
 * One selectable option: a session, or the whole day.
 * @param {object} props
 * @param {import('../utils/scheduleAvailability').AvailabilitySlot} props.slot
 * @param {boolean} props.isSelected
 * @param {(slotKey: string) => void} props.onSelectSlot
 * @param {string | null} [props.note] — extra line under the time range
 */
function SlotOption({ slot, isSelected, onSelectSlot, note = null }) {
  const label = periodLabel(slot.period, slot.startTime);
  const range = formatSlotRange(slot.startTime, slot.endTime);
  const status = slot.available
    ? `${slot.slotsRemaining} ${slot.slotsRemaining === 1 ? 'slot' : 'slots'} left`
    : slotReasonLabel(slot.reason);

  return (
    <Pressable
      onPress={() => onSelectSlot(slot.slotKey)}
      disabled={!slot.available}
      accessibilityRole="button"
      accessibilityLabel={`${label}, ${range}${note ? `, ${note}` : ''}, ${status}`}
      accessibilityState={{ disabled: !slot.available, selected: isSelected }}
      style={({ pressed }) => [
        styles.slot,
        !slot.available && styles.slotDisabled,
        isSelected && styles.slotSelected,
        pressed && slot.available && styles.pressed,
      ]}
    >
      <View style={styles.slotText}>
        <Text style={[styles.period, !slot.available && styles.textInactive]}>{label}</Text>
        <Text style={[styles.range, !slot.available && styles.textInactive]}>{range}</Text>
        {note ? (
          <Text style={[styles.range, !slot.available && styles.textInactive]}>{note}</Text>
        ) : null}
      </View>
      <View style={styles.statusCol}>
        <Text
          style={[
            styles.status,
            slot.available ? styles.statusAvailable : styles.textInactive,
          ]}
        >
          {status}
        </Text>
        {isSelected ? (
          <Ionicons name="checkmark-circle" size={20} color={colors.primaryTeal} />
        ) : null}
      </View>
    </Pressable>
  );
}

/**
 * Morning / afternoon sessions for one date, then the whole day (both
 * sessions as ONE visit) when the date has it. Exactly one option is
 * selected. Available options are selectable; unavailable ones are disabled
 * and say why (Full, Closed, Past, …).
 *
 * @param {object} props
 * @param {import('../utils/scheduleAvailability').AvailabilitySlot[]} props.slots
 * @param {import('../utils/scheduleAvailability').AvailabilitySlot | null} [props.wholeDay]
 * @param {string | null} [props.selectedSlotKey]
 * @param {(slotKey: string) => void} props.onSelectSlot
 */
export default function TimeSlotList({ slots, wholeDay = null, selectedSlotKey, onSelectSlot }) {
  if (!slots || slots.length === 0) {
    return <Text style={styles.empty}>No visiting time slots on this date.</Text>;
  }

  const breakLabel = middayBreakLabel(slots);

  return (
    <View style={styles.list}>
      {slots.map((slot) => (
        <SlotOption
          key={slot.slotKey}
          slot={slot}
          isSelected={slot.available && slot.slotKey === selectedSlotKey}
          onSelectSlot={onSelectSlot}
        />
      ))}
      {wholeDay ? (
        <SlotOption
          slot={wholeDay}
          isSelected={wholeDay.available && wholeDay.slotKey === selectedSlotKey}
          onSelectSlot={onSelectSlot}
          note={`Morning and afternoon · counts as 1 visit${breakLabel ? ` · midday break ${breakLabel}` : ''}`}
        />
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  list: {
    gap: spacing.sm,
  },
  slot: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: spacing.sm,
    minHeight: layout.buttonHeight,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
  },
  slotDisabled: {
    backgroundColor: colors.background,
  },
  slotSelected: {
    borderColor: colors.primaryTeal,
    borderWidth: 2,
    backgroundColor: 'rgba(13, 165, 138, 0.06)',
  },
  slotText: {
    flex: 1,
  },
  period: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  range: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  statusCol: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    flexShrink: 1,
  },
  status: {
    ...typography.statusLabel,
    textAlign: 'right',
    flexShrink: 1,
  },
  statusAvailable: {
    color: colors.success,
  },
  textInactive: {
    color: GRAY_INACTIVE,
  },
  empty: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  pressed: { opacity: 0.88 },
});
