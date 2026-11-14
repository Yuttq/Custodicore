/**
 * CustodiCore Visitor App v2.1 — color tokens.
 * Source of truth: provided mock + spec in Phase 1.
 *
 * The bright brand/status colors (primaryTeal, success, warning, danger) are
 * kept for decorative use: icons, tints, borders and accents. They fall below
 * WCAG AA as text on white, so text and filled controls carrying white text
 * use the contrast-safe variants below (ratios measured against white).
 */
export const colors = {
  primaryNavy: '#0F3D7A',
  primaryTeal: '#0DA58A',
  /** Primary button fills and teal text (5.26:1) */
  primaryTealDark: '#0A7A67',

  success: '#16A34A',
  warning: '#F59E0B',
  danger: '#EF4444',

  /**
   * Success text on white or light neutral backgrounds, and fills with white
   * text (5.02:1). Not guaranteed AA on green status tints (~4.39:1).
   */
  successStrong: '#15803D',
  /**
   * Warning text on white or light neutral backgrounds (5.02:1). Not
   * guaranteed AA on amber status tints (~4.38:1).
   */
  warningText: '#B45309',
  /**
   * Success text on green status tints, e.g. the QR "Inside Facility" banner
   * (6.24:1 on a 12% green tint, where successStrong is only ~4.39:1).
   */
  successText: '#166534',
  /** Danger text and destructive fills with white text (6.47:1) */
  dangerStrong: '#B91C1C',

  background: '#F8FAFC',
  card: '#FFFFFF',
  border: '#E5E7EB',

  textPrimary: '#111827',
  textSecondary: '#6B7280',
  /** Lowest-emphasis readable text; still AA on white (4.83:1) */
  textMuted: '#6B7280',
  /** Disabled labels only — exempt from WCAG contrast (2.54:1) */
  textDisabled: '#9CA3AF',

  /** Informational status / accents */
  info: '#2563EB',

  /**
   * Navy surfaces (Login, splash, Home/My Visits header band) — matches
   * CustodiCore Web. Ratios measured against primaryNavy.
   */
  navySurface: '#0F3D7A',
  /** Primary text on navy (10.67:1) */
  textOnNavy: '#FFFFFF',
  /** Secondary text on navy (7.18:1) */
  textOnNavyMuted: '#CBD5E1',
  /** Hairlines / rings on navy — decorative only */
  borderOnNavy: 'rgba(255, 255, 255, 0.35)',

  white: '#FFFFFF',
};

export default colors;
