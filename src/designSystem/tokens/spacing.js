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
  cardGap: spacing.md,
  pageTitleGap: spacing.lg,
  sectionGap: spacing.lg,

  buttonHeight: 48,
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
