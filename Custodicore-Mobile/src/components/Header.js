import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, commonStyles, layout, spacing, typography } from '../designSystem';

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
          hitSlop={spacing.sm}
          style={styles.back}
        >
          <Text style={styles.backText}>‹</Text>
        </Pressable>
      ) : (
        <View style={commonStyles.backPlaceholder} />
      )}
      <Text
        accessibilityRole="header"
        style={styles.title}
        numberOfLines={1}
      >
        {title}
      </Text>
      <View style={commonStyles.backPlaceholder} />
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: spacing.sm,
    paddingHorizontal: layout.screenPadding,
    backgroundColor: colors.white,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    minHeight: layout.buttonHeight,
  },
  title: {
    ...typography.screenHeader,
    flex: 1,
    textAlign: 'center',
    color: colors.textPrimary,
  },
  back: {
    width: layout.iconButtonSize - spacing.sm,
    minHeight: layout.buttonHeight,
    justifyContent: 'center',
    alignItems: 'center',
  },
  backText: {
    fontSize: 28,
    color: colors.primaryNavy,
    fontWeight: '600',
  },
});
