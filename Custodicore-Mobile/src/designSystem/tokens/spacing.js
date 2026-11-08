/**
 * CustodiCore spacing scale — use tokens only (no arbitrary px).
 * sm = 8 | md = 16 | lg = 24 | xl = 32
 * xs = 4 micro-gaps only (label-to-input, hairline adjacency)
 */
export const spacing = {
  xs: 4,
  sm: 8,
  md: 16,
  lg: 24,
  xl: 32,
};

export const layout = {
  screenPadding: spacing.md,
  cardPadding: spacing.md,
  // Compact rhythm (Nov 2026): 12 between cards, 16 between sections.
  cardGap: spacing.sm + spacing.xs,
  pageTitleGap: spacing.md,
  sectionGap: spacing.md,

  /** Buttons and text inputs. Icon-only controls keep iconButtonSize (48). */
  buttonHeight: 44,
  /** Minimum touch target for icon-only controls */
  iconButtonSize: 48,

  tabBarRowMinHeight: 56,
  tabBarQrLabelReserve: spacing.xs,
  tabBarHeight: 56 + spacing.xs,
  qrButtonSize: 64,
  tabBarPaddingTop: spacing.sm,
  tabBarPaddingBottomMin: spacing.lg,
  tabBarGestureBuffer: spacing.xs,

  cardRadius: spacing.md,
  buttonRadius: spacing.sm + spacing.xs,
  borderRadiusSm: spacing.sm,
  chipRadius: 999,
};

export default spacing;
