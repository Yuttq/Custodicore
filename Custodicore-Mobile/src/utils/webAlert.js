import { Alert, Platform } from 'react-native';

/**
 * React Native Web ships `Alert.alert` as a no-op, so every confirmation in
 * the app (log out, decline a visit, ...) silently did nothing in the browser.
 * On web only, this routes `Alert.alert` through the browser's own dialogs:
 *
 * - no buttons / one button  -> window.alert, then that button's onPress
 * - one action + Cancel      -> window.confirm: OK runs the action
 * - several actions          -> window.confirm per action, first OK wins
 *
 * Native (Android / iOS) keeps the real Alert. Imported once from App.js.
 */
export function installWebAlert() {
  if (Platform.OS !== 'web' || typeof window === 'undefined') {
    return;
  }

  Alert.alert = (title, message, buttons) => {
    const text = [title, message].filter(Boolean).join('\n\n');
    const list = Array.isArray(buttons) ? buttons : [];
    const cancel = list.find((b) => b?.style === 'cancel');
    const actions = list.filter((b) => b && b !== cancel);

    if (actions.length <= 1 && !cancel) {
      window.alert(text);
      actions[0]?.onPress?.();
      return;
    }

    for (const action of actions) {
      const prompt = actions.length === 1 ? text : `${text}\n\n${action.text}?`;
      if (window.confirm(prompt)) {
        action.onPress?.();
        return;
      }
    }

    cancel?.onPress?.();
  };
}
