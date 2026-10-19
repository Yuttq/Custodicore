import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect } from '@react-navigation/native';
import React, { useCallback, useState } from 'react';
import { Alert, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
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
import {
  RELATIONSHIP_DOCUMENTS_SUBMITTED_NOTICE,
  getInfoNotice,
} from '../utils/registrationRequirements';

/**
 * Registration summaries saved by older app versions listed these even though
 * registration never uploaded them, and had no `kind` on Guardian Information.
 * Current summaries mark every uploaded document `submitted: true`, so a
 * Marriage Certificate sent with the registration is still listed.
 */
const LEGACY_NOT_SUBMITTED_LABELS = new Set([
  'Marriage Certificate',
  'Birth Certificate',
  'CENOMAR',
]);
const LEGACY_INFO_LABELS = new Set(['Guardian Information']);

/**
 * Typed details (Guardian Information), not uploaded documents. Sent with the
 * registration when `submitted`; older summaries kept them on the device only.
 * @param {{ label: string; kind?: string }} item
 */
function isLocalInfo(item) {
  return item.kind === 'info' || LEGACY_INFO_LABELS.has(item.label);
}

/**
 * Restricted home for a signed-in visitor whose email is verified but whose
 * information/documents staff have not approved (verificationStatus
 * `pending` or `rejected`). AppNavigator shows only this stack until
 * /me reports `verified`; the API refuses visit features meanwhile too.
 */
export default function VerificationReviewScreen({ navigation }) {
  const { user, registrationSummary, refreshUser, logout } = useAuth();
  const [checking, setChecking] = useState(false);
  // Web only: react-native-web's Alert.alert is a no-op, so Check Status results are shown inline.
  const [webNotice, setWebNotice] = useState(null);
  const isRejected = user?.verificationStatus === 'rejected';
  const summaryItems = (registrationSummary?.documents ?? []).filter(
    (item) => item.submitted === true || !LEGACY_NOT_SUBMITTED_LABELS.has(item.label),
  );
  const documents = summaryItems.filter((item) => !isLocalInfo(item));
  const localInfo = summaryItems.filter(isLocalInfo);

  // Pick up a decision made while the app was in the background.
  useFocusEffect(
    useCallback(() => {
      refreshUser().catch(() => {});
    }, [refreshUser]),
  );

  const onCheckStatus = useCallback(async () => {
    if (checking) return;
    setChecking(true);
    setWebNotice(null);
    const notify = (title, message, isError) => {
      if (Platform.OS === 'web') {
        setWebNotice({ title, message, isError });
      } else {
        Alert.alert(title, message);
      }
    };
    try {
      const fresh = await refreshUser();
      // `verified` switches the navigator to the full app on its own.
      if (fresh?.verificationStatus === 'pending') {
        notify(
          'Still under review',
          'Our staff is still reviewing your documents and information. You will be notified once your account has been approved.',
          false,
        );
      }
    } catch (e) {
      notify(
        'Could not check status',
        typeof e?.message === 'string' && e.message.trim()
          ? e.message
          : 'Please check your connection and try again.',
        true,
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
            <Text style={styles.sectionHeading}>Documents Submitted With Registration</Text>
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

        {localInfo.length > 0 ? (
          <>
            <Text style={styles.sectionHeading}>Information Provided</Text>
            <Card style={styles.documentsCard}>
              {localInfo.map((item) => (
                <View key={item.label} style={styles.docRow}>
                  <Ionicons
                    name="information-circle-outline"
                    size={20}
                    color={colors.textSecondary}
                  />
                  <View style={styles.docText}>
                    <Text style={styles.docLabel}>{item.label}</Text>
                    {item.detail ? (
                      <Text style={styles.docDetail}>{item.detail}</Text>
                    ) : null}
                  </View>
                </View>
              ))}
              <Text style={styles.docDetail}>{getInfoNotice(localInfo)}</Text>
            </Card>
          </>
        ) : null}

        <Card style={styles.documentsCard}>
          <Text style={styles.docDetail}>{RELATIONSHIP_DOCUMENTS_SUBMITTED_NOTICE}</Text>
        </Card>

        <View style={styles.buttonWrap}>
          {webNotice ? (
            <Text
              style={[styles.webNotice, webNotice.isError ? styles.webNoticeError : null]}
              accessibilityRole="alert"
            >
              <Text style={styles.webNoticeTitle}>{webNotice.title}. </Text>
              {webNotice.message}
            </Text>
          ) : null}
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
  webNotice: {
    ...typography.metadata,
    color: colors.textPrimary,
    marginBottom: spacing.md,
  },
  webNoticeError: {
    color: colors.danger,
  },
  webNoticeTitle: {
    fontWeight: '600',
  },
  secondaryWrap: {
    marginTop: spacing.sm,
  },
});
