import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useMemo } from 'react';
import {
  Alert,
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
import { FACILITY_RULES, VISITOR_REMINDERS } from '../mock/assignedVisits.mock';
import { MOCK_VERIFICATION_ITEMS } from '../mock/dashboard.mock';
import { DEFAULT_LOCAL_PROFILE } from '../mock/profile.mock';

/** Auxiliary shortcuts — excludes items already in bottom navigation. */
const QUICK_ACTION_ROWS = [
  [
    { id: 'history', label: 'Visit History', icon: 'time-outline' },
    { id: 'documents', label: 'Verification Documents', icon: 'document-text-outline' },
  ],
  [
    { id: 'guidelines', label: 'Facility Guidelines', icon: 'book-outline' },
    { id: 'help', label: 'Help Center', icon: 'help-circle-outline' },
  ],
];

function getTimeGreeting() {
  const hour = new Date().getHours();
  if (hour < 12) return 'Good Morning,';
  if (hour < 17) return 'Good Afternoon,';
  return 'Good Evening,';
}

function QuickActionTile({ action, onPress }) {
  return (
    <Pressable
      style={styles.quickItem}
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={action.label}
    >
      <View style={styles.quickIconCircle}>
        <Ionicons name={action.icon} size={26} color={colors.primaryTeal} />
      </View>
      <Text style={styles.quickLabel}>{action.label}</Text>
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

  const onQuickAction = useCallback(
    (actionId) => {
      if (actionId === 'history') {
        navigation.navigate('VisitHistory');
        return;
      }
      if (actionId === 'documents') {
        navigation.navigate('VisitorVerificationDocuments', {
          relationshipId:
            registrationSummary?.relationship ??
            DEFAULT_LOCAL_PROFILE.relationshipToPdl ??
            'spouse',
        });
        return;
      }
      if (actionId === 'guidelines') {
        const body = [
          'Facility rules:',
          ...FACILITY_RULES.map((rule) => `• ${rule}`),
          '',
          'Visitor reminders:',
          ...VISITOR_REMINDERS.map((reminder) => `• ${reminder}`),
        ].join('\n');
        Alert.alert('Facility Guidelines', body);
        return;
      }
      if (actionId === 'help') {
        Alert.alert(
          'Help Center',
          'For visit scheduling, verification, or facility questions, contact the BJMP facility front desk during business hours or use the reference number on your assigned visit.',
        );
      }
    },
    [navigation, registrationSummary?.relationship],
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
          {QUICK_ACTION_ROWS.map((row) => (
            <View key={row.map((action) => action.id).join('-')} style={styles.quickRow}>
              {row.map((action) => (
                <QuickActionTile
                  key={action.id}
                  action={action}
                  onPress={() => onQuickAction(action.id)}
                />
              ))}
            </View>
          ))}
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
  quickLabel: {
    ...typography.caption,
    color: colors.textPrimary,
    fontWeight: '600',
    textAlign: 'center',
  },
});
