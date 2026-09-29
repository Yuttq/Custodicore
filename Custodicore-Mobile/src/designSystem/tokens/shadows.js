import { Platform } from 'react-native';

/**
 * CustodiCore Visitor App v2.1 — very subtle modern shadows.
 * Intentionally light; cards still keep a 1px border.
 */
export const shadows = {
  card: Platform.select({
    ios: {
      shadowColor: '#111827',
      shadowOffset: { width: 0, height: 1 },
      shadowOpacity: 0.06,
      shadowRadius: 6,
    },
    android: { elevation: 1 },
    default: {},
  }),
  /** Elevated center QR tab — subtle, banking-style. */
  qrFab: Platform.select({
    ios: {
      shadowColor: '#0f172a',
      shadowOffset: { width: 0, height: 4 },
      shadowOpacity: 0.14,
      shadowRadius: 8,
    },
    android: { elevation: 6 },
    default: {},
  }),
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
};

export default shadows;
