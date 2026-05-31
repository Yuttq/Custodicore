import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  FlatList,
  Pressable,
  RefreshControl,
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
import { LoadingSpinner, EmptyState } from '../components';
import { useVisits } from '../context/VisitsContext';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import { canRespondToVisit, getMyVisitsTab } from '../mock/assignedVisits.mock';

const TABS = [
  { key: 'upcoming', label: 'Upcoming' },
  { key: 'pending', label: 'Pending' },
  { key: 'completed', label: 'Completed' },
];

const LOADING_MS = 500;

/** Maps visit status to StatusChip keys for the BJMP visitation workflow. */
const VISIT_STATUS_CHIP = {
  pending_confirmation: 'pending_confirmation',
  scheduled: 'pending_confirmation',
  confirmed: 'confirmed',
  qr_ready: 'qr_ready',
  checked_in: 'checked_in',
  checked_out: 'completed',
  completed: 'completed',
  cancelled: 'cancelled',
  unable_to_attend: 'unable_to_attend',
};

/**
 * @param {string} status
 */
function resolveVisitStatusChip(status) {
  return VISIT_STATUS_CHIP[status] ?? 'pending';
}

function VisitListEmpty({ tab, hasAnyVisits, onReturnHome }) {
  if (!hasAnyVisits && tab === 'upcoming') {
    return (
      <EmptyState
        title="No Upcoming Visits"
        message="You currently have no assigned visits."
        iconName="calendar-outline"
        iconColor={colors.primaryTeal}
        style={styles.emptyWrap}
      >
        <Button
          title="Return to Home"
          onPress={onReturnHome}
          accessibilityLabel="Return to home"
        />
      </EmptyState>
    );
  }

  const config = {
    upcoming: {
      title: 'No Upcoming Visits',
      message: 'Confirmed visits ready for your schedule will appear here.',
      icon: 'calendar-outline',
      actionTitle: 'Return to Home',
    },
    pending: {
      title: 'No Pending Visits',
      message: 'Visits awaiting your attendance confirmation will appear here.',
      icon: 'time-outline',
      actionTitle: null,
    },
    completed: {
      title: 'No Completed Visits',
      message: 'Your visit history will appear here after sessions are completed.',
      icon: 'checkmark-done-outline',
      actionTitle: null,
    },
  };
  const state = config[tab] ?? config.upcoming;

  return (
    <EmptyState
      title={state.title}
      message={state.message}
      iconName={state.icon}
      iconColor={colors.primaryTeal}
      style={styles.emptyWrap}
    >
      {state.actionTitle && onReturnHome ? (
        <Button
          title={state.actionTitle}
          onPress={onReturnHome}
          accessibilityLabel={state.actionTitle}
        />
      ) : null}
    </EmptyState>
  );
}

/**
 * @param {object} props
 * @param {import('../context/VisitsContext').Visit} props.item
 * @param {() => void} props.onPress
 * @param {boolean} props.showPendingActions
 * @param {(id: string) => void} props.onConfirmPress
 * @param {(id: string) => void} props.onUnablePress
 */
function VisitCard({ item, onPress, showPendingActions, onConfirmPress, onUnablePress }) {
  const chipStatus = resolveVisitStatusChip(item.status);

  return (
    <Card style={styles.visitCard}>
      <Pressable
        onPress={onPress}
        style={({ pressed }) => [styles.cardPressable, pressed && styles.pressed]}
        accessibilityRole="button"
        accessibilityLabel={`Visit with ${item.pdlName} on ${item.dateDisplay}`}
      >
        <Text style={styles.dateText}>{item.dateDisplay}</Text>
        <Text style={styles.pdlName} numberOfLines={2}>
          {item.pdlName}
        </Text>
        <Text style={styles.timeText}>{item.timeLabel}</Text>
        <View style={styles.chipRow}>
          <StatusChip status={chipStatus} />
        </View>
      </Pressable>

      {showPendingActions ? (
        <View style={styles.pendingActions}>
          <Button
            title="Confirm Attendance"
            onPress={() => onConfirmPress(item.id)}
            accessibilityLabel={`Confirm attendance for ${item.pdlName}`}
          />
          <Button
            title="Unable To Attend"
            variant="secondary"
            onPress={() => onUnablePress(item.id)}
            accessibilityLabel={`Unable to attend visit with ${item.pdlName}`}
          />
        </View>
      ) : null}
    </Card>
  );
}

/**
 * My Visits — upcoming, pending confirmation, and completed visits (v2.1 / BJMP).
 */
export default function MyAssignedVisitsScreen({ navigation }) {
  const { visits, confirmVisit, refreshVisits } = useVisits();
  const [activeTab, setActiveTab] = useState('upcoming');
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const fetchVisits = useCallback(async (isRefresh = false) => {
    if (isRefresh) setRefreshing(true);
    else setLoading(true);
    try {
      await refreshVisits();
      if (!isRefresh) await new Promise((r) => setTimeout(r, LOADING_MS));
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [refreshVisits]);

  useEffect(() => {
    fetchVisits(false);
  }, [fetchVisits]);

  const filteredVisits = useMemo(() => {
    const filtered = visits.filter((v) => getMyVisitsTab(v.status) === activeTab);
    const sorted = [...filtered].sort(
      (a, b) => new Date(a.scheduledAt) - new Date(b.scheduledAt),
    );
    if (activeTab === 'completed') {
      sorted.reverse();
    }
    return sorted;
  }, [visits, activeTab]);

  const tabBarInset = useTabBarScrollInset();

  const onViewDetails = useCallback(
    (item) => {
      navigation.navigate('VisitDetails', { visitId: item.id });
    },
    [navigation],
  );

  const handleConfirm = useCallback(
    async (id) => {
      try {
        await confirmVisit(id);
        Alert.alert(
          'Attendance confirmed',
          'Your attendance has been recorded. Please arrive on time with valid ID.',
        );
      } catch {
        Alert.alert('Error', 'Could not confirm attendance. Please try again.');
      }
    },
    [confirmVisit],
  );

  const onConfirmPress = useCallback(
    (id) => {
      Alert.alert(
        'Confirm Attendance?',
        'Are you sure you will attend this scheduled visit?',
        [
          { text: 'Cancel', style: 'cancel' },
          { text: 'Confirm', onPress: () => handleConfirm(id) },
        ],
      );
    },
    [handleConfirm],
  );

  const onUnablePress = useCallback(
    (visitId) => {
      Alert.alert('Unable To Attend?', 'Are you sure?', [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Continue',
          onPress: () => navigation.navigate('UnableToAttend', { visitId }),
        },
      ]);
    },
    [navigation],
  );

  const onReturnHome = useCallback(() => {
    navigation.navigate('Dashboard');
  }, [navigation]);

  const renderItem = useCallback(
    ({ item }) => (
      <VisitCard
        item={item}
        onPress={() => onViewDetails(item)}
        showPendingActions={activeTab === 'pending' && canRespondToVisit(item.status)}
        onConfirmPress={onConfirmPress}
        onUnablePress={onUnablePress}
      />
    ),
    [activeTab, onViewDetails, onConfirmPress, onUnablePress],
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <Text style={styles.screenTitle}>My Visits</Text>

      <View style={styles.tabBar}>
        {TABS.map((tab) => {
          const active = activeTab === tab.key;
          return (
            <Pressable
              key={tab.key}
              onPress={() => setActiveTab(tab.key)}
              style={[styles.tab, active && styles.tabActive]}
              accessibilityRole="tab"
              accessibilityState={{ selected: active }}
            >
              <Text style={[styles.tabLabel, active && styles.tabLabelActive]}>
                {tab.label}
              </Text>
            </Pressable>
          );
        })}
      </View>

      {loading ? (
        <LoadingSpinner message="Loading visits…" compact />
      ) : (
        <FlatList
          data={filteredVisits}
          keyExtractor={(it) => it.id}
          renderItem={renderItem}
          contentContainerStyle={
            filteredVisits.length === 0
              ? [styles.emptyList, { paddingBottom: tabBarInset }]
              : [styles.list, { paddingBottom: tabBarInset }]
          }
          ListEmptyComponent={
            <VisitListEmpty
              tab={activeTab}
              hasAnyVisits={visits.length > 0}
              onReturnHome={onReturnHome}
            />
          }
          refreshControl={
            <RefreshControl
              refreshing={refreshing}
              onRefresh={() => fetchVisits(true)}
              tintColor={colors.primaryTeal}
              colors={[colors.primaryTeal]}
            />
          }
          showsVerticalScrollIndicator={false}
        />
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  screenTitle: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
    paddingHorizontal: spacing[20],
    paddingTop: spacing[8],
    paddingBottom: spacing[12],
  },
  tabBar: {
    flexDirection: 'row',
    marginHorizontal: spacing[20],
    marginBottom: spacing[12],
    backgroundColor: colors.card,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.border,
    padding: spacing[4],
  },
  tab: {
    flex: 1,
    paddingVertical: spacing[8],
    alignItems: 'center',
    borderRadius: 8,
  },
  tabActive: {
    backgroundColor: colors.primaryTeal,
  },
  tabLabel: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textSecondary,
  },
  tabLabelActive: {
    color: colors.white,
  },
  list: {
    paddingHorizontal: spacing[20],
  },
  emptyList: {
    flexGrow: 1,
    paddingHorizontal: spacing[20],
    justifyContent: 'center',
  },
  visitCard: {
    borderRadius: layout.cardRadius,
    padding: spacing[10],
    marginBottom: spacing[8],
  },
  cardPressable: {
    gap: spacing[4],
  },
  dateText: {
    ...typography.body,
    fontWeight: '700',
    color: colors.primaryNavy,
  },
  pdlName: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  timeText: {
    ...typography.caption,
    color: colors.textSecondary,
  },
  chipRow: {
    alignSelf: 'flex-start',
    marginTop: spacing[4],
  },
  pendingActions: {
    marginTop: spacing[10],
    paddingTop: spacing[10],
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
    gap: spacing[8],
  },
  pressed: { opacity: 0.92 },
  emptyWrap: {
    flexGrow: 1,
    justifyContent: 'center',
    paddingVertical: spacing[24],
  },
});
