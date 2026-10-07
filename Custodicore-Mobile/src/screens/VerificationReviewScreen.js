import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect } from '@react-navigation/native';
import React, { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
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
 * Restricted home for a signed-in visitor whose email is verified but whose
 * information/documents staff have not approved (verificationStatus
 * `pending` or `rejected`). AppNavigator shows only this stack until
 * /me reports `verified`; the API refuses visit features meanwhile too.
 */
export default function VerificationReviewScreen({ navigation }) {
  const { user, registrationSummary, refreshUser, logout } = useAuth();
  const [checking, setChecking] = useState(false);
  const isRejected = user?.verificationStatus === 'rejected';
  const documents = registrationSummary?.documents ?? [];

  // Pick up a decision made while the app was in the background.
  useFocusEffect(
    useCallback(() => {
      refreshUser().catch(() => {});
    }, [refreshUser]),
  );

  const onCheckStatus = useCallback(async () => {
    if (checking) return;
    setChecking(true);
    try {
      const fresh = await refreshUser();
      // `verified` switches the navigator to the full app on its own.
      if (fresh?.verificationStatus === 'pending') {
        Alert.alert(
          'Still under review',
          'Our staff is still reviewing your documents and information. You will be notified once your account has been approved.',
        );
      }
    } catch (e) {
      Alert.alert(
        'Could not check status',
        typeof e?.message === 'string' && e.message.trim()
          ? e.message
          : 'Please check your connection and try again.',
      );
    } finally {
      setChecking(false);
    }
  }, [checking, refreshUser]);

  const onLogout = useCallback(() => {
    logout().catch(() => {});
  }, [logout]);

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <ScrollView
        contentContainerStyle={styles.scroll}
        showsVerticalScrollIndicator={false}
      >
        <View style={styles.illustrationWrap}>
          <View style={styles.illustrationCircle}>
            <Ionicons
              name={isRejected ? 'document-text-outline' : 'clipboard-outline'}
              size={56}
              color={isRejected ? colors.danger : colors.primaryTeal}
            />
            <View
              style={[
                styles.illustrationBadge,
                isRejected ? styles.illustrationBadgeRejected : null,
              ]}
            >
              <Ionicons
                name={isRejected ? 'close' : 'time-outline'}
                size={16}
                color={colors.white}
              />
            </View>
          </View>
        </View>

        <Card style={styles.reviewCard}>
          {isRejected ? (
            <>
              <Text style={styles.reviewLead}>
                Your information and documents were not approved.
              </Text>
              <Text style={styles.reviewBody}>
                Our staff reviewed the information and documents you submitted and could not
                approve your account. Visitor services are not available for this account.
              </Text>
              {user?.rejectionReason ? (
                <View style={styles.reasonBox}>
                  <Text style={styles.reasonLabel}>Reason</Text>
                  <Text style={styles.reasonText}>{user.rejectionReason}</Text>
                </View>
              ) : null}
              <Text style={styles.reviewBody}>
                Please check your verification documents or contact facility staff for help.
              </Text>
              <View style={styles.chipWrap}>
                <StatusChip status="verification_rejected" />
              </View>
            </>
          ) : (
            <>
              <Text style={styles.reviewLead}>
                Your documents and information are under review.
              </Text>
              <Text style={styles.reviewBody}>
                Your email has been verified successfully. Our staff is currently reviewing the
                information and documents you submitted.
              </Text>
              <Text style={styles.reviewBody}>
                You will receive a notification once your account has been approved.
              </Text>
              <View style={styles.chipWrap}>
                <StatusChip status="pending_verification" />
              </View>
            </>
          )}
        </Card>

        {documents.length > 0 ? (
          <>
            <Text style={styles.sectionHeading}>Documents Submitted</Text>
            <Card style={styles.documentsCard}>
              {documents.map((item) => (
                <View key={item.label} style={styles.docRow}>
                  <Ionicons name="checkmark-circle" size={20} color={colors.success} />
                  <View style={styles.docText}>
                    <Text style={styles.docLabel}>{item.label}</Text>
                    {item.detail ? (
                      <Text style={styles.docDetail}>{item.detail}</Text>
                    ) : null}
                  </View>
                </View>
              ))}
            </Card>
          </>
        ) : null}

        <View style={styles.buttonWrap}>
          <Button
            title="Check Status"
            onPress={onCheckStatus}
            loading={checking}
            disabled={checking}
            accessibilityLabel="Check review status"
          />
          <View style={styles.secondaryWrap}>
            <Button
              variant="secondary"
              title="Verification Documents"
              onPress={() => navigation.navigate('VisitorVerificationDocuments')}
              accessibilityLabel="View verification documents"
            />
          </View>
          <View style={styles.secondaryWrap}>
            <Button
              variant="secondary"
              title="Personal Information"
              onPress={() => navigation.navigate('PersonalInformation')}
              accessibilityLabel="View personal information"
            />
          </View>
          <View style={styles.secondaryWrap}>
            <Button
              variant="secondary"
              title="Log Out"
              onPress={onLogout}
              accessibilityLabel="Log out"
            />
          </View>
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
    backgroundColor: colors.warning,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: colors.white,
  },
  illustrationBadgeRejected: {
    backgroundColor: colors.danger,
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
  reviewCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.sectionGap,
  },
  reviewLead: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
    lineHeight: 24,
  },
  reviewBody: {
    ...typography.body,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
    lineHeight: 22,
  },
  reasonBox: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
    padding: spacing.md,
    marginBottom: spacing.sm,
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
  chipWrap: {
    alignSelf: 'flex-start',
    marginTop: spacing.xs,
  },
  buttonWrap: {
    marginTop: 'auto',
  },
  secondaryWrap: {
    marginTop: spacing.sm,
  },
});
