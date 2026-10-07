import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useEffect, useMemo, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, layout, spacing, typography } from '../designSystem';
import {
  MONTH_NAMES,
  WEEKDAY_SHORT,
  buildMonthGrid,
  formatDateLong,
  manilaTodayIso,
  parseIsoDate,
  shiftMonth,
} from '../utils/scheduleAvailability';

const GRAY_INACTIVE = '#C4C9D2';
const CELL_SIZE = 40;

function monthIndex(year, month) {
  return year * 12 + (month - 1);
}

/**
 * Small month-grid calendar for picking a visit date. Only dates that have
 * at least one available slot can be selected; past, out-of-range and
 * fully unavailable dates are greyed out and disabled.
 *
 * @param {object} props
 * @param {Record<string, { available: boolean }>} props.daysByDate
 * @param {string} props.today — YYYY-MM-DD (facility timezone)
 * @param {string | null} [props.minDate] — first date with availability data
 * @param {string | null} [props.maxDate] — last date with availability data
 * @param {string | null} [props.selectedDate]
 * @param {(date: string) => void} props.onSelectDate
 */
export default function VisitCalendar({
  daysByDate,
  today,
  minDate,
  maxDate,
  selectedDate,
  onSelectDate,
}) {
  const [visible, setVisible] = useState(() => {
    const start =
      parseIsoDate(selectedDate) ?? parseIsoDate(minDate) ?? parseIsoDate(today ?? manilaTodayIso());
    return { year: start.year, month: start.month };
  });

  const min = parseIsoDate(minDate);
  const max = parseIsoDate(maxDate);
  const minIndex = min ? monthIndex(min.year, min.month) : null;
  const maxIndex = max ? monthIndex(max.year, max.month) : null;
  const visibleIndex = monthIndex(visible.year, visible.month);

  // New data range (e.g. first load) — keep the visible month inside it.
  useEffect(() => {
    if (minIndex != null && visibleIndex < minIndex) setVisible({ year: min.year, month: min.month });
    else if (maxIndex != null && visibleIndex > maxIndex) setVisible({ year: max.year, month: max.month });
    // eslint-disable-next-line react-hooks/exhaustive-deps -- only react to range changes
  }, [minIndex, maxIndex]);

  const weeks = useMemo(() => buildMonthGrid(visible.year, visible.month), [visible]);
  const canGoBack = minIndex == null || visibleIndex > minIndex;
  const canGoForward = maxIndex == null || visibleIndex < maxIndex;

  const renderCell = (date, i) => {
    if (!date) return <View key={`pad-${i}`} style={styles.cell} />;

    const inRange = (!minDate || date >= minDate) && (!maxDate || date <= maxDate);
    const isPast = Boolean(today) && date < today;
    const isAvailable = inRange && !isPast && Boolean(daysByDate[date]?.available);
    const isSelected = date === selectedDate && isAvailable;
    const isToday = date === today;
    const dayNumber = Number(date.slice(8, 10));

    return (
      <Pressable
        key={date}
        style={styles.cell}
        onPress={() => onSelectDate(date)}
        disabled={!isAvailable}
        accessibilityRole="button"
        accessibilityLabel={`${formatDateLong(date)}, ${isAvailable ? 'available' : 'unavailable'}`}
        accessibilityState={{ disabled: !isAvailable, selected: isSelected }}
      >
        {({ pressed }) => (
          <View
            style={[
              styles.dayCircle,
              isAvailable && styles.dayAvailable,
              isSelected && styles.daySelected,
              pressed && isAvailable && styles.pressed,
            ]}
          >
            <Text
              style={[
                styles.dayText,
                isAvailable ? styles.dayTextAvailable : styles.dayTextInactive,
                isSelected && styles.dayTextSelected,
              ]}
            >
              {dayNumber}
            </Text>
            {isToday ? (
              <View style={[styles.todayDot, isSelected && styles.todayDotSelected]} />
            ) : null}
          </View>
        )}
      </Pressable>
    );
  };

  return (
    <View>
      <View style={styles.header}>
        <Pressable
          onPress={() => setVisible((v) => shiftMonth(v.year, v.month, -1))}
          disabled={!canGoBack}
          style={styles.navButton}
          accessibilityRole="button"
          accessibilityLabel="Previous month"
          accessibilityState={{ disabled: !canGoBack }}
        >
          <Ionicons
            name="chevron-back"
            size={20}
            color={canGoBack ? colors.primaryNavy : GRAY_INACTIVE}
          />
        </Pressable>
        <Text style={styles.monthLabel} accessibilityRole="header">
          {MONTH_NAMES[visible.month - 1]} {visible.year}
        </Text>
        <Pressable
          onPress={() => setVisible((v) => shiftMonth(v.year, v.month, 1))}
          disabled={!canGoForward}
          style={styles.navButton}
          accessibilityRole="button"
          accessibilityLabel="Next month"
          accessibilityState={{ disabled: !canGoForward }}
        >
          <Ionicons
            name="chevron-forward"
            size={20}
            color={canGoForward ? colors.primaryNavy : GRAY_INACTIVE}
          />
        </Pressable>
      </View>

      <View style={styles.row}>
        {WEEKDAY_SHORT.map((d) => (
          <View key={d} style={styles.cell}>
            <Text style={styles.weekday}>{d}</Text>
          </View>
        ))}
      </View>

      {weeks.map((week, w) => (
        <View key={`week-${w}`} style={styles.row}>
          {week.map((date, i) => renderCell(date, w * 7 + i))}
        </View>
      ))}

      <View style={styles.legend}>
        <View style={styles.legendItem}>
          <View style={[styles.legendSwatch, styles.dayAvailable]} />
          <Text style={styles.legendText}>Available</Text>
        </View>
        <View style={styles.legendItem}>
          <View style={[styles.legendSwatch, styles.daySelected]} />
          <Text style={styles.legendText}>Selected</Text>
        </View>
        <View style={styles.legendItem}>
          <View style={[styles.legendSwatch, styles.legendInactive]} />
          <Text style={styles.legendText}>Unavailable</Text>
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.sm,
  },
  navButton: {
    width: layout.iconButtonSize,
    height: layout.iconButtonSize,
    alignItems: 'center',
    justifyContent: 'center',
  },
  monthLabel: {
    ...typography.body,
    fontWeight: '700',
    color: colors.textPrimary,
  },
  row: {
    flexDirection: 'row',
  },
  cell: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 2,
  },
  weekday: {
    ...typography.statusLabel,
    color: colors.textSecondary,
    paddingBottom: spacing.xs,
  },
  dayCircle: {
    width: CELL_SIZE,
    height: CELL_SIZE,
    borderRadius: CELL_SIZE / 2,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: 'transparent',
  },
  dayAvailable: {
    backgroundColor: 'rgba(13, 165, 138, 0.1)',
    borderColor: colors.primaryTeal,
  },
  daySelected: {
    backgroundColor: colors.primaryNavy,
    borderColor: colors.primaryNavy,
  },
  dayText: {
    ...typography.metadata,
  },
  dayTextAvailable: {
    fontWeight: '700',
    color: colors.primaryNavy,
  },
  dayTextInactive: {
    color: GRAY_INACTIVE,
  },
  dayTextSelected: {
    color: colors.white,
  },
  todayDot: {
    position: 'absolute',
    bottom: 4,
    width: 4,
    height: 4,
    borderRadius: 2,
    backgroundColor: colors.primaryTeal,
  },
  todayDotSelected: {
    backgroundColor: colors.white,
  },
  legend: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'center',
    gap: spacing.md,
    marginTop: spacing.sm,
  },
  legendItem: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
  },
  legendSwatch: {
    width: 12,
    height: 12,
    borderRadius: 6,
    borderWidth: 1,
  },
  legendInactive: {
    backgroundColor: colors.background,
    borderColor: GRAY_INACTIVE,
  },
  legendText: {
    ...typography.statusLabel,
    color: colors.textSecondary,
  },
  pressed: { opacity: 0.88 },
});
