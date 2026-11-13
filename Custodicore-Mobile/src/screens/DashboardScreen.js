import Ionicons from '@expo/vector-icons/Ionicons';
import { Image as ExpoImage } from 'expo-image';
import { useFocusEffect } from '@react-navigation/native';
import useAutoRefresh from '../hooks/useAutoRefresh';
import React, { useCallback, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Card,
  StatusChip,
  colors,
  layout,
  spacing,
  typography,
} from '../designSystem';
import VisitCalendar from '../components/VisitCalendar';
import { useAuth } from '../hooks/useAuth';
import { useVisits } from '../context/VisitsContext';
import useAnnouncements from '../hooks/useAnnouncements';
import useScheduleAvailability from '../hooks/useScheduleAvailability';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import useVisitorVerification from '../hooks/useVisitorVerification';
import { loadLocalProfile } from '../services/localProfileStorage';
import { pickNextVisit, visitTimeText } from '../utils/activeVisits';
import {
  PDL_REQUEST_LIMIT_TITLE,
  canAddAnotherPdl,
  pdlRequestLimitMessage,
  pdlRequestLimitReached,
} from '../utils/pdlLinkRequest';
import { announcementCategoryLabel, formatAnnouncementDate } from '../utils/announcementDisplay';
import {
  calendarDateHandoff,
  isWeeklyLimitDay,
  WEEKLY_LIMIT_REACHED_TITLE,
  weeklyLimitReachedNotice,
} from '../utils/scheduleAvailability';
import { noAvailabilityNotice } from '../utils/noAvailabilityMessage';
import { buildVisitDateMarkers, visitsForRelationship } from '../utils/visitCalendarMarkers';
import { RELATIONSHIP_NOT_VERIFIED_SCHEDULE } from '../utils/visitGuidance';
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
 * Home's "Upcoming Visit": the next visit only, as one compact row — date,
 * time and PDL with its status. Tapping the row opens Visit Details; "View My
 * Visits" opens the My Visits tab. Home hides it when there is no next visit.
 * @param {object} props
 * @param {{ id: string; dateDisplay?: string; pdlName?: string; status?: string; timeLabel?: string | null; isWholeDay?: boolean }} props.visit
 * @param {() => void} props.onViewVisit
 * @param {() => void} props.onViewMyVisits
 */
function CompactUpcomingVisit({ visit, onViewVisit, onViewMyVisits }) {
  const time = visitTimeText(visit);
  const when = [visit.dateDisplay, time].filter(Boolean).join(' · ');
  // Date and time each get their own line so neither is cut off beside the
  // status chip (e.g. "November 12, 2026" / "09:00 - 16:30 · Whole day").
  const dateLine = visit.dateDisplay || time;
  const timeLine = visit.dateDisplay ? time : '';

  return (
    <View style={styles.upcomingSection}>
      <View style={styles.upcomingHeader}>
        <Text style={[styles.sectionLabel, styles.upcomingLabel]}>Upcoming Visit</Text>
        <TextLink
          label="View My Visits"
          onPress={onViewMyVisits}
          accessibilityLabel="View all your visits in My Visits"
        />
      </View>
      <Pressable
        onPress={onViewVisit}
        style={({ pressed }) => [styles.upcomingRow, pressed && styles.pressed]}
        accessibilityRole="button"
        accessibilityLabel={`Next visit: ${when}${visit.pdlName ? `, ${visit.pdlName}` : ''}. Tap for visit details.`}
      >
        <Ionicons name="calendar-outline" size={20} color={colors.primaryNavy} />
        <View style={styles.upcomingText}>
          {dateLine ? <Text style={styles.upcomingWhen}>{dateLine}</Text> : null}
          {timeLine ? <Text style={styles.upcomingTime}>{timeLine}</Text> : null}
          {visit.pdlName ? <Text style={styles.upcomingPdl}>{visit.pdlName}</Text> : null}
        </View>
        <StatusChip status={resolveVisitStatusChip(visit.status)} />
      </Pressable>
    </View>
  );
}

/**
 * The visitor's verified PDLs as chips above the Home calendar, plus the
 * "+ Add PDL" chip that opens the Phase 5B "Add Another PDL" request form. One
 * chip is selected at a time and the calendar shows that PDL's dates;
 * tapping a chip stays on Home.
 * @param {object} props
 * @param {{ relationshipId: string; pdlName: string | null }[]} props.relationships
 * @param {string | null} [props.selectedId]
 * @param {(relationshipId: string) => void} [props.onSelect]
 * @param {boolean} props.canAdd — approved account (stays shown while requests are open)
 * @param {() => void} props.onAdd
 */
function HomePdlChips({ relationships, selectedId, onSelect, canAdd, onAdd }) {
  if (relationships.length === 0 && !canAdd) return null;

  return (
    <View style={styles.pdlChipRow}>
      {relationships.map((r) => {
        const active = r.relationshipId === selectedId;
        return (
          <Pressable
            key={r.relationshipId}
            onPress={() => onSelect?.(r.relationshipId)}
            style={({ pressed }) => [
              styles.pdlChip,
              active && styles.pdlChipActive,
              pressed && styles.pressed,
            ]}
            accessibilityRole="button"
            accessibilityState={{ selected: active }}
            accessibilityLabel={`Show visit dates for ${r.pdlName ?? 'PDL'}`}
          >
            <Text style={[styles.pdlChipText, active && styles.pdlChipTextActive]} numberOfLines={1}>
              {r.pdlName ?? 'PDL'}
            </Text>
          </Pressable>
        );
      })}
      {canAdd ? (
        // A labelled chip in the same row as the PDLs, so it never sits alone.
        <Pressable
          onPress={onAdd}
          hitSlop={8}
          style={({ pressed }) => [styles.pdlChip, styles.addPdlChip, pressed && styles.pressed]}
          accessibilityRole="button"
          accessibilityLabel="Add another PDL. Ask facility staff to add another PDL to your account"
        >
          <Ionicons name="add" size={16} color={colors.primaryTeal} />
          <Text style={styles.addPdlText} numberOfLines={1}>
            {relationships.length === 0 ? 'Add Another PDL' : 'Add PDL'}
          </Text>
        </Pressable>
      ) : null}
    </View>
  );
}

/**
 * Visitation calendar for Home, PDL first: the chips pick one verified
 * relationship (GET /api/schedules/availability) and the calendar shows only
 * that PDL's dates, with the visitor's own pending / confirmed visits with
 * that PDL marked. Tapping an available date opens My Visits → Schedule with
 * that PDL and date already selected; slots are chosen and requested there.
 * @param {object} props
 * @param {ReturnType<typeof useScheduleAvailability>} props.schedule
 * @param {Record<string, 'pending' | 'confirmed'>} props.markedDates — the selected PDL's visits
 * @param {(params: { tab: 'schedule'; date: string; relationshipId: string }) => void} props.onOpenDate
 * @param {boolean} props.canAddPdl — show the (+) Add Another PDL button
 * @param {() => void} props.onAddPdl
 */
function HomeVisitCalendar({ schedule, markedDates, onOpenDate, canAddPdl, onAddPdl }) {
  const {
    loading,
    error,
    reload,
    today,
    from,
    to,
    weeklyLimit,
    relationships,
    relationship,
    selectRelationship,
    daysByDate,
  } = schedule;
  // Null while a date is bookable. Read from the loaded days only (not
  // `loading`), so a background refresh never swaps in the "Tap an
  // available date" hint over a calendar with nothing to tap.
  const availabilityNotice = useMemo(
    () => noAvailabilityNotice(relationship, weeklyLimit),
    [relationship, weeklyLimit],
  );
  // Dates blocked only by the visitor-wide weekly limit stay tappable so the
  // visitor learns why they cannot book (the backend still enforces it).
  const weeklyLimitDates = useMemo(() => {
    /** @type {Record<string, boolean>} */
    const out = {};
    for (const [date, day] of Object.entries(daysByDate)) {
      if (isWeeklyLimitDay(day)) out[date] = true;
    }
    return out;
  }, [daysByDate]);
  // Names the tapped date's week, which need not be the current one.
  const onWeeklyLimitDatePress = useCallback((date) => {
    Alert.alert(WEEKLY_LIMIT_REACHED_TITLE, weeklyLimitReachedNotice(weeklyLimit?.limit, date), [
      { text: 'OK' },
    ]);
  }, [weeklyLimit]);

  // Only a date the selected PDL has an available session on opens Schedule.
  const onSelectDate = useCallback(
    (date) => {
      const params = calendarDateHandoff(relationship, date);
      if (params) onOpenDate(params);
    },
    [relationship, onOpenDate],
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
    // The API's top-level `message` only covers this case (no verified
    // relationship); the app's wording keeps Home and Schedule consistent.
    content = (
      <>
        <HomePdlChips relationships={[]} canAdd={canAddPdl} onAdd={onAddPdl} />
        <Text style={styles.noVisit}>{RELATIONSHIP_NOT_VERIFIED_SCHEDULE}</Text>
      </>
    );
  } else {
    content = (
      <>
        {/* The verified PDLs (one selected) and the "+ Add PDL" chip. */}
        <HomePdlChips
          relationships={relationships}
          selectedId={relationship.relationshipId}
          onSelect={selectRelationship}
          canAdd={canAddPdl}
          onAdd={onAddPdl}
        />
        {relationship.message ? (
          <Text style={styles.calendarWarning}>{relationship.message}</Text>
        ) : availabilityNotice ? (
          <View style={styles.calendarNotice}>
            <Ionicons name="information-circle-outline" size={18} color={colors.textSecondary} />
            <Text style={styles.calendarNoticeText}>{availabilityNotice}</Text>
          </View>
        ) : (
          <Text style={styles.noVisit}>Tap an available date to choose a time slot.</Text>
        )}
        {/* Tapping a date leaves Home, so no date stays selected here and the
            Selected legend stays hidden (showSelection={false}). */}
        <VisitCalendar
          daysByDate={daysByDate}
          today={today}
          minDate={from}
          maxDate={to}
          selectedDate={null}
          onSelectDate={onSelectDate}
          markedDates={markedDates}
          showSelection={false}
          blockedDates={weeklyLimitDates}
          onBlockedDatePress={onWeeklyLimitDatePress}
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
  // While Home stays open, keep visits and the calendar current.
  useAutoRefresh(() =>
    Promise.allSettled([
      refreshVisits({ silent: true }),
      isApprovedVisitor ? reloadAvailability({ silent: true }) : null,
    ]),
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

  // Phase 5B "Add Another PDL": always shown to approved accounts
  // (AddPdlRequest is only registered for approved visitors). At the
  // open-request limit it explains why instead of opening the form; the
  // backend checks approval and the limit again.
  const canAddPdl = isApprovedVisitor && canAddAnotherPdl(verification?.backendStatus);
  const pdlRequestLimit = verification?.pdlRequestLimit ?? null;
  const onAddPdl = useCallback(() => {
    if (pdlRequestLimitReached(pdlRequestLimit)) {
      Alert.alert(PDL_REQUEST_LIMIT_TITLE, pdlRequestLimitMessage(pdlRequestLimit.max), [{ text: 'OK' }]);
      return;
    }
    navigation.navigate('AddPdlRequest');
  }, [navigation, pdlRequestLimit]);

  const { relationship, relationships, today: availabilityToday } = schedule;
  // Visits can be requested only for a staff-verified relationship, which
  // is exactly what availability returns.
  const hasVerifiedRelationship = isApprovedVisitor && Boolean(relationship);
  // The selected PDL's upcoming visits only (matched by PDL name; none when
  // the name is missing or shared, never guessed).
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

  // The signed-in account's photo, reloaded each time Home comes into view
  // so a photo changed or removed on Profile shows here right away.
  const accountId = user?.id ?? null;
  useFocusEffect(
    useCallback(() => {
      let cancelled = false;
      loadLocalProfile(LOCAL_PHOTO_DEFAULTS, accountId).then((loaded) => {
        if (!cancelled) setProfile(loaded);
      });
      return () => {
        cancelled = true;
      };
    }, [accountId]),
  );

  const onViewDocuments = useCallback(() => {
    navigation.navigate('VisitorVerificationDocuments');
  }, [navigation]);

  const onViewVisit = useCallback(() => {
    if (!nextVisit) return;
    navigation.navigate('VisitDetails', { visitId: nextVisit.id });
  }, [navigation, nextVisit]);

  // My Visits tab, on its Upcoming list.
  const onViewMyVisits = useCallback(() => {
    navigation.navigate('Schedule', { tab: 'upcoming' });
  }, [navigation]);

  // "View Visitation Schedule" opens the Schedule tab on the PDL selected on
  // Home (the first verified relationship until a chip is tapped).
  const relationshipId = relationship?.relationshipId ?? null;
  const onOpenSchedule = useCallback(() => {
    navigation.navigate(
      'Schedule',
      relationshipId ? { tab: 'schedule', relationshipId } : { tab: 'schedule' },
    );
  }, [navigation, relationshipId]);

  // The selected PDL, then a date on the Home calendar: the Schedule tab
  // opens with both selected (existing handoff); slots are picked there.
  const onOpenCalendarDate = useCallback(
    (params) => navigation.navigate('Schedule', params),
    [navigation],
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

        {/* Only the next visit, as one compact row; hidden when there is none. */}
        {nextVisit ? (
          <CompactUpcomingVisit
            visit={nextVisit}
            onViewVisit={onViewVisit}
            onViewMyVisits={onViewMyVisits}
          />
        ) : null}

        {isApprovedVisitor ? (
          <>
            <HomeVisitCalendar
              schedule={schedule}
              markedDates={markedDates}
              onOpenDate={onOpenCalendarDate}
              canAddPdl={canAddPdl}
              onAddPdl={onAddPdl}
            />
            {/* The Schedule tab only repeats the "not verified" notice until then. */}
            {hasVerifiedRelationship ? (
              <View style={styles.scheduleLink}>
                <TextLink
                  label="View Visitation Schedule"
                  onPress={onOpenSchedule}
                  accessibilityLabel="View visitation schedule in My Visits"
                />
              </View>
            ) : null}
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
    marginBottom: spacing.md,
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
  // Compact "Upcoming Visit": header with "View My Visits", then one row.
  upcomingSection: {
    marginBottom: spacing.md,
  },
  upcomingHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: spacing.sm,
    marginBottom: spacing.xs,
  },
  upcomingLabel: {
    marginBottom: 0,
  },
  upcomingRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    minHeight: 48,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
  },
  upcomingText: {
    flex: 1,
  },
  upcomingWhen: {
    ...typography.body,
    fontWeight: '600',
    color: colors.primaryNavy,
  },
  upcomingTime: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  upcomingPdl: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  noVisit: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
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
  // Verified PDLs (display only) + the "+ Add PDL" chip.
  pdlChipRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    alignItems: 'center',
    gap: spacing.sm,
    marginBottom: spacing.sm,
  },
  pdlChip: {
    maxWidth: '100%',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs,
    borderRadius: layout.chipRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
  },
  // Selected PDL: same look as RelationshipPicker's active chip.
  pdlChipActive: {
    borderColor: colors.primaryNavy,
    backgroundColor: colors.primaryNavy,
  },
  pdlChipText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  pdlChipTextActive: {
    color: colors.white,
  },
  addPdlChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    paddingLeft: spacing.sm + spacing.xs,
    borderStyle: 'dashed',
    borderColor: colors.primaryTeal,
    backgroundColor: colors.card,
  },
  addPdlText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.primaryTeal,
  },
  // Why no date can be booked: quiet, with an icon, not a bold warning.
  calendarNotice: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    marginBottom: spacing.sm,
  },
  calendarNoticeText: {
    ...typography.metadata,
    color: colors.textSecondary,
    flex: 1,
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
