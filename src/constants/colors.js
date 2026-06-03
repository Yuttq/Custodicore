/**
 * @deprecated Import from `src/designSystem` — legacy aliases preserved.
 */
import { colors as ds } from '../designSystem/tokens/colors';

export const colors = {
  ...ds,
  primary: ds.primaryNavy,
  lightGray: ds.background,
  dark: ds.textPrimary,
  borderLight: ds.border,
};

export default colors;
