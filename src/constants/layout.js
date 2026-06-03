/**
 * @deprecated Import `layout` and `spacing` from `src/designSystem`.
 */
import { layout as dsLayout, spacing } from '../designSystem/tokens/spacing';

export const layout = {
  ...dsLayout,
  minTouchHeight: dsLayout.buttonHeight,
  borderRadius: dsLayout.cardRadius,
  borderRadiusSm: dsLayout.borderRadiusSm,
  spacing,
};

export default layout;
