import React, { useMemo } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Button,
  StackScreenHeader,
  StatusChip,
  colors,
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { LoadingSpinner } from '../components';
import useVisitorVerification from '../hooks/useVisitorVerification';
import { formatDate, formatTime } from '../utils';
import { goBackOr } from '../utils/safeNavigation';
import {
  documentWorkflowStatusToChip,
  getDocumentDetailAction,
  getDocumentDetailActionLabel,
  isDocumentVerified,
} from '../utils/verificationDocumentUi';

/**
 * @param {string | null | undefined} isoDate
 */
function formatDocumentDate(isoDate) {
  if (!isoDate) return null;
  const datePart = formatDate(isoDate);
  const timePart = formatTime(isoDate);
  return [datePart, timePart].filter(Boolean).join(' · ');
}

function DetailField({ label, value }) {
  return (
    <View style={styles.detailField}>
      <Text style={styles.detailLabel}>{label}</Text>
      <Text style={styles.detailValue}>{value}</Text>
    </View>
  );
}

/**
 * Single verification document — real status from GET /api/documents; upload actions by status only (v2.1).
 */
export default function VerificationDocumentDetailScreen({ navigation, route }) {
  const documentKey = route.params?.documentKey;
  const { verification, loading, error, reload } = useVisitorVerification();

  const document = useMemo(
    () => verification?.documents.find((doc) => doc.key === documentKey) ?? null,
    [verification, documentKey],
  );

  if (loading && !verification) {
    return (
      <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
        <StackScreenHeader title="Document Details" navigation={navigation} />
        <View style={styles.missingWrap}>
          <LoadingSpinner message="Loading document…" compact />
        </View>
      </SafeAreaView>
    );
  }

  if (!document) {
    return (
      <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
        <StackScreenHeader title="Document Details" navigation={navigation} />
        <View style={styles.missingWrap}>
          <Text style={styles.missingText}>{error || 'Document not found.'}</Text>
          {error ? <Button title="Retry" onPress={reload} /> : null}
          <Button title="Go Back" variant="secondary" onPress={() => goBackOr(navigation)} />
        </View>
      </SafeAreaView>
    );
  }

  const detailAction = document.canUpload ? getDocumentDetailAction(document.uploadStatus) : null;
  const actionLabel = document.canUpload ? getDocumentDetailActionLabel(document.uploadStatus) : null;

  const uploadDate =
    document.uploadStatus === 'pending'
      ? 'Not uploaded yet'
      : formatDocumentDate(document.uploadedAt) || '—';
  const verificationDate = formatDocumentDate(document.verifiedAt) || '—';
  const officerRemarks = document.reviewNote?.trim() || '—';

  const openUpload = () => {
    if (isDocumentVerified(document.uploadStatus)) {
      Alert.alert(
        'Document locked',
        'Verified documents cannot be replaced or modified.',
      );
      return;
    }
    navigation.navigate('UploadID', { documentKey: document.key });
  };

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Document Details" navigation={navigation} />

      <ScrollView
        contentContainerStyle={commonStyles.scrollContent}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        <View style={styles.panel}>
          <Text style={styles.docTitle}>{document.label}</Text>
          <View style={styles.statusBadgeWrap}>
            <StatusChip status={documentWorkflowStatusToChip(document.uploadStatus)} />
          </View>

          {document.detail ? <DetailField label="Details" value={document.detail} /> : null}
          <DetailField label="Upload Date" value={uploadDate} />
          <DetailField label="Verification Date" value={verificationDate} />
          <DetailField label="Officer Remarks" value={officerRemarks} />

          {document.uploadStatus === 'rejected' ? (
            <View style={styles.rejectionBox}>
              <Text style={styles.rejectionTitle}>Rejected</Text>
              <Text style={styles.rejectionBody}>
                {document.rejectionReason ||
                  'Facility staff rejected this document. Please upload a new, clear copy or contact the facility.'}
              </Text>
            </View>
          ) : null}

          {document.unavailableReason ? (
            <Text style={styles.detailValue}>{document.unavailableReason}</Text>
          ) : null}

          {detailAction && actionLabel ? (
            <View style={styles.actionButtonWrap}>
              <Button
                title={actionLabel}
                variant={detailAction === 'upload' || detailAction === 'upload_new' ? 'primary' : 'secondary'}
                onPress={openUpload}
                accessibilityLabel={actionLabel}
              />
            </View>
          ) : null}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  panel: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    padding: spacing.md,
  },
  docTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  statusBadgeWrap: {
    alignSelf: 'flex-start',
    marginBottom: spacing.md,
  },
  detailField: {
    marginBottom: spacing.md,
  },
  detailLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.xs,
  },
  detailValue: {
    ...typography.body,
    color: colors.textPrimary,
    lineHeight: 20,
  },
  rejectionBox: {
    marginTop: spacing.xs,
    marginBottom: spacing.md,
    padding: spacing.sm,
    borderRadius: layout.borderRadiusSm,
    backgroundColor: 'rgba(239, 68, 68, 0.06)',
    borderWidth: 1,
    borderColor: 'rgba(239, 68, 68, 0.2)',
  },
  rejectionTitle: {
    ...typography.statusLabel,
    color: colors.danger,
    marginBottom: spacing.xs,
  },
  rejectionBody: {
    ...typography.body,
    color: colors.textPrimary,
    lineHeight: 20,
  },
  actionButtonWrap: {
    marginTop: spacing.xs,
  },
  missingWrap: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.md,
    gap: spacing.md,
  },
  missingText: {
    ...typography.body,
    color: colors.textSecondary,
    textAlign: 'center',
  },
});
