/**
 * Shared text styles — hierarchy for enterprise / government UI density.
 */
export const typography = {
  /** Screen titles in headers (unchanged consumer usage) */
  title: { fontSize: 20, fontWeight: 'bold' },
  /** Welcome / hero line on dashboard */
  display: { fontSize: 22, fontWeight: '700', letterSpacing: -0.2 },
  /** Section titles, list primary lines */
  headline: { fontSize: 16, fontWeight: '600' },
  body: { fontSize: 14, fontWeight: 'normal' },
  bodyStrong: { fontSize: 14, fontWeight: '600' },
  caption: { fontSize: 12, fontWeight: 'normal' },
  captionStrong: { fontSize: 12, fontWeight: '600' },
  /** Eyebrow / meta labels (pair with textTransform: 'uppercase' in StyleSheet) */
  meta: { fontSize: 11, fontWeight: '600', letterSpacing: 0.5 },
  button: { fontSize: 16, fontWeight: '600' },
};

export default typography;
