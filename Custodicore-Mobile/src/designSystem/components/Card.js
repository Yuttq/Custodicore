import React from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { colors } from '../tokens/colors';
import { layout } from '../tokens/spacing';
import { shadows } from '../tokens/shadows';

/**
 * v2.1 Card
 * @param {object} props
 * @param {React.ReactNode} props.children
 * @param {() => void} [props.onPress]
 * @param {import('react-native').ViewStyle} [props.style]
 * @param {string} [props.accessibilityLabel]
 */
export function Card({ children, onPress, style, accessibilityLabel }) {
  const content = <View style={[styles.card, style]}>{children}</View>;

  if (!onPress) return content;

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

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.card,
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    padding: layout.cardPadding,
    ...shadows.card,
  },
  pressed: { opacity: 0.97 },
});

export default Card;
