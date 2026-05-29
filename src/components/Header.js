import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, layout, typography } from '../constants';

/**
 * @param {object} props
 * @param {string} props.title
 * @param {boolean} [props.showBackButton]
 * @param {() => void} [props.onBackPress]
 */
export default function Header({ title, showBackButton, onBackPress }) {
  return (
    <View style={styles.row}>
      {showBackButton ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Go back"
          onPress={onBackPress}
          hitSlop={8}
          style={styles.back}
        >
          <Text style={styles.backText}>‹</Text>
        </Pressable>
      ) : (
        <View style={styles.backPlaceholder} />
      )}
      <Text
        accessibilityRole="header"
        style={[typography.title, styles.title]}
        numberOfLines={1}
      >
        {title}
      </Text>
      <View style={styles.backPlaceholder} />
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: layout.spacing.sm,
    paddingHorizontal: layout.spacing.sm,
    backgroundColor: colors.white,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    minHeight: layout.minTouchHeight,
  },
  title: {
    flex: 1,
    textAlign: 'center',
    color: colors.textPrimary,
  },
  back: {
    width: 40,
    minHeight: layout.minTouchHeight,
    justifyContent: 'center',
    alignItems: 'center',
  },
  backText: {
    fontSize: 28,
    color: colors.primary,
    fontWeight: '600',
  },
  backPlaceholder: { width: 40 },
});
