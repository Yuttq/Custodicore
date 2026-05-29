import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { StyleSheet, View } from 'react-native';

/**
 * Ionicons per main tab: solid when focused, outline when inactive — clearer at a glance.
 * Add routes here only when a new tab is registered in `AppNavigator` `MainTabs`.
 */
const TAB_ICONS = {
  Dashboard: { focused: 'home', unfocused: 'home-outline' },
  Schedule: { focused: 'calendar', unfocused: 'calendar-outline' },
  QR: { focused: 'qr-code', unfocused: 'qr-code-outline' },
  Notifications: { focused: 'notifications', unfocused: 'notifications-outline' },
  Profile: { focused: 'person', unfocused: 'person-outline' },
};

const ICON_SIZE = 24;

/**
 * @param {object} props
 * @param {keyof typeof TAB_ICONS} props.routeName
 * @param {string} props.color
 * @param {number} [props.size]
 * @param {boolean} props.focused
 */
export function MainTabBarIcon({ routeName, color, size, focused }) {
  const glyphs = TAB_ICONS[routeName];
  if (!glyphs) return null;

  const iconSize = typeof size === 'number' && size > 0 ? size : ICON_SIZE;
  const name = focused ? glyphs.focused : glyphs.unfocused;

  return (
    <View style={styles.iconSlot} pointerEvents="box-none">
      <Ionicons name={name} size={iconSize} color={color} />
    </View>
  );
}

const styles = StyleSheet.create({
  iconSlot: {
    height: 28,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
