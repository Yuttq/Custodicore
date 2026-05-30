import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { layout } from '../designSystem';

/**
 * Bottom padding for scrollable tab content so it clears the custom tab bar
 * and Android system navigation without overlapping.
 * @param {number} [extra=16] — additional breathing room above the tab bar
 */
export function useTabBarScrollInset(extra = 16) {
  const insets = useSafeAreaInsets();
  const bottomPad = Math.max(insets.bottom, layout.tabBarPaddingBottomMin);
  return layout.tabBarHeight + layout.tabBarPaddingTop + bottomPad + extra;
}

export default useTabBarScrollInset;
