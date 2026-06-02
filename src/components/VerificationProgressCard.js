import React, { useMemo } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, layout, spacing, typography } from '../designSystem';
import {
  getVerificationProgress,
  getVerificationSummaryTitle,
} from '../utils/verificationDocumentUi';

/**
 * @param {object} props
 * @param {import('../mock/visitorVerificationDocuments.mock').MockVerificationDocument[]} props.documents
 * @param {import('../mock/visitorVerificationDocuments.mock').VisitorVerificationStatus} props.overallStatus
 */
export default function VerificationProgressCard({ documents, overallStatus }) {
  const { verifiedCount, total, percent } = useMemo(
    () => getVerificationProgress(documents),
    [documents],
  );
  const title = useMemo(
    () => getVerificationSummaryTitle(documents, overallStatus),
    [documents, overallStatus],
  );

  const progressColor =
    percent === 100 ? colors.success : percent > 0 ? colors.primaryTeal : colors.border;

  return (
    <View style={styles.card}>
      <Text style={styles.title}>{title}</Text>
      <Text style={styles.meta}>
        {verifiedCount} of {total} document{total === 1 ? '' : 's'} verified
      </Text>
      <View style={styles.progressTrack}>
        <View style={[styles.progressFill, { width: `${percent}%`, backgroundColor: progressColor }]} />
      </View>
      <Text style={styles.percentLabel}>{percent}%</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    marginBottom: layout.cardGap,
  },
  title: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.xs,
  },
  meta: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  progressTrack: {
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.border,
    overflow: 'hidden',
    marginBottom: spacing.sm,
  },
  progressFill: {
    height: '100%',
    borderRadius: 4,
  },
  percentLabel: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textSecondary,
  },
});
