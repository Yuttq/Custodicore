/**
 * Navigates back when any ancestor stack allows it; otherwise navigates to a fallback route.
 * Prevents dev warnings: "The action 'GO_BACK' was not handled by any navigator."
 *
 * @param {import('@react-navigation/native').NavigationProp<Record<string, object | undefined>>} navigation
 * @param {{ name: string; params?: object }} [fallback]
 */
export function goBackOr(navigation, fallback) {
  const target = fallback ?? { name: 'MainTabs', params: { screen: 'Dashboard' } };

  let nav = navigation;
  while (nav) {
    if (typeof nav.canGoBack === 'function' && nav.canGoBack()) {
      nav.goBack();
      return;
    }
    nav = typeof nav.getParent === 'function' ? nav.getParent() : undefined;
  }

  navigation.navigate(target.name, target.params);
}

export default goBackOr;
