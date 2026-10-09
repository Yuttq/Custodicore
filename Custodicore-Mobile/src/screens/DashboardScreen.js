import Ionicons from '@expo/vector-icons/Ionicons';
import { Image as ExpoImage } from 'expo-image';
import { useFocusEffect } from '@react-navigation/native';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
import RelationshipPicker from '../components/RelationshipPicker';
import VisitCalendar from '../components/VisitCalendar';
import { useAuth } from '../hooks/useAuth';
import { useVisits } from '../context/VisitsContext';
import useAnnouncements from '../hooks/useAnnouncements';
import useScheduleAvailability from '../hooks/useScheduleAvailability';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import useVisitorVerification from '../hooks/useVisitorVerification';
import { loadLocalProfile } from '../services/localProfileStorage';
import { pickNextVisit, visitTimeText } from '../utils/activeVisits';
import { announcementCategoryLabel, formatAnnouncementDate } from '../utils/announcementDisplay';
import { buildVisitDateMarkers, visitsForRelationship } from '../utils/visitCalendarMarkers';
import { resolveVisitStatusChip } from '../utils/visitStatusChip';

/** Only the avatar photo is device-local — the backend has no photo field. */
const LOCAL_PHOTO_DEFAULTS = { photoUri: null };

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
 * Verification reminder for Home, or null when none is needed. Profile shows
 * the verified badge, so Home only speaks up when the backend status
 * (GET /api/documents, falling back to the cached /api/me value) explicitly
 * says pending or rejected — e.g. staff changed it after this session began.
 * Verified, missing, or not-yet-loaded statuses show nothing.
 * @param {{ backendStatus?: string | null; verificationStatus?: string } | null | undefined} verification
 * @param {string | null | undefined} meStatus — pending | verified | rejected
 */
function resolveVerificationReminder(verification, meStatus) {
  const status = verification?.backendStatus ?? meStatus;
  if (status === 'rejected') {
    return {
      label: 'Verification Rejected',
      icon: 'close-circle-outline',
      accent: colors.danger,
      bg: 'rgba(239, 68, 68, 0.08)',
    };
  }
  if (status !== 'pending') return null;
  // Pending with nothing uploaded yet (known only from GET /api/documents).
  if (verification?.verificationStatus === 'verification_pending') {
    return {
      label: 'Verification Required',
      icon: 'alert-circle-outline',
      accent: colors.warning,
      bg: 'rgba(245, 158, 11, 0.12)',
    };
  }
  return {
    label: 'Documents Under Review',
    icon: 'time-outline',
    accent: colors.primaryNavy,
    bg: 'rgba(15, 61, 122, 0.08)',
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
 * Visitation calendar for Home: availability (GET /api/schedules/availability)
 * for one relationship at a time, with the visitor's own pending / confirmed
 * visits marked. Tapping an available date opens My Visits → Schedule with
 * that date; slots are chosen and requested there, never here.
 * @param {object} props
 * @param {ReturnType<typeof useScheduleAvailability>} props.schedule
 * @param {Record<string, 'pending' | 'confirmed'>} props.markedDates
 * @param {(date: string) => void} props.onSelectDate
 */
function HomeVisitCalendar({ schedule, markedDates, onSelectDate }) {
  const {
    loading,
    error,
    reload,
    message,
    today,
    from,
    to,
    relationships,
    relationship,
    selectRelationship,
    daysByDate,
  } = schedule;
  const hasAvailableDate = useMemo(
    () => Object.values(daysByDate).some((d) => d.available),
    [daysByDate],
  );

  let content;
  if (loading && !relationship) {
    content = <ActivityIndicator color={colors.primaryTeal} />;
  } else if (error && !relationship) {
    content = (
      <>
        <Text style={styles.noVisit}>{error}</Text>
        <TextLink label="Try Again" onPress={reload} accessibilityLabel="Reload visit availability" />
      </>
    );
  } else if (!relationship) {
    content = (
      <Text style={styles.noVisit}>
        {message ?? 'A verified PDL relationship is required to view visit availability.'}
      </Text>
    );
  } else {
    content = (
      <>
        <RelationshipPicker
          relationships={relationships}
          selectedId={relationship.relationshipId}
          onSelect={selectRelationship}
        />
        {relationship.pdlName ? (
          <Text style={styles.visitPdl}>{relationship.pdlName}</Text>
        ) : null}
        {relationship.message ? (
          <Text style={styles.calendarWarning}>{relationship.message}</Text>
        ) : !hasAvailableDate && !loading ? (
          <Text style={styles.calendarWarning}>
            No visit dates are available in the coming weeks. Please check again later.
          </Text>
        ) : (
          <Text style={styles.noVisit}>
            Tap an available date to choose a time slot and request a visit.
          </Text>
        )}
        <VisitCalendar
          key={relationship.relationshipId}
          daysByDate={daysByDate}
          today={today}
          minDate={from}
          maxDate={to}
          selectedDate={null}
          onSelectDate={onSelectDate}
          markedDates={markedDates}
          showSelection={false}
          compact
        />
      </>
    );
  }

  return (
    <View style={styles.homeSection}>
      <Text style={styles.sectionLabel}>Visitation Calendar</Text>
      <Card style={styles.visitCard}>{content}</Card>
    </View>
  );
}

/**
 * "Pinned · Schedule · Oct 7, 2026" above an announcement title, from the
 * fields GET /api/announcements already returns.
 * @param {object} props
 * @param {{ pinned: boolean; category?: string | null; createdAt?: string | null }} props.item
 */
function AnnouncementMeta({ item }) {
  const details = [
    announcementCategoryLabel(item.category),
    formatAnnouncementDate(item.createdAt),
  ].filter(Boolean);
  if (!item.pinned && details.length === 0) return null;

  return (
    <View style={styles.announcementMeta}>
      {item.pinned ? <Text style={styles.pinnedLabel}>Pinned</Text> : null}
      {details.length > 0 ? (
        <Text style={styles.announcementMetaText} numberOfLines={1}>
          {details.join(' · ')}
        </Text>
      ) : null}
    </View>
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
              <AnnouncementMeta item={item} />
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
 * Visitor home dashboard — a quick overview: the next visit, a verification
 * reminder when one is needed, a visitation calendar, and compact
 * announcements. Visit progress lives in Visit Details → Timeline; slot
 * selection and visit requests under My Visits → Schedule.
 */
export default function DashboardScreen({ navigation }) {
  const { registrationSummary, user, isApprovedVisitor } = useAuth();
  const { visits, refreshVisits } = useVisits();
  const { verification } = useVisitorVerification();
  // Availability is an approved-visitor API (visitor.approved middleware).
  const schedule = useScheduleAvailability({ enabled: isApprovedVisitor });
  const { reload: reloadAvailability } = schedule;

  // Reload visits and availability when Home regains focus so staff
  // decisions and new requests show up. The first focus is skipped:
  // VisitsProvider and useScheduleAvailability already load on mount.
  const focusedOnce = useRef(false);
  useFocusEffect(
    useCallback(() => {
      if (!focusedOnce.current) {
        focusedOnce.current = true;
        return;
      }
      refreshVisits().catch(() => {
        // error already stored in VisitsContext
      });
      reloadAvailability();
    }, [refreshVisits, reloadAvailability]),
  );
  const [profile, setProfile] = useState(LOCAL_PHOTO_DEFAULTS);
  const announcements = useAnnouncements();

  const visitorName =
    user?.fullName?.trim() || registrationSummary?.fullName?.trim() || 'Visitor';

  const greeting = useMemo(() => getTimeGreeting(), []);
  const initials = useMemo(() => getInitials(visitorName), [visitorName]);

  const verificationReminder = useMemo(
    () => resolveVerificationReminder(verification, user?.verificationStatus),
    [verification, user?.verificationStatus],
  );

  const nextVisit = useMemo(() => pickNextVisit(visits), [visits]);

  const { relationship, relationships, today: availabilityToday } = schedule;
  const markedDates = useMemo(
    () =>
      relationship && availabilityToday
        ? buildVisitDateMarkers(
            visitsForRelationship(visits, relationship, relationships),
            availabilityToday,
          )
        : {},
    [visits, relationship, relationships, availabilityToday],
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

  // Schedule opens on the relationship Home shows (when there is one).
  const relationshipId = relationship?.relationshipId ?? null;
  const onOpenSchedule = useCallback(() => {
    navigation.navigate(
      'Schedule',
      relationshipId ? { tab: 'schedule', relationshipId } : { tab: 'schedule' },
    );
  }, [navigation, relationshipId]);

  const onSelectCalendarDate = useCallback(
    (date) => {
      if (!relationshipId) return;
      navigation.navigate('Schedule', { tab: 'schedule', date, relationshipId });
    },
    [navigation, relationshipId],
  );

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

        {verificationReminder ? (
          <CompactVerificationStatus
            display={verificationReminder}
            onViewDocuments={onViewDocuments}
          />
        ) : null}

        <Text style={styles.sectionLabel}>Upcoming Visit</Text>
        {nextVisit ? (
          <Card style={styles.visitCard}>
            <View style={styles.visitPrimaryRow}>
              <View style={styles.visitDateCol}>
                <Text style={styles.visitDate}>{nextVisit.dateDisplay}</Text>
                <Text style={styles.visitTime}>{visitTimeText(nextVisit)}</Text>
              </View>
              <StatusChip status={resolveVisitStatusChip(nextVisit.status)} />
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
              You have no upcoming visits. Request a visit by choosing an available visitation
              slot; facility staff will review your request.
            </Text>
            <View style={styles.noVisitAction}>
              <Button
                title="Request a Visit"
                variant="secondary"
                onPress={onOpenSchedule}
                accessibilityLabel="Request a visit from the visitation schedule"
              />
            </View>
          </Card>
        )}

        {isApprovedVisitor ? (
          <>
            <HomeVisitCalendar
              schedule={schedule}
              markedDates={markedDates}
              onSelectDate={onSelectCalendarDate}
            />
            <View style={styles.scheduleLink}>
              <TextLink
                label="View Visitation Schedule"
                onPress={onOpenSchedule}
                accessibilityLabel="View visitation schedule in My Visits"
              />
            </View>
          </>
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
  calendarWarning: {
    ...typography.metadata,
    color: colors.warningText,
    fontWeight: '600',
    marginBottom: spacing.sm,
  },
  announcementMeta: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    marginBottom: spacing.xs,
  },
  pinnedLabel: {
    ...typography.statusLabel,
    color: colors.primaryTeal,
    fontWeight: '700',
  },
  announcementMetaText: {
    ...typography.statusLabel,
    color: colors.textSecondary,
    flexShrink: 1,
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
