import Ionicons from '@expo/vector-icons/Ionicons';
import moment from 'moment';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import QRCode from 'react-native-qrcode-svg';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Button,
  Card,
  colors,
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { EmptyState } from '../components';
import { useVisits } from '../context/VisitsContext';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import {
  fetchQrTokenPayload,
  resolveScheduleIdForQr,
} from '../repositories/qrRepository';
import { formatDate, formatTime } from '../utils';

const AUTO_REFRESH_MS = 5 * 60 * 1000;

/**
 * @param {Record<string, unknown>} data
 */
function normalizeQrPayload(data) {
  const qrToken =
    (typeof data?.qrToken === 'string' && data.qrToken) ||
    (typeof data?.token === 'string' && data.token) ||
    (typeof data?.payload === 'string' && data.payload) ||
    '';
  const expiresAt =
    (typeof data?.expiresAt === 'string' && data.expiresAt) ||
    (typeof data?.expires_at === 'string' && data.expires_at) ||
    null;
  const schedule =
    data && typeof data.schedule === 'object' && data.schedule !== null
      ? data.schedule
      : {};
  const scheduledAt =
    (typeof schedule.scheduledAt === 'string' && schedule.scheduledAt) ||
    (typeof data?.scheduledAt === 'string' && data.scheduledAt) ||
    null;
  const pdlName =
    (typeof schedule.pdlName === 'string' && schedule.pdlName) ||
    (typeof data?.pdlName === 'string' && data.pdlName) ||
    '—';
  const dateDisplay =
    (typeof schedule.dateDisplay === 'string' && schedule.dateDisplay) ||
    (scheduledAt ? formatDate(scheduledAt) : '—');
  const timeLabel =
    (typeof schedule.timeLabel === 'string' && schedule.timeLabel) ||
    (scheduledAt ? formatTime(scheduledAt) : '—');

  return {
    qrToken,
    expiresAt,
    pdlName,
    dateDisplay,
    timeLabel,
  };
}

function formatCountdown(totalSeconds) {
  const safe = Math.max(0, Math.floor(totalSeconds));
  const h = Math.floor(safe / 3600);
  const m = Math.floor((safe % 3600) / 60);
  const s = safe % 60;
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(h)}:${pad(m)}:${pad(s)}`;
}

/**
 * @param {boolean} isExpired
 * @param {string | undefined} visitStatus
 */
function getPassStatus(isExpired, visitStatus) {
  if (isExpired) {
    return {
      label: 'Expired',
      hint: 'This pass is no longer valid for entry.',
      tone: 'expired',
    };
  }
  if (visitStatus === 'checked_in') {
    return {
      label: 'Active',
      hint: 'You may present this pass at the facility.',
      tone: 'active',
    };
  }
  return {
    label: 'Ready for Check-In',
    hint: 'Valid for your assigned visit window.',
    tone: 'ready',
  };
}

/**
 * @param {object} props
 * @param {ReturnType<typeof getPassStatus>} props.status
 */
function PassStatusBanner({ status }) {
  const toneStyles = {
    active: styles.statusActive,
    ready: styles.statusReady,
    expired: styles.statusExpired,
  };
  const textStyles = {
    active: styles.statusTextActive,
    ready: styles.statusTextReady,
    expired: styles.statusTextExpired,
  };

  return (
    <View style={[styles.statusBanner, toneStyles[status.tone]]}>
      <Text style={[styles.statusLabel, textStyles[status.tone]]}>{status.label}</Text>
      <Text style={styles.statusHint}>{status.hint}</Text>
    </View>
  );
}

/**
 * @param {string | null | undefined} message
 */
function isNoActiveQrPassError(message) {
  if (!message) return false;
  const normalized = message.toLowerCase();
  return (
    normalized.includes('no upcoming visit') ||
    normalized.includes('no active qr') ||
    normalized.includes('no confirmed visit')
  );
}

/**
 * Digital visit pass — answers “Can I enter the facility?” (v2.1).
 */
export default function QRCodeScreen({ route, navigation }) {
  const scheduleIdParam = route?.params?.scheduleId ?? route?.params?.visitId;
  const { visits, getVisitById } = useVisits();

  const preferredVisitId = useMemo(() => {
    const confirmed = visits.find((v) => v.status === 'confirmed');
    return confirmed?.id ?? visits[0]?.id;
  }, [visits]);

  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState(null);
  const [qrPayload, setQrPayload] = useState(null);
  const [resolvedScheduleId, setResolvedScheduleId] = useState(null);
  const [secondsLeft, setSecondsLeft] = useState(0);
  const hasLoadedOnce = useRef(false);

  const activeVisit = useMemo(
    () => (resolvedScheduleId ? getVisitById(resolvedScheduleId) : null),
    [resolvedScheduleId, getVisitById],
  );

  const applyExpiry = useCallback((expiresAt) => {
    if (!expiresAt) {
      setSecondsLeft(0);
      return;
    }
    const exp = moment(expiresAt);
    if (!exp.isValid()) {
      setSecondsLeft(0);
      return;
    }
    setSecondsLeft(Math.max(0, exp.diff(moment(), 'seconds')));
  }, []);

  const load = useCallback(
    async (mode = 'initial') => {
      const silent = mode === 'refresh' && hasLoadedOnce.current;
      if (silent) setRefreshing(true);
      else {
        setLoading(true);
        setError(null);
      }
      try {
        const sid = await resolveScheduleIdForQr(
          scheduleIdParam ?? preferredVisitId,
        );
        setResolvedScheduleId(sid);
        const data = await fetchQrTokenPayload(sid);
        const normalized = normalizeQrPayload(
          data && typeof data === 'object' ? data : {},
        );
        if (!normalized.qrToken) {
          throw new Error('Invalid response: missing QR token.');
        }
        setQrPayload(normalized);
        applyExpiry(normalized.expiresAt);
        setError(null);
        hasLoadedOnce.current = true;
      } catch (e) {
        const message = e instanceof Error ? e.message : 'Failed to load QR pass.';
        setError(message);
        setQrPayload(null);
      } finally {
        if (silent) setRefreshing(false);
        else setLoading(false);
      }
    },
    [scheduleIdParam, preferredVisitId, applyExpiry],
  );

  useEffect(() => {
    hasLoadedOnce.current = false;
    load('initial');
  }, [scheduleIdParam, preferredVisitId, load]);

  useEffect(() => {
    if (!qrPayload?.expiresAt) return undefined;
    const tick = () => {
      const exp = moment(qrPayload.expiresAt);
      if (!exp.isValid()) return;
      setSecondsLeft(Math.max(0, exp.diff(moment(), 'seconds')));
    };
    tick();
    const id = setInterval(tick, 1000);
    return () => clearInterval(id);
  }, [qrPayload?.expiresAt]);

  useEffect(() => {
    if (!qrPayload?.qrToken || loading) return undefined;
    const id = setInterval(() => {
      load('refresh');
    }, AUTO_REFRESH_MS);
    return () => clearInterval(id);
  }, [qrPayload?.qrToken, loading, load]);

  const countdownDisplay = formatCountdown(secondsLeft);
  const isExpired = secondsLeft <= 0 && Boolean(qrPayload?.expiresAt);
  const tabBarInset = useTabBarScrollInset();
  const showNoActivePass = !loading && !qrPayload && isNoActiveQrPassError(error);
  const passStatus = getPassStatus(isExpired, activeVisit?.status);

  const onViewMyVisits = useCallback(() => {
    navigation.navigate('Schedule');
  }, [navigation]);

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right']}>
      <View style={styles.headerRow}>
        <Text style={styles.headerTitle} accessibilityRole="header">
          QR Pass
        </Text>
        {qrPayload && !loading ? (
          <Pressable
            onPress={() => load('refresh')}
            disabled={refreshing}
            style={({ pressed }) => [
              styles.refreshIconBtn,
              pressed && !refreshing && styles.pressed,
              refreshing && styles.refreshDisabled,
            ]}
            accessibilityRole="button"
            accessibilityLabel="Refresh QR pass"
          >
            {refreshing ? (
              <ActivityIndicator size="small" color={colors.primaryTeal} />
            ) : (
              <Ionicons name="refresh" size={22} color={colors.primaryTeal} />
            )}
          </Pressable>
        ) : (
          <View style={styles.refreshPlaceholder} />
        )}
      </View>

      <ScrollView
        contentContainerStyle={[commonStyles.scrollContent, { paddingBottom: tabBarInset }]}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        {loading ? (
          <View style={styles.centerBlock}>
            <ActivityIndicator size="large" color={colors.primaryTeal} />
            <Text style={styles.loadingText}>Loading visit pass…</Text>
          </View>
        ) : showNoActivePass ? (
          <EmptyState
            title="No Active QR Pass"
            message="A QR pass is issued when you have a confirmed assigned visit. Check My Visits for your schedule, or tap refresh after an officer assigns a visit."
            iconName="qr-code-outline"
            iconColor={colors.primaryTeal}
            style={styles.centerBlock}
          >
            <Button
              title="View My Visits"
              onPress={onViewMyVisits}
              accessibilityLabel="View my assigned visits"
            />
          </EmptyState>
        ) : error ? (
          <View style={styles.centerBlock}>
            <EmptyState
              title="Couldn't Load QR Pass"
              message={error}
              emphasis="error"
              iconName="cloud-offline-outline"
              iconColor={colors.danger}
              accessibilityRole="alert"
              style={styles.errorEmpty}
            >
              <Button
                title="Retry"
                onPress={() => load('initial')}
                accessibilityLabel="Retry loading QR pass"
              />
            </EmptyState>
          </View>
        ) : (
          <>
            <Card style={styles.passCard}>
              <PassStatusBanner status={passStatus} />

              <View style={styles.expiryRow}>
                <Ionicons
                  name="time-outline"
                  size={16}
                  color={isExpired ? colors.warning : colors.textSecondary}
                />
                <Text style={styles.expiryLabel}>Expires in</Text>
                <Text
                  style={[styles.countdown, isExpired && styles.countdownExpired]}
                  accessibilityLabel={`Expires in ${countdownDisplay}`}
                >
                  {isExpired ? '00:00:00' : countdownDisplay}
                </Text>
              </View>

              <View
                style={[styles.qrFrame, isExpired && styles.qrFrameExpired]}
                accessibilityLabel="Visit entry QR code"
              >
                <QRCode
                  value={qrPayload?.qrToken ?? ''}
                  size={232}
                  color={colors.primaryNavy}
                  backgroundColor={colors.white}
                />
              </View>

              <View style={styles.visitSummary}>
                <Text style={styles.summaryDate}>{qrPayload?.dateDisplay}</Text>
                <Text style={styles.summaryTime}>{qrPayload?.timeLabel}</Text>
                <Text style={styles.summaryPdl} numberOfLines={2}>
                  {qrPayload?.pdlName}
                </Text>
              </View>
            </Card>

            <View style={styles.noticeBox}>
              <Ionicons name="id-card-outline" size={18} color={colors.primaryTeal} />
              <Text style={styles.noticeText}>
                Bring your valid ID. Present both QR code and ID during check-in.
              </Text>
            </View>
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  headerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.sm,
    paddingBottom: spacing.sm,
  },
  headerTitle: {
    ...typography.pageTitle,
    color: colors.textPrimary,
    flex: 1,
  },
  refreshIconBtn: {
    width: layout.iconButtonSize - spacing.sm,
    height: layout.iconButtonSize - spacing.sm,
    borderRadius: (layout.iconButtonSize - spacing.sm) / 2,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  refreshPlaceholder: {
    width: layout.iconButtonSize - spacing.sm,
  },
  centerBlock: {
    minHeight: 300,
    alignItems: 'center',
    justifyContent: 'center',
  },
  errorEmpty: {
    paddingVertical: spacing.md,
  },
  loadingText: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.sm,
  },
  passCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.cardGap,
    alignItems: 'center',
  },
  statusBanner: {
    alignSelf: 'stretch',
    borderRadius: layout.buttonRadius,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.sm,
    marginBottom: spacing.sm,
    alignItems: 'center',
  },
  statusActive: {
    backgroundColor: 'rgba(22, 163, 74, 0.12)',
  },
  statusReady: {
    backgroundColor: 'rgba(13, 165, 138, 0.12)',
  },
  statusExpired: {
    backgroundColor: 'rgba(245, 158, 11, 0.14)',
  },
  statusLabel: {
    ...typography.body,
    fontWeight: '700',
    letterSpacing: 0.3,
  },
  statusTextActive: {
    color: colors.success,
  },
  statusTextReady: {
    color: colors.primaryTeal,
  },
  statusTextExpired: {
    color: colors.warning,
  },
  statusHint: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.xs,
    textAlign: 'center',
  },
  expiryRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
    marginBottom: spacing.sm,
  },
  expiryLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  countdown: {
    ...typography.metadata,
    fontWeight: '700',
    color: colors.primaryNavy,
    fontVariant: ['tabular-nums'],
  },
  countdownExpired: {
    color: colors.warning,
  },
  qrFrame: {
    backgroundColor: colors.white,
    borderRadius: 16,
    padding: spacing.md,
    borderWidth: 1,
    borderColor: colors.border,
    marginBottom: spacing.md,
  },
  qrFrameExpired: {
    opacity: 0.55,
  },
  visitSummary: {
    alignSelf: 'stretch',
    alignItems: 'center',
    paddingTop: spacing.xs,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
  },
  summaryDate: {
    ...typography.cardTitle,
    color: colors.primaryNavy,
  },
  summaryTime: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    marginTop: spacing.xs,
  },
  summaryPdl: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.xs,
    textAlign: 'center',
  },
  noticeBox: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    backgroundColor: colors.card,
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    padding: spacing.sm,
  },
  noticeText: {
    ...typography.metadata,
    color: colors.textPrimary,
    flex: 1,
  },
  refreshDisabled: { opacity: 0.6 },
  pressed: { opacity: 0.88 },
});
