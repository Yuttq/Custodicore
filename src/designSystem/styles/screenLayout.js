import { StyleSheet } from 'react-native';
import { colors } from '../tokens/colors';
import { layout, spacing } from '../tokens/spacing';
import { typography } from '../tokens/typography';

/** Shared screen layout — page title → section → card rhythm. */
export const screenLayout = StyleSheet.create({
  scrollContent: {
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.xl,
  },
  pageTitle: {
    ...typography.pageTitle,
    color: colors.textPrimary,
    marginBottom: layout.pageTitleGap,
  },
  sectionLabel: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  sectionBlock: {
    marginBottom: layout.sectionGap,
  },
  cardStackGap: {
    marginBottom: layout.cardGap,
  },
});

export default screenLayout;
