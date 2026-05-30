import React from 'react';
import { StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { colors } from '../constants';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';

/**
 * @param {object} props
 * @param {React.ReactNode} props.children
 * @param {'white' | 'lightGray'} [props.backgroundColor]
 * @param {import('react-native-safe-area-context').Edge[]} [props.edges] Safe-area edges (omit `top` when a parent stack header already handles the top inset).
 * @param {boolean} [props.tabScreen] Reserve space above the bottom tab bar for scrollable content.
 */
export default function ScreenContainer({
  children,
  backgroundColor = 'white',
  edges = ['top', 'left', 'right'],
  tabScreen = false,
}) {
  const bg =
    backgroundColor === 'lightGray' ? colors.lightGray : colors.white;
  const tabBarInset = useTabBarScrollInset(0);

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: bg }]} edges={edges}>
      <View
        style={[
          styles.inner,
          { backgroundColor: bg },
          tabScreen && { paddingBottom: tabBarInset },
        ]}
      >
        {children}
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  inner: { flex: 1 },
});
