import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect } from '@react-navigation/native';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  AppState,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
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
import useVisitorVerification from '../hooks/useVisitorVerification';
import { getInfoNotice } from '../utils/registrationRequirements';
import {
  getCheckStatusNotice,
  getVerificationReviewState,
} from '../utils/verificationReviewStatus';

/**
 * Registration summaries saved by older app versions had no `kind` on
 * Guardian Information.
 */
const LEGACY_INFO_LABELS = new Set(['Guardian Information']);

/**
 * Typed details (Guardian Information), not uploaded documents. Sent with the
 * registration when `submitted`; older summaries kept them on the device only.
 * Shown here because no other screen shows them. Uploaded documents are not
 * listed: Verification Documents shows each one with its review status.
 * @param {{ label: string; kind?: string }} item
 */
function isLocalInfo(item) {
  return item.kind === 'info' || LEGACY_INFO_LABELS.has(item.label);
}

/** Icon and colors per status tone. */
const TONES = {
  success: { icon: 'checkmark-circle-outline', color: colors.success, bg: 'rgba(22, 163, 74, 0.12)' },
  danger: { icon: 'arrow-undo-outline', color: colors.danger, bg: 'rgba(239, 68, 68, 0.10)' },
  warning: { icon: 'alert-circle-outline', color: colors.warningText, bg: 'rgba(245, 158, 11, 0.14)' },
  info: { icon: 'time-outline', color: colors.primaryTeal, bg: 'rgba(13, 165, 138, 0.12)' },
};

/**
 * Compact navigation row (same pattern as Profile's account menu).
 * @param {object} props
 * @param {string} props.icon
 * @param {string} props.label
 * @param {boolean} [props.destructive]
 * @param {boolean} [props.isLast]
 * @param {string} props.accessibilityLabel
 * @param {() => void} props.onPress
 */
function NavRow({ icon, label, destructive, isLast, accessibilityLabel, onPress }) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [
        styles.navRow,
        !isLast && styles.navRowBorder,
        pressed && styles.navRowPressed,
      ]}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
    >
      <View style={[styles.navIconWrap, destructive && styles.navIconWrapDanger]}>
        <Ionicons
          name={icon}
          size={18}
          color={destructive ? colors.danger : colors.primaryTeal}
        />
      </View>
      <Text
        style={[styles.navLabel, destructive && styles.navLabelDanger]}
        numberOfLines={1}
      >
        {label}
      </Text>
      {!destructive ? (
        <Ionicons name="chevron-forward" size={18} color={colors.textSecondary} />
      ) : null}
    </Pressable>
  );
}

/**
 * Restricted home for a signed-in visitor whose email is verified but whose
 * information/documents staff have not approved (verificationStatus
 * `pending` or `rejected`). AppNavigator shows only this stack until
 * /me reports `verified`; the API refuses visit features meanwhile too.
 *
 * The account status (GET /api/me) and each document's own status
 * (GET /api/documents) are shown separately: see verificationReviewStatus.
 */
export default function VerificationReviewScreen({ navigation }) {
  const { user, registrationSummary, refreshUser, logout } = useAuth();
  // Reloads GET /api/documents whenever this screen regains focus.
  const {
    verification,
    loading: documentsLoading,
    error: documentsError,
    reload: reloadDocuments,
  } = useVisitorVerification();
  const [checking, setChecking] = useState(false);
  // Web only: react-native-web's Alert.alert is a no-op, so Check Status results are shown inline.
  const [webNotice, setWebNotice] = useState(null);
  const localInfo = (registrationSummary?.documents ?? []).filter(isLocalInfo);

  const reviewState = useMemo(
    () =>
      getVerificationReviewState({
        accountStatus: user?.verificationStatus,
        accountReason: user?.rejectionReason,
        documents: verification?.documents ?? null,
      }),
    [user?.verificationStatus, user?.rejectionReason, verification],
  );
  const tone = TONES[reviewState.tone] ?? TONES.info;

  // Pick up a decision made while the app was in the background.
  useFocusEffect(
    useCallback(() => {
      refreshUser().catch(() => {});
    }, [refreshUser]),
  );

  // Focus does not fire when the app returns from the background, so staff
  // decisions made meanwhile are picked up here.
  const refreshRef = useRef(() => {});
  refreshRef.current = () => {
    refreshUser().catch(() => {});
    reloadDocuments();
  };
  useEffect(() => {
    let previous = AppState.currentState;
    const sub = AppState.addEventListener('change', (next) => {
      if (/inactive|background/.test(String(previous)) && next === 'active') refreshRef.current();
      previous = next;
    });
    return () => sub.remove();
  }, []);

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
      // Both sources, freshly loaded, so the result never reports "under
      // review" while a document is actually rejected. `verified` also
      // switches the navigator to the full app on its own.
      const [fresh, freshDocuments] = await Promise.all([refreshUser(), reloadDocuments()]);
      const { title, message } = getCheckStatusNotice(
        getVerificationReviewState({
          accountStatus: fresh?.verificationStatus,
          accountReason: fresh?.rejectionReason,
          documents: freshDocuments?.documents ?? null,
        }),
      );
      notify(title, message, false);
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
  }, [checking, refreshUser, reloadDocuments]);

  const openDocuments = useCallback(
    () => navigation.navigate('VisitorVerificationDocuments'),
    [navigation],
  );

  const onLogout = useCallback(() => {
    logout().catch(() => {});
  }, [logout]);

  const attention = reviewState.documentsNeedingAttention;
  const isReturned = reviewState.kind === 'returned';

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <ScrollView contentContainerStyle={styles.scroll} showsVerticalScrollIndicator={false}>
        <Text style={styles.pageTitle} accessibilityRole="header">
          Account Verification
        </Text>

        {/* Status first: what state the account is in and what happens next. */}
        <Card style={styles.statusCard}>
          <View style={styles.statusHeader}>
            <View style={[styles.statusIcon, { backgroundColor: tone.bg }]}>
              <Ionicons name={tone.icon} size={22} color={tone.color} />
            </View>
            <View style={styles.statusText}>
              <Text style={styles.statusTitle} accessibilityRole="header">
                {reviewState.title}
              </Text>
              <StatusChip status={reviewState.chip.status} label={reviewState.chip.label} />
            </View>
          </View>

          <Text style={styles.statusBody}>{reviewState.message}</Text>

          {/* Account-level reason: only for a returned account. */}
          {isReturned ? (
            reviewState.accountReason ? (
              <View style={styles.reasonBox}>
                <Text style={styles.reasonLabel}>Reason from staff</Text>
                <Text style={styles.reasonText}>{reviewState.accountReason}</Text>
              </View>
            ) : (
              <Text style={styles.mutedLine}>
                No reason was given. Contact facility staff for details.
              </Text>
            )
          ) : null}

          {/* Each document staff rejected, with its own reason. */}
          {attention.length > 0 ? (
            <View style={styles.attentionWrap}>
              <Text style={styles.attentionHeading}>Needs correction</Text>
              {attention.map((doc) => (
                <View key={doc.key} style={styles.attentionRow}>
                  <Ionicons name="alert-circle" size={16} color={colors.danger} style={styles.attentionIcon} />
                  <View style={styles.attentionText}>
                    <Text style={styles.attentionLabel}>
                      {doc.label}
                      {doc.detail ? <Text style={styles.attentionDetail}> · {doc.detail}</Text> : null}
                    </Text>
                    <Text style={styles.attentionReason}>
                      {doc.reason ?? 'No reason was given. Open Verification Documents for details.'}
                    </Text>
                  </View>
                </View>
              ))}
            </View>
          ) : null}

          {/* Document details are still unknown: say so instead of guessing. */}
          {!verification && documentsLoading ? (
            <View style={styles.inlineStatus}>
              <ActivityIndicator size="small" color={colors.primaryTeal} />
              <Text style={styles.mutedLineInline}>Checking your documents…</Text>
            </View>
          ) : null}
          {!verification && !documentsLoading && documentsError ? (
            <Text style={styles.mutedLine}>
              Document details could not be loaded. Tap Check Status to try again.
            </Text>
          ) : null}

          {webNotice ? (
            <Text
              style={[styles.webNotice, webNotice.isError ? styles.webNoticeError : null]}
              accessibilityRole="alert"
            >
              <Text style={styles.webNoticeTitle}>{webNotice.title}. </Text>
              {webNotice.message}
            </Text>
          ) : null}

          <View style={styles.actions}>
            {reviewState.showDocumentsAction ? (
              <>
                <Button
                  title="Open Verification Documents"
                  onPress={openDocuments}
                  accessibilityLabel="Open Verification Documents to correct your registration"
                />
                <View style={styles.actionGap} />
                <Button
                  variant="secondary"
                  title="Check Status"
                  onPress={onCheckStatus}
                  loading={checking}
                  disabled={checking}
                  accessibilityLabel="Check review status"
                />
              </>
            ) : (
              <Button
                title="Check Status"
                onPress={onCheckStatus}
                loading={checking}
                disabled={checking}
                accessibilityLabel="Check review status"
              />
            )}
          </View>
        </Card>

        {localInfo.length > 0 ? (
          <>
            <Text style={styles.sectionLabel}>Information Provided</Text>
            <Card style={styles.infoCard}>
              {localInfo.map((item) => (
                <View key={item.label} style={styles.infoRow}>
                  <Ionicons
                    name="information-circle-outline"
                    size={18}
                    color={colors.textSecondary}
                  />
                  <View style={styles.infoText}>
                    <Text style={styles.infoLabel}>{item.label}</Text>
                    {item.detail ? (
                      <Text style={styles.infoDetail}>{item.detail}</Text>
                    ) : null}
                  </View>
                </View>
              ))}
              <Text style={styles.infoDetail}>{getInfoNotice(localInfo)}</Text>
            </Card>
          </>
        ) : null}

        <Text style={styles.sectionLabel}>Account</Text>
        <Card style={styles.navCard}>
          <NavRow
            icon="document-text-outline"
            label="Verification Documents"
            accessibilityLabel="View verification documents"
            onPress={openDocuments}
          />
          <NavRow
            icon="person-outline"
            label="Personal Information"
            accessibilityLabel="View personal information"
            onPress={() => navigation.navigate('PersonalInformation')}
          />
          <NavRow
            icon="log-out-outline"
            label="Log Out"
            destructive
            isLast
            accessibilityLabel="Log out"
            onPress={onLogout}
          />
        </Card>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  scroll: {
    flexGrow: 1,
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.md,
    paddingBottom: spacing.lg,
  },
  pageTitle: {
    ...typography.screenHeader,
    color: colors.textPrimary,
    marginBottom: spacing.md,
  },

  statusCard: {
    marginBottom: spacing.md,
  },
  statusHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm + spacing.xs,
  },
  statusIcon: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
  },
  statusText: {
    flex: 1,
    flexDirection: 'row',
    flexWrap: 'wrap',
    alignItems: 'center',
    columnGap: spacing.sm,
    rowGap: spacing.xs,
  },
  statusTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
  },
  statusBody: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.sm,
  },
  reasonBox: {
    borderRadius: layout.borderRadiusSm,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
    paddingHorizontal: spacing.sm + spacing.xs,
    paddingVertical: spacing.sm,
    marginTop: spacing.sm,
  },
  reasonLabel: {
    ...typography.statusLabel,
    textTransform: 'uppercase',
    color: colors.textSecondary,
    marginBottom: 2,
  },
  reasonText: {
    ...typography.metadata,
    color: colors.textPrimary,
  },
  mutedLine: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.sm,
  },
  inlineStatus: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.sm,
  },
  mutedLineInline: {
    ...typography.metadata,
    color: colors.textSecondary,
  },

  attentionWrap: {
    marginTop: spacing.sm + spacing.xs,
    paddingTop: spacing.sm,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
  },
  attentionHeading: {
    ...typography.statusLabel,
    textTransform: 'uppercase',
    color: colors.textSecondary,
    marginBottom: spacing.xs,
  },
  attentionRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    paddingVertical: spacing.xs,
  },
  attentionIcon: {
    marginTop: 2,
  },
  attentionText: { flex: 1 },
  attentionLabel: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  attentionDetail: {
    fontWeight: '400',
    color: colors.textSecondary,
  },
  attentionReason: {
    ...typography.metadata,
    color: colors.textSecondary,
  },

  webNotice: {
    ...typography.metadata,
    color: colors.textPrimary,
    marginTop: spacing.sm,
  },
  webNoticeError: {
    color: colors.danger,
  },
  webNoticeTitle: {
    fontWeight: '600',
  },
  actions: {
    marginTop: spacing.md,
  },
  actionGap: {
    height: spacing.sm,
  },

  sectionLabel: {
    ...typography.statusLabel,
    textTransform: 'uppercase',
    letterSpacing: 0.6,
    color: colors.textSecondary,
    marginBottom: spacing.xs + 2,
    marginLeft: spacing.xs,
  },

  infoCard: {
    paddingVertical: spacing.sm + spacing.xs,
    marginBottom: spacing.md,
  },
  infoRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    marginBottom: spacing.xs,
  },
  infoText: { flex: 1 },
  infoLabel: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  infoDetail: {
    ...typography.metadata,
    color: colors.textSecondary,
  },

  navCard: {
    paddingVertical: 0,
    paddingHorizontal: 0,
    overflow: 'hidden',
  },
  navRow: {
    flexDirection: 'row',
    alignItems: 'center',
    minHeight: 52,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    gap: spacing.sm + spacing.xs,
    backgroundColor: colors.card,
  },
  navRowBorder: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  navRowPressed: {
    backgroundColor: colors.background,
  },
  navIconWrap: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: colors.background,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  navIconWrapDanger: {
    backgroundColor: 'rgba(239, 68, 68, 0.08)',
    borderColor: 'rgba(239, 68, 68, 0.25)',
  },
  navLabel: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  navLabelDanger: {
    color: colors.danger,
  },
});
