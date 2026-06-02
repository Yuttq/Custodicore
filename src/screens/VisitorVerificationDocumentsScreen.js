import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useMemo } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, colors, layout, spacing, typography } from '../designSystem';
import { EmptyState } from '../components';
import VerificationProgressCard from '../components/VerificationProgressCard';
import { DEFAULT_LOCAL_PROFILE } from '../mock/profile.mock';
import { getMockVisitorVerification } from '../mock/visitorVerificationDocuments.mock';
import { getDocumentStatusDisplay } from '../utils/verificationDocumentUi';

/**
 * @param {object} props
 * @param {import('../mock/visitorVerificationDocuments.mock').MockVerificationDocument} props.document
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
 * Visitor verification documents — main document management screen (v2.1 / BJMP).
 */
export default function VisitorVerificationDocumentsScreen({ navigation, route }) {
  const relationshipId =
    route.params?.relationshipId ?? DEFAULT_LOCAL_PROFILE.relationshipToPdl ?? 'spouse';

  const verification = useMemo(
    () => getMockVisitorVerification(relationshipId),
    [relationshipId],
  );

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
        {documents.length === 0 ? (
          <EmptyState
            title="No Documents Uploaded"
            message="Upload required documents to begin verification."
            iconName="document-text-outline"
            iconColor={colors.primaryTeal}
            style={styles.documentsEmpty}
          >
            <Button
              title="Upload Document"
              onPress={() =>
                navigation.navigate('UploadID', { relationshipId })
              }
              accessibilityLabel="Upload verification document"
            />
          </EmptyState>
        ) : (
          <>
            <VerificationProgressCard
              documents={documents}
              overallStatus={verification.verificationStatus}
            />

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
  safe: { flex: 1, backgroundColor: colors.background },
  topBar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: layout.screenPadding,
    paddingVertical: spacing.sm,
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
    ...typography.pageTitle,
    fontSize: 20,
    lineHeight: 24,
    color: colors.textPrimary,
  },
  scroll: {
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.xl,
  },
  sectionEyebrow: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
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
