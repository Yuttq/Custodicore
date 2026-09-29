import React, { useMemo } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors } from '../tokens/colors';
import { layout, spacing } from '../tokens/spacing';
import { typography } from '../tokens/typography';

const VARIANTS = {
  confirmed: { label: 'Attendance Confirmed', bg: colors.success, fg: colors.white },
  pending: { label: 'Pending', bg: colors.warning, fg: colors.textPrimary },
  pending_confirmation: {
    label: 'Pending Confirmation',
    bg: colors.warning,
    fg: colors.textPrimary,
  },
  qr_ready: {
    label: 'QR Ready',
    bg: 'rgba(37, 99, 235, 0.12)',
    fg: colors.info,
  },
  checked_in: { label: 'Checked-In', bg: colors.success, fg: colors.white },
  scheduled: {
    label: 'Scheduled',
    bg: 'rgba(37, 99, 235, 0.12)',
    fg: colors.info,
  },
  cancelled: { label: 'Cancelled', bg: colors.danger, fg: colors.white },
  unable_to_attend: {
    label: 'Unable To Attend',
    bg: colors.danger,
    fg: colors.white,
  },
  completed: {
    label: 'Completed',
    bg: 'rgba(107, 114, 128, 0.15)',
    fg: colors.textPrimary,
  },
  verified: { label: 'Verified', bg: colors.success, fg: colors.white },
  pending_verification: {
    label: 'Pending Verification',
    bg: colors.warning,
    fg: colors.textPrimary,
  },
  document_pending: {
    label: 'Pending',
    bg: 'rgba(245, 158, 11, 0.15)',
    fg: colors.textPrimary,
  },
  document_uploaded: {
    label: 'Uploaded',
    bg: 'rgba(37, 99, 235, 0.12)',
    fg: colors.info,
  },
  document_under_review: {
    label: 'Under Review',
    bg: 'rgba(245, 158, 11, 0.18)',
    fg: colors.textPrimary,
  },
  document_verified: {
    label: 'Verified',
    bg: colors.success,
    fg: colors.white,
  },
  document_rejected: {
    label: 'Rejected',
    bg: colors.danger,
    fg: colors.white,
  },
  verification_pending: {
    label: 'Pending',
    bg: 'rgba(245, 158, 11, 0.15)',
    fg: colors.textPrimary,
  },
  verification_under_review: {
    label: 'Under Review',
    bg: 'rgba(245, 158, 11, 0.18)',
    fg: colors.textPrimary,
  },
  verification_verified: {
    label: 'Verified',
    bg: colors.success,
    fg: colors.white,
  },
  verification_rejected: {
    label: 'Rejected',
    bg: colors.danger,
    fg: colors.white,
  },
};

/**
 * v2.1 Status chip
 * @param {object} props
 * @param {keyof typeof VARIANTS} props.status
 * @param {string} [props.label]
 */
export function StatusChip({ status, label }) {
  const variant = useMemo(() => {
    if (status && status in VARIANTS) return VARIANTS[status];
    return VARIANTS.pending;
  }, [status]);

  const finalLabel = label ?? variant.label;

  return (
    <View
      accessibilityRole="text"
      accessibilityLabel={`Status: ${finalLabel}`}
      style={[styles.base, { backgroundColor: variant.bg }]}
    >
      <Text style={[styles.text, { color: variant.fg }]}>{finalLabel}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  base: {
    alignSelf: 'flex-start',
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
    borderRadius: layout.chipRadius,
  },
  text: {
    ...typography.statusLabel,
    letterSpacing: 0.2,
  },
});

export default StatusChip;
