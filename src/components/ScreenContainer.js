import React from 'react';
import { StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { colors } from '../constants';

/**
 * @param {object} props
 * @param {React.ReactNode} props.children
 * @param {'white' | 'lightGray'} [props.backgroundColor]
 * @param {import('react-native-safe-area-context').Edge[]} [props.edges] Safe-area edges (omit `top` when a parent stack header already handles the top inset).
 */
export default function ScreenContainer({
  children,
  backgroundColor = 'white',
  edges = ['top', 'left', 'right'],
}) {
  const bg =
    backgroundColor === 'lightGray' ? colors.lightGray : colors.white;

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: bg }]} edges={edges}>
      <View style={[styles.inner, { backgroundColor: bg }]}>{children}</View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  inner: { flex: 1 },
});
