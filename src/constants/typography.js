/**
 * @deprecated Import from `src/designSystem`.
 */
export { typography } from '../designSystem/tokens/typography';

import { typography as t } from '../designSystem/tokens/typography';

export default {
  title: t.pageTitle,
  display: t.cardTitle,
  headline: { ...t.body, fontWeight: '600' },
  body: t.metadata,
  bodyStrong: { ...t.metadata, fontWeight: '600' },
  caption: t.statusLabel,
  captionStrong: t.statusLabel,
  meta: t.eyebrow,
  button: { ...t.body, fontWeight: '600' },
};
