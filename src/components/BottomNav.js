import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback } from 'react';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import Animated, {
  useAnimatedStyle,
  useSharedValue,
  withTiming,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, layout, shadows } from '../designSystem';
import { useNotificationBadge } from '../context/NotificationBadgeContext';

const TAB_PADDING_TOP = layout.tabBarPaddingTop;
const TAB_PADDING_BOTTOM_MIN = layout.tabBarPaddingBottomMin;
const QR_BUTTON_SIZE = layout.qrButtonSize;
const QR_LIFT = 12;
const PRESS_DURATION_MS = 150;

const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

/**
 * Custom bottom tab bar — center QR Pass elevated (GCash / Maya style).
 * @param {import('@react-navigation/bottom-tabs').BottomTabBarProps} props
 */
export default function BottomNav({ state, descriptors, navigation }) {
  const insets = useSafeAreaInsets();
  const { unreadCount } = useNotificationBadge();
  const paddingBottom = Math.max(insets.bottom, TAB_PADDING_BOTTOM_MIN);

  const onTabPress = useCallback(
    (route, isFocused) => {
      const event = navigation.emit({
        type: 'tabPress',
        target: route.key,
        canPreventDefault: true,
      });
      if (!isFocused && !event.defaultPrevented) {
        navigation.navigate(route.name, route.params);
      }
    },
    [navigation],
  );

  const onTabLongPress = useCallback(
    (route) => {
      navigation.emit({
        type: 'tabLongPress',
        target: route.key,
      });
    },
    [navigation],
  );

  const qrRoute = state.routes.find((r) => r.name === 'QR');
  const qrIndex = state.routes.findIndex((r) => r.name === 'QR');
  const qrFocused = state.index === qrIndex;
  const qrDescriptor = qrRoute ? descriptors[qrRoute.key] : null;

  return (
    <View
      style={[
        styles.wrapper,
        shadows.tabBar,
        { paddingTop: TAB_PADDING_TOP, paddingBottom },
      ]}
    >
      <View style={styles.row}>
        {state.routes.map((route, index) => {
          if (route.name === 'QR') {
            return <View key={route.key} style={styles.qrSlot} />;
          }

          const { options } = descriptors[route.key];
          const isFocused = state.index === index;
          const label =
            options.tabBarLabel !== undefined
              ? options.tabBarLabel
              : options.title !== undefined
                ? options.title
                : route.name;
          const activeTint = options.tabBarActiveTintColor ?? colors.primaryTeal;
          const inactiveTint = options.tabBarInactiveTintColor ?? colors.textSecondary;
          const tint = isFocused ? activeTint : inactiveTint;
          const showBadge = route.name === 'Notifications' && unreadCount > 0;

          return (
            <Pressable
              key={route.key}
              accessibilityRole="button"
              accessibilityState={isFocused ? { selected: true } : {}}
              accessibilityLabel={options.tabBarAccessibilityLabel ?? String(label)}
              onPress={() => onTabPress(route, isFocused)}
              onLongPress={() => onTabLongPress(route)}
              style={({ pressed }) => [styles.tab, pressed && styles.tabPressed]}
            >
              <View style={styles.iconWrap}>
                {options.tabBarIcon?.({
                  focused: isFocused,
                  color: tint,
                  size: 24,
                })}
                {showBadge ? (
                  <View style={styles.badge}>
                    <Text style={styles.badgeText}>
                      {unreadCount > 99 ? '99+' : unreadCount}
                    </Text>
                  </View>
                ) : null}
              </View>
              <Text style={[styles.label, { color: tint }, isFocused && styles.labelFocused]}>
                {label}
              </Text>
            </Pressable>
          );
        })}
      </View>

      {qrRoute && qrDescriptor ? (
        <QrCenterTabButton
          focused={qrFocused}
          label={
            qrDescriptor.options.tabBarLabel ??
            qrDescriptor.options.title ??
            'QR Pass'
          }
          accessibilityLabel={
            qrDescriptor.options.tabBarAccessibilityLabel ?? 'QR pass tab'
          }
          onPress={() => onTabPress(qrRoute, qrFocused)}
          onLongPress={() => onTabLongPress(qrRoute)}
        />
      ) : null}
    </View>
  );
}

function QrCenterTabButton({ focused, label, accessibilityLabel, onPress, onLongPress }) {
  const scale = useSharedValue(1);

  const animatedStyle = useAnimatedStyle(() => ({
    transform: [{ scale: scale.value }],
  }));

  const onPressIn = () => {
    scale.value = withTiming(0.98, { duration: PRESS_DURATION_MS });
  };

  const onPressOut = () => {
    scale.value = withTiming(1, { duration: PRESS_DURATION_MS });
  };

  return (
    <View style={styles.qrOuter} pointerEvents="box-none">
      <AnimatedPressable
        onPress={onPress}
        onLongPress={onLongPress}
        onPressIn={onPressIn}
        onPressOut={onPressOut}
        accessibilityRole="button"
        accessibilityState={focused ? { selected: true } : {}}
        accessibilityLabel={accessibilityLabel}
        style={[styles.qrPressable, animatedStyle]}
      >
        <View style={[styles.qrButton, shadows.qrFab]}>
          <Ionicons name="qr-code" size={30} color={colors.white} />
        </View>
        <Text style={[styles.qrLabel, focused && styles.qrLabelFocused]}>{label}</Text>
      </AnimatedPressable>
    </View>
  );
}

const styles = StyleSheet.create({
  wrapper: {
    backgroundColor: colors.white,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
    minHeight: layout.tabBarHeight,
    overflow: 'visible',
  },
  row: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    minHeight: 48,
  },
  tab: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'flex-end',
    paddingVertical: 4,
  },
  tabPressed: {
    opacity: 0.72,
  },
  iconWrap: {
    height: 28,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 4,
  },
  label: {
    fontSize: 11,
    fontWeight: '500',
    letterSpacing: 0.2,
    marginBottom: Platform.OS === 'ios' ? 0 : 2,
  },
  labelFocused: {
    fontWeight: '600',
  },
  qrSlot: {
    flex: 1,
  },
  qrOuter: {
    position: 'absolute',
    left: 0,
    right: 0,
    top: -QR_LIFT,
    alignItems: 'center',
    pointerEvents: 'box-none',
  },
  qrPressable: {
    alignItems: 'center',
  },
  qrButton: {
    width: QR_BUTTON_SIZE,
    height: QR_BUTTON_SIZE,
    borderRadius: QR_BUTTON_SIZE / 2,
    backgroundColor: colors.primaryTeal,
    alignItems: 'center',
    justifyContent: 'center',
  },
  qrLabel: {
    marginTop: 6,
    fontSize: 11,
    fontWeight: '600',
    color: colors.textSecondary,
    letterSpacing: 0.2,
  },
  qrLabelFocused: {
    color: colors.primaryTeal,
  },
  badge: {
    position: 'absolute',
    top: -4,
    right: -10,
    minWidth: 18,
    height: 18,
    borderRadius: 9,
    backgroundColor: colors.danger,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 4,
    borderWidth: 1.5,
    borderColor: colors.white,
  },
  badgeText: {
    fontSize: 10,
    fontWeight: '700',
    color: colors.white,
  },
});
