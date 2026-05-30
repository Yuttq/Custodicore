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
import { Button, colors, layout, spacing, typography } from '../designSystem';
import { EmptyState } from '../components';
import { useVisits } from '../context/VisitsContext';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import {
  fetchQrTokenPayload,
  resolveScheduleIdForQr,
} from '../repositories/qrRepository';
import { formatDate, formatTime } from '../utils';

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
  const facility =
    (typeof schedule.facilityName === 'string' && schedule.facilityName) ||
    (typeof schedule.facility === 'string' && schedule.facility) ||
    (typeof data?.facilityName === 'string' && data.facilityName) ||
    '—';
  const referenceNumber =
    (typeof data?.referenceNumber === 'string' && data.referenceNumber) ||
    (typeof data?.scheduleId === 'string' && data.scheduleId) ||
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
    facility,
    referenceNumber,
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
  return `${pad(h)} : ${pad(m)} : ${pad(s)}`;
}

function InfoLine({ label, value }) {
  return (
    <View style={styles.infoLine}>
      <Text style={styles.infoLabel}>{label}</Text>
      <Text style={styles.infoValue}>{value}</Text>
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
 * Digital visit pass — gate QR with expiry countdown (v2.1).
 */
export default function QRCodeScreen({ route, navigation }) {
  const scheduleIdParam = route?.params?.scheduleId ?? route?.params?.visitId;
  const { visits } = useVisits();

  const preferredVisitId = useMemo(() => {
    const confirmed = visits.find((v) => v.status === 'confirmed');
    return confirmed?.id ?? visits[0]?.id;
  }, [visits]);

  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState(null);
  const [qrPayload, setQrPayload] = useState(null);
  const [secondsLeft, setSecondsLeft] = useState(0);
  const hasLoadedOnce = useRef(false);

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

  const countdownDisplay = formatCountdown(secondsLeft);
  const isExpired = secondsLeft <= 0 && qrPayload?.expiresAt;
  const tabBarInset = useTabBarScrollInset();
  const showNoActivePass = !loading && !qrPayload && isNoActiveQrPassError(error);

  const onViewMyVisits = useCallback(() => {
    navigation.navigate('Schedule');
  }, [navigation]);

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <Text style={styles.headerTitle}>QR PASS</Text>

      <ScrollView
        contentContainerStyle={[styles.scroll, { paddingBottom: tabBarInset }]}
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
            message="A QR pass is issued when you have a confirmed assigned visit. Check My Visits for your schedule, or pull to refresh after an officer assigns a visit."
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
              <Button title="Retry" onPress={() => load('initial')} accessibilityLabel="Retry loading QR pass" />
            </EmptyState>
          </View>
        ) : (
          <>
            <View style={styles.passCard}>
              <Text style={styles.passIntro}>
                Present this QR code at the facility gate and to the Front Desk
                Officer.
              </Text>

              <Text style={styles.expiresLabel}>EXPIRES IN</Text>
              <Text
                style={[styles.countdown, isExpired && styles.countdownExpired]}
                accessibilityLabel={`Expires in ${countdownDisplay}`}
              >
                {isExpired ? '00 : 00 : 00' : countdownDisplay}
              </Text>

              <View style={styles.qrFrame} accessibilityLabel="Visit entry QR code">
                <QRCode
                  value={qrPayload?.qrToken ?? ''}
                  size={248}
                  color={colors.primaryNavy}
                  backgroundColor={colors.white}
                />
              </View>

              <View style={styles.visitInfo}>
                <InfoLine label="Facility" value={qrPayload?.facility} />
                <InfoLine label="Date" value={qrPayload?.dateDisplay} />
                <InfoLine label="Time" value={qrPayload?.timeLabel} />
                <InfoLine label="PDL" value={qrPayload?.pdlName} />
                <InfoLine label="Reference Number" value={qrPayload?.referenceNumber} />
              </View>

              {!isExpired ? (
                <View style={styles.activeBadge}>
                  <Text style={styles.activeText}>ACTIVE</Text>
                </View>
              ) : (
                <View style={styles.expiredBadge}>
                  <Text style={styles.expiredText}>EXPIRED</Text>
                </View>
              )}

              <Pressable
                onPress={() => load('refresh')}
                disabled={refreshing}
                style={({ pressed }) => [
                  styles.refreshBtn,
                  pressed && !refreshing && styles.pressed,
                  refreshing && styles.refreshDisabled,
                ]}
                accessibilityRole="button"
                accessibilityLabel="Refresh QR"
              >
                {refreshing ? (
                  <ActivityIndicator color={colors.white} />
                ) : (
                  <>
                    <Ionicons name="refresh" size={18} color={colors.white} />
                    <Text style={styles.refreshText}>Refresh QR</Text>
                  </>
                )}
              </Pressable>
            </View>

            <View style={styles.noticeBox}>
              <Ionicons name="shield-checkmark-outline" size={20} color={colors.primaryTeal} />
              <Text style={styles.noticeText}>
                Bring the same government-issued ID used during registration.
              </Text>
            </View>
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: {
    flex: 1,
    backgroundColor: colors.background,
  },
  headerTitle: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
    textAlign: 'center',
    paddingTop: spacing[8],
    paddingBottom: spacing[12],
    letterSpacing: 1,
  },
  scroll: {
    paddingHorizontal: spacing[20],
  },
  centerBlock: {
    minHeight: 320,
    alignItems: 'center',
    justifyContent: 'center',
  },
  errorEmpty: {
    paddingVertical: spacing[16],
  },
  loadingText: {
    ...typography.body,
    color: colors.textSecondary,
  },
  errorText: {
    ...typography.body,
    color: colors.danger,
    textAlign: 'center',
    marginBottom: spacing[8],
  },
  passCard: {
    backgroundColor: colors.primaryNavy,
    borderRadius: layout.cardRadius,
    padding: spacing[20],
    marginBottom: layout.cardGap,
  },
  passIntro: {
    ...typography.caption,
    color: 'rgba(255,255,255,0.85)',
    textAlign: 'center',
    lineHeight: 18,
    marginBottom: spacing[16],
  },
  expiresLabel: {
    ...typography.caption,
    fontWeight: '600',
    color: 'rgba(255,255,255,0.7)',
    textAlign: 'center',
    letterSpacing: 1.2,
    marginBottom: spacing[8],
  },
  countdown: {
    fontSize: 32,
    fontWeight: '700',
    color: colors.white,
    textAlign: 'center',
    letterSpacing: 2,
    marginBottom: spacing[20],
  },
  countdownExpired: {
    color: colors.warning,
  },
  qrFrame: {
    alignSelf: 'center',
    backgroundColor: colors.white,
    borderRadius: 16,
    padding: spacing[16],
    marginBottom: spacing[20],
  },
  visitInfo: {
    marginBottom: spacing[16],
  },
  infoLine: {
    marginBottom: spacing[10],
  },
  infoLabel: {
    ...typography.caption,
    color: 'rgba(255,255,255,0.65)',
    marginBottom: spacing[4],
  },
  infoValue: {
    ...typography.body,
    fontWeight: '600',
    color: colors.white,
  },
  activeBadge: {
    alignSelf: 'center',
    backgroundColor: colors.success,
    paddingHorizontal: spacing[16],
    paddingVertical: spacing[8],
    borderRadius: layout.chipRadius,
    marginBottom: spacing[16],
  },
  activeText: {
    ...typography.statusLabel,
    color: colors.white,
    fontWeight: '700',
    letterSpacing: 0.8,
  },
  expiredBadge: {
    alignSelf: 'center',
    backgroundColor: colors.warning,
    paddingHorizontal: spacing[16],
    paddingVertical: spacing[8],
    borderRadius: layout.chipRadius,
    marginBottom: spacing[16],
  },
  expiredText: {
    ...typography.statusLabel,
    color: colors.textPrimary,
    fontWeight: '700',
  },
  refreshBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing[8],
    height: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.35)',
    backgroundColor: 'rgba(255,255,255,0.12)',
  },
  refreshText: {
    ...typography.body,
    fontWeight: '600',
    color: colors.white,
  },
  refreshDisabled: { opacity: 0.7 },
  pressed: { opacity: 0.9 },
  noticeBox: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing[12],
    backgroundColor: colors.card,
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    padding: spacing[16],
  },
  noticeText: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
    lineHeight: 22,
  },
});
