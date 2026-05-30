import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useMemo } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Button,
  Card,
  StatusChip,
  colors,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { DEFAULT_LOCAL_PROFILE } from '../mock/profile.mock';
import {
  documentUploadStatusToChip,
  getMockVisitorVerification,
} from '../mock/visitorVerificationDocuments.mock';
import { formatDate, formatTime } from '../utils';
import { getRelationshipLabel } from '../utils/registrationRequirements';

function DocumentStatusRow({ document: doc }) {
  const chipStatus = documentUploadStatusToChip(doc.uploadStatus);
  const whenLine = (() => {
    if (!doc.uploadedAt) return null;
    const datePart = formatDate(doc.uploadedAt);
    const timePart = formatTime(doc.uploadedAt);
    return [datePart, timePart].filter(Boolean).join(' · ');
  })();

  return (
    <Card style={styles.docCard}>
      <View style={styles.docHeader}>
        <View style={styles.docIconWrap}>
          <Ionicons
            name={
              doc.uploadStatus === 'verified'
                ? 'document-text'
                : doc.uploadStatus === 'rejected'
                  ? 'alert-circle-outline'
                  : 'document-outline'
            }
            size={22}
            color={
              doc.uploadStatus === 'verified'
                ? colors.success
                : doc.uploadStatus === 'rejected'
                  ? colors.danger
                  : colors.primaryTeal
            }
          />
        </View>
        <View style={styles.docBody}>
          <View style={styles.docTitleRow}>
            <Text style={styles.docTitle}>{doc.label}</Text>
            <StatusChip status={chipStatus} />
          </View>
          {whenLine ? (
            <Text style={styles.docMeta}>Uploaded {whenLine}</Text>
          ) : (
            <Text style={styles.docMetaMuted}>Not uploaded yet</Text>
          )}
          {doc.reviewNote ? (
            <View style={styles.reviewNoteWrap}>
              <Text style={styles.reviewNoteLabel}>Review note</Text>
              <Text style={styles.reviewNoteBody}>{doc.reviewNote}</Text>
            </View>
          ) : null}
        </View>
      </View>
    </Card>
  );
}

/**
 * Visitor verification documents — status overview and required uploads (v2.1).
 * Mock data only; no backend APIs.
 */
export default function VisitorVerificationDocumentsScreen({ navigation, route }) {
  const relationshipId =
    route.params?.relationshipId ?? DEFAULT_LOCAL_PROFILE.relationshipToPdl ?? 'spouse';

  const verification = useMemo(
    () => getMockVisitorVerification(relationshipId),
    [relationshipId],
  );

  const uploadedCount = verification.documents.filter(
    (doc) => doc.uploadStatus !== 'pending',
  ).length;
  const totalCount = verification.documents.length;
  const progressRatio = totalCount > 0 ? uploadedCount / totalCount : 0;

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
        <Text style={styles.screenTitle}>Verification Documents</Text>
        <View style={styles.backPlaceholder} />
      </View>

      <ScrollView
        contentContainerStyle={styles.scroll}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        <Card style={styles.statusCard}>
          <Text style={styles.eyebrow}>VERIFICATION STATUS</Text>
          <View style={styles.statusRow}>
            <StatusChip status={verification.verificationStatus} />
          </View>
          <Text style={styles.statusHint}>
            {verification.verificationStatus === 'verification_pending'
              ? 'Upload the required documents below to begin verification.'
              : verification.verificationStatus === 'verification_under_review'
                ? 'Your documents are being reviewed by facility staff.'
                : verification.verificationStatus === 'verification_verified'
                  ? 'Your visitor verification is complete. You may proceed with assigned visits.'
                  : 'One or more documents need to be corrected and re-uploaded.'}
          </Text>
          {verification.rejectionReason ? (
            <View style={styles.rejectionBanner}>
              <Ionicons name="warning-outline" size={18} color={colors.danger} />
              <Text style={styles.rejectionText}>{verification.rejectionReason}</Text>
            </View>
          ) : null}
        </Card>

        <Card style={styles.summaryCard}>
          <Text style={styles.summaryLabel}>Relationship to PDL</Text>
          <Text style={styles.summaryValue}>
            {getRelationshipLabel(relationshipId) || verification.relationshipLabel}
          </Text>
          <Text style={styles.progressMeta}>
            {uploadedCount} of {totalCount} document{totalCount === 1 ? '' : 's'} uploaded
          </Text>
          <View style={styles.progressTrack}>
            <View
              style={[styles.progressFill, { width: `${Math.round(progressRatio * 100)}%` }]}
            />
          </View>
        </Card>

        <Text style={styles.sectionHeading}>Required Documents</Text>
        <Text style={styles.sectionSub}>
          Based on your relationship, the following documents are required for visitor
          verification.
        </Text>

        {verification.documents.map((doc) => (
          <DocumentStatusRow key={doc.key} document={doc} />
        ))}

        <Button
          title="Upload or Update Documents"
          onPress={() =>
            navigation.navigate('UploadID', {
              relationshipId,
            })
          }
          accessibilityLabel="Upload or update verification documents"
        />
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
  statusCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.cardGap,
  },
  eyebrow: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textSecondary,
    letterSpacing: 0.5,
    marginBottom: spacing[8],
  },
  statusRow: {
    alignSelf: 'flex-start',
    marginBottom: spacing[12],
  },
  statusHint: {
    ...typography.body,
    color: colors.textSecondary,
    lineHeight: 22,
  },
  rejectionBanner: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing[8],
    marginTop: spacing[12],
    padding: spacing[12],
    borderRadius: 12,
    backgroundColor: 'rgba(239, 68, 68, 0.08)',
    borderWidth: 1,
    borderColor: 'rgba(239, 68, 68, 0.25)',
  },
  rejectionText: {
    ...typography.caption,
    color: colors.danger,
    flex: 1,
    lineHeight: 18,
  },
  summaryCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.sectionGap,
  },
  summaryLabel: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[4],
  },
  summaryValue: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing[12],
  },
  progressMeta: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[8],
  },
  progressTrack: {
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.border,
    overflow: 'hidden',
  },
  progressFill: {
    height: '100%',
    backgroundColor: colors.primaryTeal,
    borderRadius: 4,
  },
  sectionHeading: {
    ...typography.sectionTitle,
    color: colors.primaryNavy,
    marginBottom: spacing[4],
  },
  sectionSub: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[16],
    lineHeight: 18,
  },
  docCard: {
    borderRadius: layout.cardRadius,
    marginBottom: spacing[12],
  },
  docHeader: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing[12],
  },
  docIconWrap: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: colors.background,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  docBody: {
    flex: 1,
  },
  docTitleRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: spacing[8],
    marginBottom: spacing[4],
  },
  docTitle: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  docMeta: {
    ...typography.caption,
    color: colors.textSecondary,
  },
  docMetaMuted: {
    ...typography.caption,
    color: colors.textSecondary,
    fontStyle: 'italic',
  },
  reviewNoteWrap: {
    marginTop: spacing[8],
    padding: spacing[8],
    borderRadius: 8,
    backgroundColor: colors.background,
    borderWidth: 1,
    borderColor: colors.border,
  },
  reviewNoteLabel: {
    ...typography.statusLabel,
    color: colors.textSecondary,
    textTransform: 'uppercase',
    letterSpacing: 0.3,
    marginBottom: spacing[4],
  },
  reviewNoteBody: {
    ...typography.caption,
    color: colors.textPrimary,
    lineHeight: 18,
  },
});
