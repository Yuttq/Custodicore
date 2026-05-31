import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useMemo } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, StatusChip, colors, layout, spacing, typography } from '../designSystem';
import { DEFAULT_LOCAL_PROFILE } from '../mock/profile.mock';
import {
  documentWorkflowStatusToChip,
  getMockVisitorVerification,
} from '../mock/visitorVerificationDocuments.mock';
import { formatDate, formatTime } from '../utils';
import {
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
 * Single verification document — view details; upload actions by status only (v2.1).
 */
export default function VerificationDocumentDetailScreen({ navigation, route }) {
  const relationshipId =
    route.params?.relationshipId ?? DEFAULT_LOCAL_PROFILE.relationshipToPdl ?? 'spouse';
  const documentKey = route.params?.documentKey;

  const document = useMemo(() => {
    const verification = getMockVisitorVerification(relationshipId);
    return verification.documents.find((doc) => doc.key === documentKey) ?? null;
  }, [relationshipId, documentKey]);

  const detailAction = document ? getDocumentDetailAction(document.uploadStatus) : null;
  const actionLabel = document ? getDocumentDetailActionLabel(document.uploadStatus) : null;

  if (!document) {
    return (
      <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
        <View style={styles.topBar}>
          <Pressable
            onPress={() => navigation.goBack()}
            style={styles.backButton}
            accessibilityRole="button"
            accessibilityLabel="Go back"
          >
            <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
          </Pressable>
          <Text style={styles.screenTitle}>Document Details</Text>
          <View style={styles.backPlaceholder} />
        </View>
        <View style={styles.missingWrap}>
          <Text style={styles.missingText}>Document not found.</Text>
          <Button title="Go Back" onPress={() => navigation.goBack()} />
        </View>
      </SafeAreaView>
    );
  }

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
    navigation.navigate('UploadID', {
      relationshipId,
      documentKey: document.key,
    });
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <View style={styles.topBar}>
        <Pressable
          onPress={() => navigation.goBack()}
          style={styles.backButton}
          accessibilityRole="button"
          accessibilityLabel="Go back"
        >
          <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
        </Pressable>
        <Text style={styles.screenTitle}>Document Details</Text>
        <View style={styles.backPlaceholder} />
      </View>

      <ScrollView
        contentContainerStyle={styles.scroll}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        <View style={styles.panel}>
          <Text style={styles.docTitle}>{document.label}</Text>
          <View style={styles.statusBadgeWrap}>
            <StatusChip status={documentWorkflowStatusToChip(document.uploadStatus)} />
          </View>

          <DetailField label="Upload Date" value={uploadDate} />
          <DetailField label="Verification Date" value={verificationDate} />
          <DetailField label="Officer Remarks" value={officerRemarks} />

          {document.uploadStatus === 'rejected' && document.rejectionReason ? (
            <View style={styles.rejectionBox}>
              <Text style={styles.rejectionTitle}>Reason</Text>
              <Text style={styles.rejectionBody}>{document.rejectionReason}</Text>
            </View>
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
  safe: { flex: 1, backgroundColor: colors.background },
  topBar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing[20],
    paddingVertical: spacing[8],
  },
  backButton: {
    width: 44,
    height: 44,
    borderRadius: 22,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  backPlaceholder: { width: 44 },
  screenTitle: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
  },
  scroll: {
    paddingHorizontal: spacing[20],
    paddingBottom: spacing[32],
  },
  panel: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    padding: spacing[16],
  },
  docTitle: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
    marginBottom: spacing[8],
  },
  statusBadgeWrap: {
    alignSelf: 'flex-start',
    marginBottom: spacing[16],
  },
  detailField: {
    marginBottom: spacing[14],
  },
  detailLabel: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[2],
  },
  detailValue: {
    ...typography.body,
    color: colors.textPrimary,
    lineHeight: 20,
  },
  rejectionBox: {
    marginTop: spacing[2],
    marginBottom: spacing[16],
    padding: spacing[10],
    borderRadius: 8,
    backgroundColor: 'rgba(239, 68, 68, 0.06)',
    borderWidth: 1,
    borderColor: 'rgba(239, 68, 68, 0.2)',
  },
  rejectionTitle: {
    ...typography.statusLabel,
    color: colors.danger,
    marginBottom: spacing[4],
  },
  rejectionBody: {
    ...typography.body,
    color: colors.textPrimary,
    lineHeight: 20,
  },
  actionButtonWrap: {
    marginTop: spacing[2],
  },
  missingWrap: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing[20],
    gap: spacing[16],
  },
  missingText: {
    ...typography.body,
    color: colors.textSecondary,
    textAlign: 'center',
  },
});
