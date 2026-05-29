import { Platform } from 'react-native';

/**
 * Shared elevation / shadow tokens for cards, surfaces, and chrome.
 * Keeps iOS and Android visually aligned without ad-hoc values per screen.
 */
export const shadows = {
  card: Platform.select({
    ios: {
      shadowColor: '#0f172a',
      shadowOffset: { width: 0, height: 1 },
      shadowOpacity: 0.07,
      shadowRadius: 6,
    },
    android: { elevation: 2 },
    default: {},
  }),
  /** Bottom tab bar — subtle separation from content. */
  tabBar: Platform.select({
    ios: {
      shadowColor: '#0f172a',
      shadowOffset: { width: 0, height: -2 },
      shadowOpacity: 0.08,
      shadowRadius: 5,
    },
    android: { elevation: 6 },
    default: {},
  }),
  /** Raised surface (e.g. QR frame) — slightly stronger than `card`. */
  surface: Platform.select({
    ios: {
      shadowColor: '#0f172a',
      shadowOffset: { width: 0, height: 2 },
      shadowOpacity: 0.08,
      shadowRadius: 8,
    },
    android: { elevation: 3 },
    default: {},
  }),
};

export default shadows;
