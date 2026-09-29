import { Platform } from 'react-native';
import { layout } from '../designSystem';

/** Center FAB lift above the tab row — keep in sync with BottomNav. */
export const TAB_BAR_QR_LIFT = 32;

/** Tab row height (icons + side labels + QR label baseline). */
export const TAB_BAR_ROW_MIN_HEIGHT = layout.tabBarRowMinHeight;

/**
 * Bottom inset inside the tab bar: gesture bar, 3-button nav, or minimum padding.
 * Adds a small Android gesture-nav buffer to avoid label clipping at the screen edge.
 */
export function getTabBarBottomInset(insets) {
  const safeBottom = insets?.bottom ?? 0;
  const base = Math.max(safeBottom, layout.tabBarPaddingBottomMin);
  const gestureBuffer =
    Platform.OS === 'android' && safeBottom > 0 ? layout.tabBarGestureBuffer : 0;
  return base + gestureBuffer;
}

/**
 * Tab bar content block height (row + QR label reserve), excluding top padding and safe bottom.
 */
export function getTabBarContentHeight() {
  return layout.tabBarRowMinHeight + layout.tabBarQrLabelReserve;
}

/**
 * Total vertical space the bottom tab bar occupies on screen (for scroll insets).
 */
export function getTabBarTotalHeight(insets) {
  return layout.tabBarPaddingTop + getTabBarContentHeight() + getTabBarBottomInset(insets);
}
