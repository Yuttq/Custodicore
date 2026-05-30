import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useMemo } from 'react';
import {
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
import { useNotificationBadge } from '../context/NotificationBadgeContext';
import { useAuth } from '../hooks/useAuth';
import { useVisits } from '../context/VisitsContext';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import { MOCK_VERIFICATION_ITEMS } from '../mock/dashboard.mock';
import { DEFAULT_LOCAL_PROFILE } from '../mock/profile.mock';

const QUICK_ACTIONS_TOP = [
  { label: 'My Visits', icon: 'calendar-outline', tab: 'Schedule', variant: 'standard' },
  { label: 'QR Pass', icon: 'qr-code', tab: 'QR', variant: 'primary' },
];

const QUICK_ACTIONS_BOTTOM = [
  { label: 'Notifications', icon: 'notifications-outline', tab: 'Notifications', variant: 'standard' },
  { label: 'Profile', icon: 'person-outline', tab: 'Profile', variant: 'standard' },
];

function getTimeGreeting() {
  const hour = new Date().getHours();
  if (hour < 12) return 'Good Morning,';
  if (hour < 17) return 'Good Afternoon,';
  return 'Good Evening,';
}

function QuickActionTile({ action, onPress }) {
  const isPrimary = action.variant === 'primary';

  return (
    <Pressable
      style={[styles.quickItem, isPrimary && styles.quickItemPrimary]}
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={action.label}
    >
      <View style={[styles.quickIconCircle, isPrimary && styles.quickIconPrimary]}>
        <Ionicons
          name={action.icon}
          size={isPrimary ? 30 : 26}
          color={isPrimary ? colors.white : colors.primaryTeal}
        />
      </View>
      <Text style={[styles.quickLabel, isPrimary && styles.quickLabelPrimary]}>
        {action.label}
      </Text>
    </Pressable>
  );
}

/**
 * Visitor home — verification status, next assigned visit, quick actions (v2.1 / BJMP).
 */
export default function DashboardScreen({ navigation }) {
  const { unreadCount } = useNotificationBadge();
  const { registrationSummary, pendingVerification } = useAuth();
  const { visits } = useVisits();

  const visitorName =
    registrationSummary?.fullName?.trim() || DEFAULT_LOCAL_PROFILE.fullName;

  const greeting = useMemo(() => getTimeGreeting(), []);
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
  const isVerified = !pendingVerification;

  const tabBarInset = useTabBarScrollInset();

  const onViewVisitDetails = () => {
    if (!nextVisit) return;
    navigation.navigate('VisitDetails', { visitId: nextVisit.id });
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={[styles.scroll, { paddingBottom: tabBarInset }]}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        <View style={styles.headerRow}>
          <View style={styles.headerText}>
            <Text style={styles.greeting}>{greeting}</Text>
            <Text style={styles.visitorName}>{visitorName}</Text>
            <View style={styles.badgeRow}>
              {isVerified ? (
                <View style={styles.verifiedBadge}>
                  <Ionicons name="checkmark-circle" size={14} color={colors.success} />
                  <Text style={styles.verifiedBadgeText}>Verified Visitor</Text>
                </View>
              ) : (
                <StatusChip status="pending_verification" />
              )}
            </View>
          </View>

          <Pressable
            onPress={() => navigation.navigate('Notifications')}
            style={styles.notifyBtn}
            accessibilityRole="button"
            accessibilityLabel="Notifications"
          >
            <Ionicons name="notifications-outline" size={24} color={colors.primaryNavy} />
            {unreadCount > 0 ? <View style={styles.notifyDot} /> : null}
          </Pressable>
        </View>

        <View style={styles.navyCard}>
          <View style={styles.navyCardTop}>
            <Text style={styles.navyTitle}>Verification Status</Text>
            <Ionicons name="shield-checkmark" size={40} color="rgba(255,255,255,0.35)" />
          </View>
          {MOCK_VERIFICATION_ITEMS.map((item) => (
            <View key={item.id} style={styles.verifyRow}>
              <Ionicons name="checkmark-circle" size={20} color={colors.success} />
              <Text style={styles.verifyLabel}>{item.label}</Text>
            </View>
          ))}
        </View>

        <Text style={styles.sectionLabel}>Next Assigned Visit</Text>
        {nextVisit ? (
          <Card style={styles.visitCard}>
            <View style={styles.visitDateBlock}>
              <Text style={styles.visitDateMonth}>{nextVisit.dateDisplay}</Text>
            </View>
            <View style={styles.visitDetails}>
              <Text style={styles.visitTime}>{nextVisit.timeLabel}</Text>
              <Text style={styles.visitPdl}>{nextVisit.pdlName}</Text>
              <Text style={styles.visitLocation}>
                {nextVisit.facility || nextVisit.location}
              </Text>
              <View style={styles.visitChipRow}>
                <StatusChip status={nextVisit.status} />
              </View>
            </View>
            <View style={styles.visitButtonWrap}>
              <Button
                title="View Visit Details"
                onPress={onViewVisitDetails}
                accessibilityLabel="View visit details"
              />
            </View>
          </Card>
        ) : (
          <Card style={styles.visitCard}>
            <Text style={styles.noVisit}>No upcoming assigned visits.</Text>
          </Card>
        )}

        <Text style={styles.sectionLabel}>Quick Actions</Text>
        <View style={styles.quickSection}>
          <View style={styles.quickRow}>
            {QUICK_ACTIONS_TOP.map((action) => (
              <QuickActionTile
                key={action.label}
                action={action}
                onPress={() => navigation.navigate(action.tab)}
              />
            ))}
          </View>
          <View style={styles.quickRow}>
            {QUICK_ACTIONS_BOTTOM.map((action) => (
              <QuickActionTile
                key={action.label}
                action={action}
                onPress={() => navigation.navigate(action.tab)}
              />
            ))}
          </View>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  scroll: {
    paddingHorizontal: spacing[20],
  },
  headerRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    marginBottom: layout.sectionGap,
  },
  headerText: { flex: 1, paddingRight: spacing[12] },
  greeting: {
    ...typography.body,
    color: colors.textSecondary,
  },
  visitorName: {
    ...typography.screenTitle,
    color: colors.textPrimary,
    marginTop: spacing[4],
  },
  badgeRow: {
    marginTop: spacing[8],
    alignSelf: 'flex-start',
  },
  verifiedBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[8],
    backgroundColor: 'rgba(22, 163, 74, 0.12)',
    paddingHorizontal: spacing[12],
    paddingVertical: spacing[4],
    borderRadius: layout.chipRadius,
  },
  verifiedBadgeText: {
    ...typography.statusLabel,
    color: colors.success,
    fontWeight: '600',
  },
  notifyBtn: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  notifyDot: {
    position: 'absolute',
    top: 10,
    right: 10,
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.danger,
    borderWidth: 1.5,
    borderColor: colors.white,
  },
  navyCard: {
    backgroundColor: colors.primaryNavy,
    borderRadius: layout.cardRadius,
    padding: spacing[16],
    marginBottom: layout.cardGap,
  },
  navyCardTop: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing[16],
  },
  navyTitle: {
    ...typography.cardTitle,
    color: colors.white,
  },
  verifyRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[12],
    marginBottom: spacing[12],
  },
  verifyLabel: {
    ...typography.body,
    color: colors.white,
    flex: 1,
  },
  sectionLabel: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
    marginBottom: spacing[12],
  },
  visitCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.sectionGap,
    padding: spacing[16],
  },
  visitDateBlock: {
    alignSelf: 'flex-start',
    backgroundColor: colors.background,
    borderRadius: 12,
    paddingHorizontal: spacing[12],
    paddingVertical: spacing[8],
    marginBottom: spacing[12],
  },
  visitDateMonth: {
    ...typography.cardTitle,
    color: colors.primaryNavy,
  },
  visitDetails: {
    marginBottom: spacing[16],
  },
  visitTime: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing[4],
  },
  visitPdl: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing[4],
  },
  visitLocation: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[12],
  },
  visitChipRow: {
    alignSelf: 'flex-start',
  },
  visitButtonWrap: {
    marginTop: spacing[4],
  },
  noVisit: {
    ...typography.body,
    color: colors.textSecondary,
    textAlign: 'center',
  },
  quickSection: {
    marginHorizontal: -spacing[8],
  },
  quickRow: {
    flexDirection: 'row',
    marginBottom: spacing[16],
  },
  quickItem: {
    flex: 1,
    paddingHorizontal: spacing[8],
    alignItems: 'center',
  },
  quickItemPrimary: {
    flex: 1.15,
  },
  quickIconCircle: {
    width: 56,
    height: 56,
    borderRadius: 28,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing[8],
  },
  quickIconPrimary: {
    width: 64,
    height: 64,
    borderRadius: 32,
    backgroundColor: colors.primaryTeal,
    borderWidth: 0,
  },
  quickLabel: {
    ...typography.caption,
    color: colors.textPrimary,
    fontWeight: '600',
    textAlign: 'center',
  },
  quickLabelPrimary: {
    color: colors.primaryTeal,
  },
});
