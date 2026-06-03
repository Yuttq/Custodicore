import { StyleSheet } from 'react-native';
import { colors } from '../tokens/colors';
import { layout, spacing } from '../tokens/spacing';
import { typography } from '../tokens/typography';

/** Shared form field styles — inputs, labels, errors. */
export const formStyles = StyleSheet.create({
  field: {
    marginBottom: spacing.md,
  },
  label: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  input: {
    minHeight: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    backgroundColor: colors.white,
    color: colors.textPrimary,
    ...typography.body,
  },
  inputError: {
    borderColor: colors.danger,
  },
  passwordRow: {
    minHeight: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingHorizontal: spacing.md,
    backgroundColor: colors.white,
    flexDirection: 'row',
    alignItems: 'center',
  },
  passwordInput: {
    flex: 1,
    color: colors.textPrimary,
    ...typography.body,
    paddingVertical: spacing.sm,
  },
  iconButton: {
    width: layout.iconButtonSize - spacing.sm,
    height: layout.iconButtonSize - spacing.sm,
    borderRadius: (layout.iconButtonSize - spacing.sm) / 2,
    alignItems: 'center',
    justifyContent: 'center',
  },
  error: {
    ...typography.metadata,
    color: colors.danger,
    marginTop: spacing.xs,
  },
});

export default formStyles;
