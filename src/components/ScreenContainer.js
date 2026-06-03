import React from 'react';
import { StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { colors } from '../designSystem';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';

/**
 * @param {object} props
 * @param {React.ReactNode} props.children
 * @param {'white' | 'lightGray'} [props.backgroundColor]
 * @param {import('react-native-safe-area-context').Edge[]} [props.edges]
 * @param {boolean} [props.tabScreen]
 */
export default function ScreenContainer({
  children,
  backgroundColor = 'white',
  edges = ['top', 'left', 'right'],
  tabScreen = false,
}) {
  const bg = backgroundColor === 'lightGray' ? colors.background : colors.white;
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
