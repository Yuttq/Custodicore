import { useIsFocused } from '@react-navigation/native';
import { useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { AUTO_REFRESH_MS, cameToForeground, shouldPoll } from '../utils/autoRefresh';

/**
 * Keeps an open screen up to date without pull-to-refresh: calls `refresh`
 * every `intervalMs` while the screen is focused and the app is in front,
 * and once right away when the app comes back from the background (or, on
 * web, when the tab becomes visible again). Nothing runs in the background.
 *
 * `refresh` must be quiet: no loading spinner, and keep the current data if
 * it fails. Calls never overlap. Pull-to-refresh and focus reloads stay as
 * they are; this only adds the automatic ones.
 *
 * @param {() => unknown} refresh
 * @param {{ intervalMs?: number, enabled?: boolean }} [options]
 */
export default function useAutoRefresh(refresh, { intervalMs = AUTO_REFRESH_MS, enabled = true } = {}) {
  const focused = useIsFocused();
  const [appState, setAppState] = useState(AppState.currentState);

  const refreshRef = useRef(refresh);
  refreshRef.current = refresh;
  const running = useRef(false);
  const run = useRef(async () => {
    if (running.current) return;
    running.current = true;
    try {
      await refreshRef.current();
    } catch {
      // Quiet: keep what is on screen; the next tick tries again.
    } finally {
      running.current = false;
    }
  }).current;

  // Track foreground/background; reload on return if this screen is open.
  const focusedRef = useRef(focused);
  focusedRef.current = focused;
  const enabledRef = useRef(enabled);
  enabledRef.current = enabled;
  useEffect(() => {
    let previous = AppState.currentState;
    const sub = AppState.addEventListener('change', (next) => {
      if (cameToForeground(previous, next) && focusedRef.current && enabledRef.current) run();
      previous = next;
      setAppState(next);
    });
    return () => sub.remove();
  }, [run]);

  const polling = shouldPoll(focused, appState, enabled);
  useEffect(() => {
    if (!polling) return undefined;
    const id = setInterval(run, intervalMs);
    return () => clearInterval(id);
  }, [polling, intervalMs, run]);
}
