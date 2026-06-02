import Ionicons from '@expo/vector-icons/Ionicons';
import { Image as ExpoImage } from 'expo-image';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
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
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import { DEFAULT_LOCAL_PROFILE } from '../mock/profile.mock';
import { getMockVisitorVerification } from '../mock/visitorVerificationDocuments.mock';
import { loadLocalProfile } from '../services/localProfileStorage';
import { getCompactVisitSteps } from '../utils/visitProgressSnapshot';

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
 * @param {string} relationshipId
 * @param {boolean} pendingVerification
 * @param {string} registrationStatus
 */
function resolveVerificationDisplay(relationshipId, pendingVerification, registrationStatus) {
  const { verificationStatus } = getMockVisitorVerification(relationshipId);

  if (verificationStatus === 'verification_verified') {
    return {
      label: 'Verified Visitor',
      icon: 'shield-checkmark',
      accent: colors.success,
      bg: 'rgba(22, 163, 74, 0.1)',
    };
  }
  if (verificationStatus === 'verification_under_review') {
    return {
      label: 'Documents Under Review',
      icon: 'time-outline',
      accent: colors.primaryNavy,
      bg: 'rgba(15, 61, 122, 0.08)',
    };
  }
  if (!pendingVerification && registrationStatus === 'approved') {
    return {
      label: 'Verified Visitor',
      icon: 'shield-checkmark',
      accent: colors.success,
      bg: 'rgba(22, 163, 74, 0.1)',
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

/**
 * Visitor home dashboard — verification, upcoming visit, progress snapshot (v2.1).
 */
export default function DashboardScreen({ navigation }) {
  const { registrationSummary, pendingVerification } = useAuth();
  const { visits } = useVisits();
  const [profile, setProfile] = useState(DEFAULT_LOCAL_PROFILE);

  const visitorName =
    registrationSummary?.fullName?.trim() ||
    profile.fullName ||
    DEFAULT_LOCAL_PROFILE.fullName;

  const relationshipId =
    registrationSummary?.relationship ??
    profile.relationshipToPdl ??
    DEFAULT_LOCAL_PROFILE.relationshipToPdl ??
    'spouse';

  const greeting = useMemo(() => getTimeGreeting(), []);
  const initials = useMemo(() => getInitials(visitorName), [visitorName]);

  const verificationDisplay = useMemo(
    () =>
      resolveVerificationDisplay(
        relationshipId,
        pendingVerification,
        profile.registrationStatus,
      ),
    [relationshipId, pendingVerification, profile.registrationStatus],
  );

  const nextVisit = useMemo(() => {
    const assigned = visits.filter(
      (v) =>
        v.status === 'confirmed' ||
        v.status === 'pending_confirmation' ||
        v.status === 'scheduled',
    );
    if (assigned.length === 0) return null;
    return [...assigned].sort(
      (a, b) => new Date(a.scheduledAt) - new Date(b.scheduledAt),
    )[0];
  }, [visits]);

  const timelineSteps = useMemo(() => {
    if (!nextVisit) return [];
    return getCompactVisitSteps(String(nextVisit.id), nextVisit.status).map((step) => ({
      ...step,
      label: HOME_STEP_LABELS[step.id] ?? step.label,
    }));
  }, [nextVisit]);

  const tabBarInset = useTabBarScrollInset();

  useEffect(() => {
    let cancelled = false;
    loadLocalProfile(DEFAULT_LOCAL_PROFILE).then((loaded) => {
      if (!cancelled) setProfile(loaded);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  const onViewDocuments = useCallback(() => {
    navigation.navigate('VisitorVerificationDocuments', {
      relationshipId,
    });
  }, [navigation, relationshipId]);

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
              No upcoming assigned visits. Schedules are assigned by facility officers.
            </Text>
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
    width: 48,
    height: 48,
    borderRadius: 24,
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
    ...typography.body,
    fontWeight: '700',
    color: colors.white,
    fontSize: 15,
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
    fontSize: 18,
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
  pressed: { opacity: 0.88 },
});
