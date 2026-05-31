import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useMemo } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, colors, layout, spacing, typography } from '../designSystem';
import { EmptyState } from '../components';
import { DEFAULT_LOCAL_PROFILE } from '../mock/profile.mock';
import { getMockVisitorVerification } from '../mock/visitorVerificationDocuments.mock';

/**
 * @param {import('../mock/visitorVerificationDocuments.mock').VisitorVerificationStatus} status
 */
function getOverallVerificationDisplay(status) {
  switch (status) {
    case 'verification_verified':
      return { label: 'Verified', icon: 'checkmark-circle', accent: colors.success };
    case 'verification_under_review':
      return { label: 'Under Review', icon: 'time', accent: colors.primaryNavy };
    case 'verification_rejected':
    case 'verification_pending':
    default:
      return { label: 'Action Required', icon: 'alert-circle', accent: colors.warning };
  }
}

/**
 * @param {import('../mock/visitorVerificationDocuments.mock').DocumentUploadStatus} uploadStatus
 */
function getDocumentStatusLabel(uploadStatus) {
  switch (uploadStatus) {
    case 'verified':
      return { label: 'Verified', color: colors.success };
    case 'rejected':
      return { label: 'Rejected', color: colors.danger };
    case 'under_review':
    case 'uploaded':
      return { label: 'Under Review', color: colors.warning };
    case 'pending':
    default:
      return { label: 'Not Submitted', color: colors.textSecondary };
  }
}

/**
 * @param {object} props
 * @param {{ label: string; icon: string; accent: string }} props.display
 */
function CompactVerificationStatus({ display }) {
  return (
    <View style={styles.statusStrip}>
      <Ionicons name={display.icon} size={16} color={display.accent} />
      <Text style={[styles.statusStripLabel, { color: display.accent }]}>{display.label}</Text>
    </View>
  );
}

/**
 * @param {object} props
 * @param {import('../mock/visitorVerificationDocuments.mock').MockVerificationDocument} props.document
 * @param {boolean} props.isLast
 * @param {() => void} props.onPress
 */
function DocumentCompactRow({ document: doc, isLast, onPress }) {
  const status = getDocumentStatusLabel(doc.uploadStatus);

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [
        styles.docRow,
        !isLast && styles.docRowBorder,
        pressed && styles.docRowPressed,
      ]}
      accessibilityRole="button"
      accessibilityLabel={`${doc.label}, ${status.label}. Tap for details.`}
    >
      <Text style={styles.docRowLabel} numberOfLines={2}>
        {doc.label}
      </Text>
      <Text style={[styles.docStatusLabel, { color: status.color }]}>{status.label}</Text>
    </Pressable>
  );
}

/**
 * Visitor verification documents — main document management screen (v2.1 / BJMP).
 */
export default function VisitorVerificationDocumentsScreen({ navigation, route }) {
  const relationshipId =
    route.params?.relationshipId ?? DEFAULT_LOCAL_PROFILE.relationshipToPdl ?? 'spouse';

  const verification = useMemo(
    () => getMockVisitorVerification(relationshipId),
    [relationshipId],
  );

  const overall = getOverallVerificationDisplay(verification.verificationStatus);
  const documents = verification.documents;

  const openDocument = useCallback(
    (doc) => {
      navigation.navigate('VerificationDocumentDetail', {
        relationshipId,
        documentKey: doc.key,
      });
    },
    [navigation, relationshipId],
  );

  const onUpload = useCallback(() => {
    navigation.navigate('UploadID', { relationshipId });
  }, [navigation, relationshipId]);

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
        <Text style={styles.sectionEyebrow}>Verification Status</Text>
        <CompactVerificationStatus display={overall} />

        {documents.length === 0 ? (
          <EmptyState
            title="No Documents Uploaded"
            message="Upload required documents to begin verification."
            iconName="document-text-outline"
            iconColor={colors.primaryTeal}
            style={styles.documentsEmpty}
          >
            <Button
              title="Upload Documents"
              onPress={onUpload}
              accessibilityLabel="Upload verification documents"
            />
          </EmptyState>
        ) : (
          <>
            <Text style={styles.sectionEyebrow}>Required Documents</Text>
            <View style={styles.docList}>
              {documents.map((doc, index) => (
                <DocumentCompactRow
                  key={doc.key}
                  document={doc}
                  isLast={index === documents.length - 1}
                  onPress={() => openDocument(doc)}
                />
              ))}
            </View>

            <Button
              title="Upload Documents"
              onPress={onUpload}
              accessibilityLabel="Upload or update verification documents"
            />
          </>
        )}
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
    paddingVertical: spacing[6],
  },
  backButton: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  backPlaceholder: { width: 40 },
  screenTitle: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
  },
  scroll: {
    paddingHorizontal: spacing[20],
    paddingBottom: spacing[24],
  },
  sectionEyebrow: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textSecondary,
    letterSpacing: 0.4,
    marginBottom: spacing[4],
    marginTop: spacing[4],
  },
  statusStrip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[6],
    paddingHorizontal: spacing[10],
    paddingVertical: spacing[8],
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    marginBottom: spacing[8],
  },
  statusStripLabel: {
    ...typography.body,
    fontWeight: '700',
    fontSize: 15,
  },
  documentsEmpty: {
    paddingVertical: spacing[20],
  },
  docList: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    overflow: 'hidden',
    marginBottom: spacing[12],
  },
  docRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing[12],
    paddingVertical: spacing[10],
    gap: spacing[12],
    backgroundColor: colors.card,
    minHeight: 42,
  },
  docRowBorder: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  docRowPressed: {
    backgroundColor: colors.background,
  },
  docRowLabel: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
    fontSize: 14,
  },
  docStatusLabel: {
    ...typography.caption,
    fontWeight: '600',
    flexShrink: 0,
  },
});
