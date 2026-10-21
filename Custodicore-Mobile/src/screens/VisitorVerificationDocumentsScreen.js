import React, { useCallback } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Button,
  StackScreenHeader,
  colors,
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { EmptyState, LoadingSpinner } from '../components';
import VerificationProgressCard from '../components/VerificationProgressCard';
import useVisitorVerification from '../hooks/useVisitorVerification';
import { getDocumentStatusDisplay } from '../utils/verificationDocumentUi';

/**
 * @param {object} props
 * @param {import('../repositories/verificationRepository').VerificationDocument} props.document
 * @param {boolean} props.isLast
 * @param {() => void} props.onPress
 */
function DocumentCompactRow({ document: doc, isLast, onPress }) {
  const status = getDocumentStatusDisplay(doc.uploadStatus);

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
 * Visitor verification documents — real statuses from GET /api/documents (v2.1 / BJMP).
 */
export default function VisitorVerificationDocumentsScreen({ navigation }) {
  const { verification, loading, error, reload } = useVisitorVerification();
  const documents = verification?.documents ?? [];

  const openDocument = useCallback(
    (doc) => {
      navigation.navigate('VerificationDocumentDetail', { documentKey: doc.key });
    },
    [navigation],
  );

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Verification Documents" navigation={navigation} />

      <ScrollView
        contentContainerStyle={commonStyles.scrollContent}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        {loading && !verification ? (
          <LoadingSpinner message="Loading verification status…" compact />
        ) : error && !verification ? (
          <EmptyState
            title="Couldn't load documents"
            message={error}
            iconName="cloud-offline-outline"
            iconColor={colors.danger}
            emphasis="error"
            style={styles.documentsEmpty}
          >
            <Button title="Retry" onPress={reload} accessibilityLabel="Retry loading documents" />
          </EmptyState>
        ) : documents.length === 0 ? (
          <EmptyState
            title="No Documents Required"
            message="There are no verification documents to show right now."
            iconName="document-text-outline"
            iconColor={colors.primaryTeal}
            style={styles.documentsEmpty}
          />
        ) : (
          <>
            {verification.rejectionReason ? (
              <View style={styles.reasonBox}>
                <Text style={styles.reasonLabel}>Reason</Text>
                <Text style={styles.reasonText}>{verification.rejectionReason}</Text>
              </View>
            ) : null}

            <VerificationProgressCard
              documents={documents}
              overallStatus={verification.verificationStatus}
            />

            {documents.every((doc) => doc.uploadStatus === 'pending') ? (
              <Text style={styles.noneSubmitted}>
                No documents submitted yet. Upload them below to begin verification.
              </Text>
            ) : null}

            {verification.awaitingRelationshipLink ? (
              <Text style={styles.noneSubmitted}>
                Facility staff review the relationship documents you sent with your registration
                after they link your account to the PDL you are visiting. You only need to upload
                a document again if staff reject it or ask for another one.
              </Text>
            ) : null}

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
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  // Same look as the reason on VerificationReviewScreen.
  reasonBox: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
    padding: spacing.md,
    marginBottom: spacing.md,
  },
  reasonLabel: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.xs,
  },
  reasonText: {
    ...typography.body,
    color: colors.textPrimary,
  },
  sectionEyebrow: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  noneSubmitted: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.md,
  },
  documentsEmpty: {
    paddingVertical: spacing.md,
  },
  docList: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    overflow: 'hidden',
  },
  docRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    gap: spacing.sm,
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
  },
  docStatusLabel: {
    ...typography.metadata,
    fontWeight: '600',
    flexShrink: 0,
  },
});
