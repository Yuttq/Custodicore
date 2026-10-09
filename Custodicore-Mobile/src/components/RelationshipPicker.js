import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, layout, spacing, typography } from '../designSystem';

/**
 * PDL chips for a visitor with more than one verified relationship; renders
 * nothing for one. Availability is per relationship, so the Home calendar and
 * the Schedule tab both show which one they are for.
 * @param {object} props
 * @param {{ relationshipId: string; pdlName: string | null }[]} props.relationships
 * @param {string | null | undefined} props.selectedId
 * @param {(relationshipId: string) => void} props.onSelect
 */
export default function RelationshipPicker({ relationships, selectedId, onSelect }) {
  if (relationships.length < 2) return null;

  return (
    <View style={styles.chipRow}>
      {relationships.map((r) => {
        const active = r.relationshipId === selectedId;
        return (
          <Pressable
            key={r.relationshipId}
            onPress={() => onSelect(r.relationshipId)}
            style={({ pressed }) => [
              styles.chip,
              active && styles.chipActive,
              pressed && styles.pressed,
            ]}
            accessibilityRole="button"
            accessibilityState={{ selected: active }}
            accessibilityLabel={`Show schedule for ${r.pdlName ?? 'PDL'}`}
          >
            <Text style={[styles.chipText, active && styles.chipTextActive]}>
              {r.pdlName ?? 'PDL'}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  chipRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    marginBottom: spacing.md,
  },
  chip: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderRadius: layout.chipRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
  },
  chipActive: {
    borderColor: colors.primaryNavy,
    backgroundColor: colors.primaryNavy,
  },
  chipText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  chipTextActive: {
    color: colors.white,
  },
  pressed: { opacity: 0.88 },
});
