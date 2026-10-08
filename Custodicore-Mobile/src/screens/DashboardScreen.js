import Ionicons from '@expo/vector-icons/Ionicons';
import { Image as ExpoImage } from 'expo-image';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
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
import { useVisits } from '../context/VisitsContext';
import useAnnouncements from '../hooks/useAnnouncements';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import useVisitTimeline from '../hooks/useVisitTimeline';
import useVisitorVerification from '../hooks/useVisitorVerification';
import { loadLocalProfile } from '../services/localProfileStorage';

/** Only the avatar photo is device-local — the backend has no photo field. */
const LOCAL_PHOTO_DEFAULTS = { photoUri: null };

const GRAY_UPCOMING = '#9CA3AF';

const HOME_STEP_LABELS = {
  visitor_eligible: 'Documents Verified',
  schedule_assigned: 'Schedule Assigned',
  attendance_confirmed: 'Attendance Confirmed',
  qr_generated: 'QR Pass Ready',
  checked_in: 'Check-In',
  visit_completed: 'Visit Completed',
};

function getTimeGreeting() {
  const hour = new Date().getHours();
  if (hour < 12) return 'Good Morning,';
  if (hour < 17) return 'Good Afternoon,';
  return 'Good Evening,';
}

/**
 * @param {string} fullName
 */
function getInitials(fullName) {
  const parts = fullName.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/**
 * Maps the real verification state (GET /api/documents, falling back to the
 * /api/me status) onto the existing strip styles.
 * @param {string | null | undefined} verificationStatus — verification_* UI key
 * @param {string | null | undefined} backendStatus — pending | verified | rejected
 */
function resolveVerificationDisplay(verificationStatus, backendStatus) {
  if (verificationStatus === 'verification_verified' || backendStatus === 'verified') {
    return {
      label: 'Verified Visitor',
      icon: 'shield-checkmark',
      accent: colors.success,
      bg: 'rgba(22, 163, 74, 0.1)',
    };
  }
  if (verificationStatus === 'verification_rejected' || backendStatus === 'rejected') {
    return {
      label: 'Verification Rejected',
      icon: 'close-circle-outline',
      accent: colors.danger,
      bg: 'rgba(239, 68, 68, 0.08)',
    };
  }
  if (
    verificationStatus === 'verification_under_review' ||
    (!verificationStatus && backendStatus === 'pending')
  ) {
    return {
      label: 'Documents Under Review',
      icon: 'time-outline',
      accent: colors.primaryNavy,
      bg: 'rgba(15, 61, 122, 0.08)',
    };
  }
  return {
    label: 'Verification Required',
    icon: 'alert-circle-outline',
    accent: colors.warning,
    bg: 'rgba(245, 158, 11, 0.12)',
  };
}

/**
 * @param {object} props
 * @param {string | null | undefined} props.photoUri
 * @param {string} props.initials
 */
function DashboardAvatar({ photoUri, initials }) {
  return (
    <View style={styles.avatar} accessibilityLabel="Profile photo">
      {photoUri ? (
        <ExpoImage
          source={{ uri: photoUri }}
          style={styles.avatarImage}
          contentFit="cover"
        />
      ) : (
        <Text style={styles.avatarText}>{initials}</Text>
      )}
    </View>
  );
}

/**
 * @param {object} props
 * @param {{ label: string; icon: string; accent: string; bg: string }} props.display
 * @param {() => void} props.onViewDocuments
 */
function CompactVerificationStatus({ display, onViewDocuments }) {
  return (
    <View style={[styles.verificationStrip, { backgroundColor: display.bg }]}>
      <View style={styles.verificationMain}>
        <Ionicons name={display.icon} size={18} color={display.accent} />
        <Text style={styles.verificationLabel}>{display.label}</Text>
      </View>
      <Pressable
        onPress={onViewDocuments}
        style={({ pressed }) => [styles.verificationAction, pressed && styles.pressed]}
        accessibilityRole="button"
        accessibilityLabel="View verification documents"
      >
        <Text style={styles.verificationActionText}>View Documents</Text>
        <Ionicons name="chevron-forward" size={14} color={colors.primaryTeal} />
      </Pressable>
    </View>
  );
}

/**
 * @param {object} props
 * @param {import('../utils/visitProgressSnapshot').CompactVisitStep} props.step
 * @param {boolean} props.isLast
 */
function HomeTimelineStep({ step, isLast }) {
  const isCompleted = step.stepState === 'completed';
  const isCurrent = step.stepState === 'current';
  const lineColor = isCompleted ? colors.success : colors.border;

  return (
    <View style={styles.timelineRow}>
      <View style={styles.timelineTrack}>
        {isCompleted ? (
          <View style={styles.dotDone}>
            <Ionicons name="checkmark" size={11} color={colors.white} />
          </View>
        ) : isCurrent ? (
          <View style={styles.dotCurrent}>
            <View style={styles.dotCurrentInner} />
          </View>
        ) : (
          <View style={styles.dotPending} />
        )}
        {!isLast ? <View style={[styles.timelineLine, { backgroundColor: lineColor }]} /> : null}
      </View>
      <View style={[styles.timelineBody, !isLast && styles.timelineBodySpaced]}>
        <Text
          style={[
            styles.timelineLabel,
            isCompleted && styles.timelineLabelDone,
            isCurrent && styles.timelineLabelCurrent,
            step.stepState === 'pending' && styles.timelineLabelPending,
          ]}
        >
          {step.label}
        </Text>
      </View>
    </View>
  );
}

/**
 * @param {object} props
 * @param {import('../utils/visitProgressSnapshot').CompactVisitStep[]} props.steps
 */
function HomeVisualTimeline({ steps }) {
  return (
    <View style={styles.timelinePanel}>
      {steps.map((step, index) => (
        <HomeTimelineStep key={step.id} step={step} isLast={index === steps.length - 1} />
      ))}
    </View>
  );
}

function TextLink({ label, onPress, accessibilityLabel }) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.textLink, pressed && styles.pressed]}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
    >
      <Text style={styles.textLinkLabel}>{label}</Text>
      <Ionicons name="chevron-forward" size={16} color={colors.primaryTeal} />
    </Pressable>
  );
}

const ANNOUNCEMENTS_PREVIEW = 2;
/** Bodies longer than this are likely clamped in the compact preview. */
const ANNOUNCEMENT_BODY_PREVIEW_CHARS = 90;

/**
 * Facility announcements (GET /api/announcements), compact on Home: the first
 * few in API order with one-line titles and two-line bodies; "View All"
 * expands the full list and text in place.
 * @param {object} props
 * @param {ReturnType<typeof useAnnouncements>} props.state
 */
function AnnouncementsSection({ state }) {
  const { announcements, loading, error, reload } = state;
  const [expanded, setExpanded] = useState(false);
  const shown = expanded ? announcements : announcements.slice(0, ANNOUNCEMENTS_PREVIEW);
  const hasMore = announcements.length > ANNOUNCEMENTS_PREVIEW;
  const canExpand =
    hasMore ||
    announcements.some((a) => (a.body?.length ?? 0) > ANNOUNCEMENT_BODY_PREVIEW_CHARS);

  let content;
  if (loading && announcements.length === 0) {
    content = <ActivityIndicator color={colors.primaryTeal} />;
  } else if (error && announcements.length === 0) {
    content = (
      <>
        <Text style={styles.noVisit}>{error}</Text>
        <TextLink label="Try Again" onPress={reload} accessibilityLabel="Reload announcements" />
      </>
    );
  } else if (announcements.length === 0) {
    content = <Text style={styles.noVisit}>No announcements right now.</Text>;
  } else {
    content = (
      <>
        {shown.map((item, index) => (
          <View
            key={item.id}
            style={[styles.announcementRow, index < shown.length - 1 && styles.announcementDivider]}
          >
            <Ionicons
              name={item.pinned ? 'megaphone' : 'megaphone-outline'}
              size={18}
              color={colors.primaryNavy}
            />
            <View style={styles.announcementBody}>
              <Text style={styles.announcementTitle} numberOfLines={expanded ? undefined : 1}>
                {item.title}
              </Text>
              <Text style={styles.announcementText} numberOfLines={expanded ? undefined : 2}>
                {item.body}
              </Text>
            </View>
          </View>
        ))}
        {canExpand ? (
          <TextLink
            label={
              expanded ? 'Show Less' : hasMore ? `View All (${announcements.length})` : 'Read More'
            }
            onPress={() => setExpanded((v) => !v)}
            accessibilityLabel={expanded ? 'Show fewer announcements' : 'View all announcements'}
          />
        ) : null}
      </>
    );
  }

  return (
    <View style={styles.homeSection}>
      <Text style={styles.sectionLabel}>Announcements</Text>
      <Card style={styles.visitCard}>{content}</Card>
    </View>
  );
}

/**
 * Visitor home dashboard — a quick overview: verification, upcoming visit,
 * progress snapshot (v2.1) and compact announcements. The visitation schedule
 * calendar lives under My Visits → Schedule.
 */
export default function DashboardScreen({ navigation }) {
  const { registrationSummary, user, isApprovedVisitor } = useAuth();
  const { visits } = useVisits();
  const { verification } = useVisitorVerification();
  const [profile, setProfile] = useState(LOCAL_PHOTO_DEFAULTS);
  const announcements = useAnnouncements();

  const visitorName =
    user?.fullName?.trim() || registrationSummary?.fullName?.trim() || 'Visitor';

  const greeting = useMemo(() => getTimeGreeting(), []);
  const initials = useMemo(() => getInitials(visitorName), [visitorName]);

  const verificationDisplay = useMemo(
    () =>
      resolveVerificationDisplay(
        verification?.verificationStatus,
        verification?.backendStatus ?? user?.verificationStatus,
      ),
    [verification, user?.verificationStatus],
  );

  const nextVisit = useMemo(() => {
    const assigned = visits.filter(
      (v) =>
        v.status === 'confirmed' ||
        v.status === 'pending_confirmation' ||
        v.status === 'assigned' ||
        v.status === 'scheduled',
    );
    if (assigned.length === 0) return null;
    return [...assigned].sort(
      (a, b) => new Date(a.scheduledAt) - new Date(b.scheduledAt),
    )[0];
  }, [visits]);

  // Real timeline for the next visit (GET /api/schedules/{id}/timeline).
  const { steps: nextVisitSteps } = useVisitTimeline(nextVisit?.id ?? null, nextVisit?.status);
  const timelineSteps = useMemo(
    () =>
      nextVisitSteps.map((step) => ({
        ...step,
        label: HOME_STEP_LABELS[step.id] ?? step.label,
      })),
    [nextVisitSteps],
  );

  const tabBarInset = useTabBarScrollInset();

  useEffect(() => {
    let cancelled = false;
    loadLocalProfile(LOCAL_PHOTO_DEFAULTS).then((loaded) => {
      if (!cancelled) setProfile(loaded);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  const onViewDocuments = useCallback(() => {
    navigation.navigate('VisitorVerificationDocuments');
  }, [navigation]);

  const onViewVisit = useCallback(() => {
    if (!nextVisit) return;
    navigation.navigate('VisitDetails', { visitId: nextVisit.id });
  }, [navigation, nextVisit]);

  const onViewFullTimeline = useCallback(() => {
    if (!nextVisit) return;
    navigation.navigate('Timeline', {
      scheduleId: nextVisit.id,
      visitId: nextVisit.id,
      visitStatus: nextVisit.status,
      pdlName: nextVisit.pdlName,
      referenceNumber: nextVisit.referenceNumber,
    });
  }, [navigation, nextVisit]);

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={[styles.scroll, { paddingBottom: tabBarInset }]}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        <View style={styles.headerRow}>
          <View style={styles.headerText}>
            <Text style={styles.greeting}>
              {greeting} {visitorName}
            </Text>
            <Text style={styles.welcomeBack}>Welcome Back</Text>
          </View>
          <DashboardAvatar photoUri={profile.photoUri} initials={initials} />
        </View>

        <CompactVerificationStatus
          display={verificationDisplay}
          onViewDocuments={onViewDocuments}
        />

        <Text style={styles.sectionLabel}>Upcoming Visit</Text>
        {nextVisit ? (
          <Card style={styles.visitCard}>
            <View style={styles.visitPrimaryRow}>
              <View style={styles.visitDateCol}>
                <Text style={styles.visitDate}>{nextVisit.dateDisplay}</Text>
                <Text style={styles.visitTime}>{nextVisit.timeLabel}</Text>
              </View>
              <StatusChip status={nextVisit.status} />
            </View>
            <Text style={styles.visitPdl}>{nextVisit.pdlName}</Text>
            <Button
              title="View Visit"
              onPress={onViewVisit}
              accessibilityLabel="View visit details"
            />
          </Card>
        ) : (
          <Card style={styles.visitCard}>
            <Text style={styles.noVisit}>
              No upcoming assigned visits. Choose an available visitation slot and submit a
              visit request for staff review.
            </Text>
            <View style={styles.noVisitAction}>
              <Button
                title="View My Visits"
                variant="secondary"
                onPress={() => navigation.navigate('Schedule')}
                accessibilityLabel="View my assigned visits"
              />
            </View>
          </Card>
        )}

        {nextVisit && timelineSteps.length > 0 ? (
          <View style={styles.progressSection}>
            <Text style={styles.sectionLabel}>Visit Progress</Text>
            <HomeVisualTimeline steps={timelineSteps} />
            <TextLink
              label="View Full Timeline"
              onPress={onViewFullTimeline}
              accessibilityLabel="View full visit timeline"
            />
          </View>
        ) : null}

        {isApprovedVisitor ? (
          <View style={styles.scheduleLink}>
            <TextLink
              label="View Visitation Schedule"
              onPress={() => navigation.navigate('Schedule', { tab: 'schedule' })}
              accessibilityLabel="View visitation schedule in My Visits"
            />
          </View>
        ) : null}

        <AnnouncementsSection state={announcements} />
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  scroll: {
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.sm,
  },
  headerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.lg,
  },
  headerText: {
    flex: 1,
    paddingRight: spacing.sm,
  },
  greeting: {
    ...typography.cardTitle,
    color: colors.textPrimary,
  },
  welcomeBack: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.xs,
  },
  avatar: {
    width: layout.buttonHeight,
    height: layout.buttonHeight,
    borderRadius: layout.buttonHeight / 2,
    backgroundColor: colors.primaryNavy,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: colors.card,
    overflow: 'hidden',
  },
  avatarImage: {
    width: '100%',
    height: '100%',
  },
  avatarText: {
    ...typography.metadata,
    fontWeight: '700',
    color: colors.white,
  },
  verificationStrip: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: spacing.sm,
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.sm,
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    marginBottom: spacing.md,
  },
  verificationMain: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    flex: 1,
  },
  verificationLabel: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flexShrink: 1,
  },
  verificationAction: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    paddingVertical: spacing.xs,
  },
  verificationActionText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.primaryTeal,
  },
  sectionLabel: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  visitCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.cardGap,
  },
  visitPrimaryRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: spacing.sm,
    marginBottom: spacing.sm,
  },
  visitDateCol: {
    flex: 1,
  },
  visitDate: {
    ...typography.cardTitle,
    color: colors.primaryNavy,
  },
  visitTime: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    marginTop: spacing.xs,
  },
  visitPdl: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  noVisit: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  noVisitAction: {
    marginTop: spacing.xs,
  },
  progressSection: {
    marginBottom: spacing.md,
  },
  timelinePanel: {
    backgroundColor: colors.card,
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    marginBottom: spacing.sm,
  },
  timelineRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
  },
  timelineTrack: {
    width: 22,
    alignItems: 'center',
    marginRight: spacing.sm,
    alignSelf: 'stretch',
  },
  dotDone: {
    width: 20,
    height: 20,
    borderRadius: 10,
    backgroundColor: colors.success,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 1,
  },
  dotCurrent: {
    width: 20,
    height: 20,
    borderRadius: 10,
    borderWidth: 2,
    borderColor: colors.primaryTeal,
    backgroundColor: colors.white,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 1,
  },
  dotCurrentInner: {
    width: 7,
    height: 7,
    borderRadius: 4,
    backgroundColor: colors.primaryTeal,
  },
  dotPending: {
    width: 20,
    height: 20,
    borderRadius: 10,
    borderWidth: 2,
    borderColor: GRAY_UPCOMING,
    backgroundColor: colors.white,
    zIndex: 1,
  },
  timelineLine: {
    flex: 1,
    width: 2,
    marginTop: spacing.xs,
    minHeight: spacing.sm,
    borderRadius: 1,
  },
  timelineBody: {
    flex: 1,
    paddingTop: spacing.xs,
  },
  timelineBodySpaced: {
    paddingBottom: spacing.xs,
  },
  timelineLabel: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  timelineLabelDone: {
    color: colors.textPrimary,
  },
  timelineLabelCurrent: {
    color: colors.primaryNavy,
    fontWeight: '700',
  },
  timelineLabelPending: {
    color: GRAY_UPCOMING,
    fontWeight: '500',
  },
  textLink: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    paddingVertical: spacing.xs,
    gap: spacing.xs,
  },
  textLinkLabel: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.primaryTeal,
  },
  scheduleLink: {
    marginTop: -spacing.sm,
    marginBottom: spacing.sm,
  },
  homeSection: {
    marginBottom: spacing.sm,
  },
  announcementRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    paddingVertical: spacing.sm,
  },
  announcementDivider: {
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  announcementBody: {
    flex: 1,
  },
  announcementTitle: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  announcementText: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.xs,
  },
  pressed: { opacity: 0.88 },
});
