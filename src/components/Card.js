import React from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { colors, layout, shadows } from '../constants';

/**
 * @param {object} props
 * @param {React.ReactNode} props.children
 * @param {() => void} [props.onPress]
 * @param {import('react-native').ViewStyle} [props.style]
 * @param {string} [props.accessibilityLabel]
 */
export default function Card({ children, onPress, style, accessibilityLabel }) {
  const content = (
    <View style={[styles.card, style]}>{children}</View>
  );

  if (onPress) {
    return (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={accessibilityLabel}
        onPress={onPress}
        style={({ pressed }) => [pressed && styles.pressed]}
      >
        {content}
      </Pressable>
    );
  }

  return content;
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.white,
    borderRadius: layout.borderRadius,
    borderWidth: 1,
    borderColor: colors.borderLight,
    padding: layout.spacing.md,
    ...shadows.card,
  },
  pressed: { opacity: 0.96 },
});
