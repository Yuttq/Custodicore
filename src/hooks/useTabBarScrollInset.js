import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { getTabBarTotalHeight } from '../utils/tabBarMetrics';

/**
 * Bottom padding for scrollable tab content so it clears the custom tab bar
 * and Android system navigation (gesture bar or 3-button) without overlapping.
 * @param {number} [extra=16] — additional breathing room above the tab bar
 */
export function useTabBarScrollInset(extra = 16) {
  const insets = useSafeAreaInsets();
  return getTabBarTotalHeight(insets) + extra;
}

export default useTabBarScrollInset;
