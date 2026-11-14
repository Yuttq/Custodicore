import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect } from '@react-navigation/native';
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
  HeaderBand,
  colors,
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { EmptyState } from '../components';
import { useVisits } from '../context/VisitsContext';
import { useAuth } from '../hooks/useAuth';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import {
  fetchQrTokenPayload,
  resolveScheduleIdForQr,
} from '../repositories/qrRepository';
import { clearQrPass, loadQrPass, saveQrPass } from '../services/qrPassStorage';
import { formatDate, formatTime } from '../utils';
import {
  formatLastUpdated,
  isDefinitiveNoPass,
  isServerUnreachable,
} from '../utils/qrPassCache';
import {
  isPassExpired,
  isQrPassDay,
  passValidUntilTime,
  qrPassSelection,
  visitSessionsText,
  visitTimeText,
} from '../utils/activeVisits';

const AUTO_REFRESH_MS = 5 * 60 * 1000;

/** Shown (as the "No Active QR Pass" state) when no visit qualifies for a pass. */
const NO_ACTIVE_QR_MESSAGE = 'No active QR pass.';

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
  // Whole-day visit: the same pass is used again for the afternoon re-entry.
  const isWholeDay = schedule.isWholeDay === true;
  // Inside the facility: the same pass is shown again at check-out.
  const checkedIn = data?.checkedIn === true;

  return {
    qrToken,
    expiresAt,
    checkedIn,
    scheduledAt,
    pdlName,
    dateDisplay,
    timeLabel: visitTimeText({ timeLabel, isWholeDay }),
    sessionsText: isWholeDay ? visitSessionsText(schedule.sessions) : null,
  };
}

/**
 * @param {boolean} isExpired
 * @param {boolean} checkedIn
 */
function getPassStatus(isExpired, checkedIn) {
  if (checkedIn) {
    return {
      label: 'Inside Facility',
      hint: 'Inside facility. Show this pass when you leave.',
      tone: 'active',
    };
  }
  if (isExpired) {
    return {
      label: 'Expired',
      hint: 'This pass is no longer valid for entry.',
      tone: 'expired',
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
    normalized.includes('no confirmed visit') ||
    // 409 responses from GET /api/schedules/{id}/qr (visit not confirmed / closed / pass used)
    normalized.includes('confirm your visit attendance') ||
    normalized.includes('qr pass is not available') ||
    normalized.includes('only available once your visit is confirmed') ||
    normalized.includes('already checked in') ||
    normalized.includes('already been used') ||
    // 409 for an `assigned` visit request still awaiting staff review
    normalized.includes('awaiting review')
  );
}

/**
 * Digital visit pass — answers “Can I enter the facility?” (v2.1).
 */
export default function QRCodeScreen({ route, navigation }) {
  const scheduleIdParam = route?.params?.scheduleId ?? route?.params?.visitId;
  const {
    visits,
    loading: visitsLoading,
    error: visitsError,
    refreshVisits,
  } = useVisits();

  // Re-checked every minute so the pass appears once the Manila date rolls
  // over to the visit day while this screen is open.
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 60 * 1000);
    return () => clearInterval(id);
  }, []);

  // Reload visits on focus so a newly approved visit is picked up; a changed
  // autoSelection then reloads the pass below. Not skipping the first
  // focus: this tab mounts lazily, long after the session's initial load.
  useFocusEffect(
    useCallback(() => {
      setNow(new Date());
      refreshVisits().catch(() => {
        // error already stored in VisitsContext
      });
    }, [refreshVisits]),
  );

  const selection = useMemo(() => qrPassSelection(visits, now), [visits, now]);

  // Automatic selection (no explicit visit passed in): the nearest confirmed
  // visit today or later. 'later' = that visit is on a later date, so show
  // its details without fetching a pass; 'none' = visits loaded, nothing
  // eligible; 'pending' = list still loading; 'fallback' = list failed to
  // load, so keep the previous behaviour of asking the backend for the
  // upcoming visit.
  const autoSelection = useMemo(() => {
    if (selection) return selection.passDay ? `visit:${selection.visit.id}` : 'later';
    if (visitsError) return 'fallback';
    if (visitsLoading) return 'pending';
    return 'none';
  }, [selection, visitsError, visitsLoading]);

  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState(null);
  const [qrPayload, setQrPayload] = useState(null);
  // A fetched pass dated later than today ('fallback' path): its details only.
  const [laterPass, setLaterPass] = useState(null);
  const hasLoadedOnce = useRef(false);

  // Offline copy of the pass (SecureStore). Display only: the Front Desk
  // verifies every scan online. `offline` = the pass on screen is the saved
  // copy (or the server could not be reached on refresh); `savedAt` = when
  // the pass on screen was last confirmed by the server.
  const { user } = useAuth();
  const accountId = user?.id ?? null;
  const cacheRef = useRef(null);
  const [cacheReady, setCacheReady] = useState(false);
  // A visit the visitor is inside for, per the saved pass: its pass is shown
  // even after its day (overnight) until the server says they checked out.
  const [insideVisitId, setInsideVisitId] = useState(null);
  const [offline, setOffline] = useState(false);
  const [savedAt, setSavedAt] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setCacheReady(false);
    (async () => {
      const entry = await loadQrPass(accountId);
      if (cancelled) return;
      cacheRef.current = entry;
      setInsideVisitId(entry?.checkedIn ? entry.visitId : null);
      setCacheReady(true);
    })();
    return () => {
      cancelled = true;
    };
  }, [accountId]);

  const forgetCache = useCallback(async () => {
    cacheRef.current = null;
    setInsideVisitId(null);
    await clearQrPass();
  }, []);

  const load = useCallback(
    async (mode = 'initial') => {
      if (!cacheReady) return;
      let requestedId = scheduleIdParam;
      if (requestedId == null && insideVisitId) {
        // Inside the facility (possibly since yesterday): that visit's pass,
        // until the server answers that the visitor has checked out.
        requestedId = insideVisitId;
      }
      if (requestedId == null) {
        // Wait for the visits list; the effect reruns once it settles.
        if (autoSelection === 'pending') return;
        if (autoSelection === 'none' || autoSelection === 'later') {
          // The list loaded and today has no confirmed visit: a saved pass
          // for today is no longer valid (e.g. the visit was cancelled).
          if (cacheRef.current) await forgetCache();
          setQrPayload(null);
          setLaterPass(null);
          setOffline(false);
          setSavedAt(null);
          setError(autoSelection === 'none' ? NO_ACTIVE_QR_MESSAGE : null);
          setLoading(false);
          return;
        }
        if (autoSelection.startsWith('visit:')) {
          requestedId = autoSelection.slice('visit:'.length);
        }
      }

      const silent = mode === 'refresh' && hasLoadedOnce.current;
      if (silent) setRefreshing(true);
      else {
        setLoading(true);
        setError(null);
        setLaterPass(null);
      }
      let sid = requestedId != null ? String(requestedId) : null;
      try {
        sid = await resolveScheduleIdForQr(requestedId);
        const data = await fetchQrTokenPayload(sid);
        const normalized = normalizeQrPayload(
          data && typeof data === 'object' ? data : {},
        );
        if (!normalized.qrToken) {
          throw new Error('Invalid response: missing QR token.');
        }
        if (!normalized.checkedIn && normalized.scheduledAt && !isQrPassDay(normalized)) {
          setQrPayload(null);
          setLaterPass(normalized);
          setOffline(false);
          setSavedAt(null);
          setError(null);
          return;
        }
        setLaterPass(null);
        setQrPayload(normalized);
        setOffline(false);
        setError(null);
        hasLoadedOnce.current = true;

        const entry = await saveQrPass({ accountId, visitId: sid, pass: normalized });
        cacheRef.current = entry;
        setSavedAt(entry?.savedAt ?? new Date().toISOString());
        setInsideVisitId(normalized.checkedIn ? String(sid) : null);
      } catch (e) {
        const message = e instanceof Error ? e.message : 'Failed to load QR pass.';
        const cached = cacheRef.current;

        if (isServerUnreachable(e)) {
          if (silent) {
            // A failed background refresh keeps the pass on screen.
            setOffline(true);
            return;
          }
          // Offline: show the saved pass of this visit (or of today, when
          // the visit could not even be looked up).
          if (cached && (sid == null || cached.visitId === String(sid))) {
            setLaterPass(null);
            setQrPayload(cached.pass);
            setOffline(true);
            setSavedAt(cached.savedAt);
            setError(null);
            hasLoadedOnce.current = true;
            return;
          }
        }

        if (isDefinitiveNoPass(e)) {
          // The server answered: no pass for this visit (checked out for
          // good, cancelled, ended…). Delete its saved copy, and let go of
          // a visit the tab was opened for, so the next eligible one is
          // picked automatically.
          if (cached && (sid == null || cached.visitId === String(sid))) {
            await forgetCache();
          } else if (insideVisitId) {
            setInsideVisitId(null);
          }
          if (scheduleIdParam != null) {
            navigation.setParams({ scheduleId: undefined, visitId: undefined });
          }
        }

        // A failed background refresh keeps the pass on screen; only a
        // definite "no pass" answer from the server clears it.
        if (silent && !isNoActiveQrPassError(message)) return;
        setError(message);
        setQrPayload(null);
        setOffline(false);
        setSavedAt(null);
      } finally {
        if (silent) setRefreshing(false);
        else setLoading(false);
      }
    },
    [scheduleIdParam, autoSelection, cacheReady, insideVisitId, accountId, forgetCache, navigation],
  );

  // The tab is opened for one visit from Visit Details ("Show QR pass").
  // Leaving the tab lets go of it, so pressing the tab later picks the
  // nearest eligible visit again instead of staying on that one.
  useFocusEffect(
    useCallback(() => {
      return () => {
        if (scheduleIdParam != null) {
          navigation.setParams({ scheduleId: undefined, visitId: undefined });
        }
      };
    }, [navigation, scheduleIdParam]),
  );

  useEffect(() => {
    hasLoadedOnce.current = false;
    load('initial');
  }, [scheduleIdParam, autoSelection, load]);

  // Picks up check-in / check-out at the gate; the token itself stays the
  // same for the whole visit day.
  useEffect(() => {
    if (!qrPayload?.qrToken || loading) return undefined;
    const id = setInterval(() => {
      load('refresh');
    }, AUTO_REFRESH_MS);
    return () => clearInterval(id);
  }, [qrPayload?.qrToken, loading, load]);

  const checkedIn = qrPayload?.checkedIn === true;
  const validUntil = passValidUntilTime(qrPayload?.expiresAt);
  const isExpired = !checkedIn && isPassExpired(qrPayload?.expiresAt, now);
  const tabBarInset = useTabBarScrollInset();
  const showNoActivePass = !loading && !qrPayload && isNoActiveQrPassError(error);
  const passStatus = getPassStatus(isExpired, checkedIn);
  const lastUpdated = qrPayload ? formatLastUpdated(savedAt, now) : null;

  // Confirmed visit on a later date: its details, no pass yet.
  // Not while the visitor is inside for another visit: that pass comes first.
  const laterVisit =
    scheduleIdParam == null && !insideVisitId && selection && !selection.passDay
      ? selection.visit
      : null;
  const later = laterVisit
    ? {
        dateDisplay: laterVisit.dateDisplay || formatDate(laterVisit.scheduledAt),
        timeLabel: visitTimeText(laterVisit),
        pdlName: laterVisit.pdlName || '—',
      }
    : laterPass;

  const onViewMyVisits = useCallback(() => {
    navigation.navigate('Schedule');
  }, [navigation]);

  return (
    // Top edge is handled by HeaderBand, which draws navy under the status bar.
    <SafeAreaView style={commonStyles.safeScreen} edges={['left', 'right']}>
      <HeaderBand
        title="QR Pass"
        right={
          qrPayload && !loading ? (
            <Pressable
              onPress={() => load('refresh')}
              disabled={refreshing}
              // 40dp circle + 4dp slop on each side = 48dp touch target.
              hitSlop={spacing.xs}
              style={({ pressed }) => [
                styles.refreshIconBtn,
                pressed && !refreshing && styles.pressed,
                refreshing && styles.refreshDisabled,
              ]}
              accessibilityRole="button"
              accessibilityLabel="Refresh QR pass"
            >
              {refreshing ? (
                <ActivityIndicator size="small" color={colors.primaryNavy} />
              ) : (
                <Ionicons name="refresh" size={22} color={colors.primaryNavy} />
              )}
            </Pressable>
          ) : (
            <View style={styles.refreshPlaceholder} />
          )
        }
      />

      <ScrollView
        contentContainerStyle={[
          commonStyles.scrollContent,
          styles.scrollBelowBand,
          { paddingBottom: tabBarInset },
        ]}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        {loading ? (
          <View style={styles.centerBlock}>
            <ActivityIndicator size="large" color={colors.primaryTeal} />
            <Text style={styles.loadingText}>Loading visit pass…</Text>
          </View>
        ) : later ? (
          <EmptyState
            title="Visit Confirmed"
            message={`${later.dateDisplay} · ${later.timeLabel}\n${later.pdlName}\n\nYour QR pass will appear here on the day of your visit.`}
            iconName="calendar-outline"
            iconColor={colors.primaryTeal}
            style={styles.centerBlock}
          >
            <Button
              title="View My Visits"
              onPress={onViewMyVisits}
              accessibilityLabel="View my assigned visits"
            />
          </EmptyState>
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
            {offline ? (
              <View style={styles.offlineBox} accessibilityRole="alert">
                <Ionicons name="cloud-offline-outline" size={18} color={colors.warning} />
                <Text style={styles.offlineText}>
                  Offline — showing your saved pass
                  {lastUpdated ? ` (last updated ${lastUpdated})` : ''}. The Front Desk
                  verifies it online when you arrive.
                </Text>
              </View>
            ) : null}

            <Card style={styles.passCard}>
              <PassStatusBanner status={passStatus} />

              {checkedIn || !validUntil ? null : (
                <View style={styles.expiryRow}>
                  <Ionicons
                    name="time-outline"
                    size={16}
                    color={isExpired ? colors.warning : colors.textSecondary}
                  />
                  <Text style={styles.expiryLabel}>Valid until</Text>
                  <Text
                    style={[styles.countdown, isExpired && styles.countdownExpired]}
                    accessibilityLabel={`Valid until ${validUntil}`}
                  >
                    {validUntil}
                  </Text>
                </View>
              )}

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
                {qrPayload?.sessionsText ? (
                  <Text style={styles.summaryTime}>
                    {qrPayload.sessionsText}. Show this same pass again when you return after
                    the midday break.
                  </Text>
                ) : null}
                <Text style={styles.summaryPdl} numberOfLines={2}>
                  {qrPayload?.pdlName}
                </Text>
                {lastUpdated ? (
                  <Text style={styles.lastUpdated}>
                    {offline ? 'Saved' : 'Updated'} {lastUpdated}
                  </Text>
                ) : null}
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
  scrollBelowBand: {
    paddingTop: spacing.md,
  },
  // Sits in the navy HeaderBand: white circle, navy icon.
  refreshIconBtn: {
    width: layout.iconButtonSize - spacing.sm,
    height: layout.iconButtonSize - spacing.sm,
    borderRadius: (layout.iconButtonSize - spacing.sm) / 2,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.white,
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
  // Status text sits on 12–14% tints: the bright colors were 1.9–2.9:1.
  statusTextActive: {
    color: colors.successText,
  },
  statusTextReady: {
    color: colors.primaryTealDark,
  },
  statusTextExpired: {
    color: colors.warningText,
  },
  statusHint: {
    ...typography.metadata,
    // textSecondary is ~4.3:1 on the tints, just under AA.
    color: colors.textPrimary,
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
  lastUpdated: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.xs,
  },
  offlineBox: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    backgroundColor: 'rgba(245, 158, 11, 0.14)',
    borderRadius: layout.cardRadius,
    padding: spacing.sm,
    marginBottom: layout.cardGap,
  },
  offlineText: {
    ...typography.metadata,
    color: colors.textPrimary,
    flex: 1,
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
