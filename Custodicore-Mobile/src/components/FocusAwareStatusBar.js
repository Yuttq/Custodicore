import { useIsFocused } from '@react-navigation/native';
import { StatusBar } from 'expo-status-bar';
import React from 'react';

/**
 * Status bar style for one screen, applied only while that screen is focused.
 *
 * Status bar instances form a stack (last mounted wins; unmounting restores the
 * rest), so mounting it only while focused means screens that never render one
 * keep their current status bar — whichever order focus/blur fire in.
 *
 * @param {{ style?: 'light' | 'dark' | 'auto' }} props
 */
export default function FocusAwareStatusBar({ style = 'light' }) {
  const isFocused = useIsFocused();
  return isFocused ? <StatusBar style={style} /> : null;
}
