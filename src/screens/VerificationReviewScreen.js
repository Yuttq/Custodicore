import Ionicons from '@expo/vector-icons/Ionicons';
import React from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
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
import { useAuth } from '../hooks/useAuth';

/**
 * Post-registration verification pending screen (v2.1).
 */
export default function VerificationReviewScreen({ navigation }) {
  const { registrationSummary, completeVerificationReview } = useAuth();
  const documents = registrationSummary?.documents ?? [];

  const onBackToDashboard = () => {
    completeVerificationReview();
    navigation.reset({
      index: 0,
      routes: [{ name: 'MainTabs' }],
    });
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <ScrollView
        contentContainerStyle={styles.scroll}
        showsVerticalScrollIndicator={false}
      >
        <View style={styles.illustrationWrap}>
          <View style={styles.illustrationCircle}>
            <Ionicons name="clipboard-outline" size={56} color={colors.primaryTeal} />
            <View style={styles.illustrationBadge}>
              <Ionicons name="checkmark" size={16} color={colors.white} />
            </View>
          </View>
        </View>

        <Text style={styles.sectionHeading}>Documents Submitted</Text>
        <Card style={styles.documentsCard}>
          {documents.length === 0 ? (
            <Text style={styles.docEmpty}>No documents on file.</Text>
          ) : (
            documents.map((item) => (
              <View key={item.label} style={styles.docRow}>
                <Ionicons name="checkmark-circle" size={20} color={colors.success} />
                <View style={styles.docText}>
                  <Text style={styles.docLabel}>{item.label}</Text>
                  {item.detail ? (
                    <Text style={styles.docDetail}>{item.detail}</Text>
                  ) : null}
                </View>
              </View>
            ))
          )}
        </Card>

        <Card style={styles.reviewCard}>
          <Text style={styles.reviewLead}>Your application is under review.</Text>

          <Text style={styles.estimateLabel}>Estimated Review Time</Text>
          <Text style={styles.estimateValue}>1 – 3 Working Days</Text>

          <View style={styles.chipWrap}>
            <StatusChip status="pending_verification" />
          </View>
        </Card>

        <Text style={styles.notifyMessage}>
          You will receive a notification once verification is completed.
        </Text>

        <View style={styles.buttonWrap}>
          <Button
            title="Back To Dashboard"
            onPress={onBackToDashboard}
            accessibilityLabel="Back to dashboard"
          />
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  scroll: {
    flexGrow: 1,
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.lg,
    paddingBottom: spacing.xl,
  },
  illustrationWrap: {
    alignItems: 'center',
    marginBottom: layout.sectionGap,
  },
  illustrationCircle: {
    width: 120,
    height: 120,
    borderRadius: 60,
    backgroundColor: 'rgba(13, 165, 138, 0.12)',
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: 'rgba(13, 165, 138, 0.25)',
  },
  illustrationBadge: {
    position: 'absolute',
    right: 8,
    bottom: 8,
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: colors.success,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: colors.white,
  },
  sectionHeading: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  documentsCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.sectionGap,
  },
  docRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    marginBottom: spacing.sm,
    gap: spacing.sm,
  },
  docText: { flex: 1 },
  docLabel: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  docDetail: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.xs,
  },
  docEmpty: {
    ...typography.body,
    color: colors.textSecondary,
  },
  reviewCard: {
    borderRadius: layout.cardRadius,
    marginBottom: spacing.md,
  },
  reviewLead: {
    ...typography.body,
    color: colors.textPrimary,
    marginBottom: spacing.md,
    lineHeight: 22,
  },
  estimateLabel: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.xs,
  },
  estimateValue: {
    ...typography.cardTitle,
    color: colors.primaryNavy,
    marginBottom: spacing.md,
  },
  chipWrap: {
    alignSelf: 'flex-start',
  },
  notifyMessage: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
    marginBottom: spacing.lg,
    paddingHorizontal: spacing.sm,
  },
  buttonWrap: {
    marginTop: 'auto',
  },
});
