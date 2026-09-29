import { StyleSheet } from 'react-native';
import { colors } from '../tokens/colors';
import { layout, spacing } from '../tokens/spacing';
import { typography } from '../tokens/typography';

/** Shared layout patterns — screens, headers, segmented controls. */
export const commonStyles = StyleSheet.create({
  safeScreen: {
    flex: 1,
    backgroundColor: colors.background,
  },
  scrollContent: {
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.xl,
  },
  stackHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: layout.screenPadding,
    paddingVertical: spacing.sm,
  },
  backButton: {
    width: layout.iconButtonSize,
    height: layout.iconButtonSize,
    borderRadius: layout.iconButtonSize / 2,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  backPlaceholder: {
    width: layout.iconButtonSize,
  },
  stackTitleWrap: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    marginHorizontal: spacing.sm,
  },
  stackTitle: {
    ...typography.screenHeader,
    color: colors.textPrimary,
    textAlign: 'center',
  },
  stackEyebrow: {
    ...typography.eyebrow,
    color: colors.textSecondary,
    textAlign: 'center',
    marginBottom: spacing.xs,
  },
  pageIntro: {
    marginBottom: spacing.lg,
  },
  pageTitle: {
    ...typography.pageTitle,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  pageSubtitle: {
    ...typography.body,
    color: colors.textSecondary,
    lineHeight: 24,
  },
  sectionLabel: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  sectionBlock: {
    marginBottom: layout.sectionGap,
  },
  fieldError: {
    ...typography.metadata,
    color: colors.danger,
    marginTop: spacing.sm,
  },
  segmentedControl: {
    flexDirection: 'row',
    marginHorizontal: layout.screenPadding,
    marginBottom: spacing.md,
    backgroundColor: colors.card,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.border,
    padding: spacing.xs,
  },
  segmentedTab: {
    flex: 1,
    paddingVertical: spacing.sm,
    alignItems: 'center',
    borderRadius: layout.borderRadiusSm,
  },
  segmentedTabActive: {
    backgroundColor: colors.primaryTeal,
  },
  segmentedTabLabel: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textSecondary,
  },
  segmentedTabLabelActive: {
    color: colors.white,
  },
  actionsStack: {
    gap: spacing.sm,
    marginTop: spacing.md,
  },
});

export default commonStyles;
