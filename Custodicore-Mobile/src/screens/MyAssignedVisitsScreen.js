import { useFocusEffect } from '@react-navigation/native';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Alert,
  FlatList,
  Pressable,
  RefreshControl,
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
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { LoadingSpinner, EmptyState } from '../components';
import VisitationScheduleSection from '../components/VisitationScheduleSection';
import { useVisits } from '../context/VisitsContext';
import { useAuth } from '../hooks/useAuth';
import useScheduleAvailability from '../hooks/useScheduleAvailability';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import { canRespondToVisit, getMyVisitsTab } from '../mock/assignedVisits.mock';
import { visitTimeText } from '../utils/activeVisits';

const TABS = [
  { key: 'upcoming', label: 'Upcoming' },
  { key: 'pending', label: 'Pending' },
  { key: 'completed', label: 'Completed' },
  { key: 'schedule', label: 'Schedule' },
];

const LOADING_MS = 500;

/** Maps visit status to StatusChip keys for the BJMP visitation workflow. */
const VISIT_STATUS_CHIP = {
  pending_confirmation: 'pending_confirmation',
  assigned: 'assigned',
  scheduled: 'pending_confirmation',
  confirmed: 'confirmed',
  qr_ready: 'qr_ready',
  checked_in: 'checked_in',
  checked_out: 'completed',
  completed: 'completed',
  cancelled: 'cancelled',
  declined: 'declined',
  no_show: 'no_show',
  unable_to_attend: 'declined',
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
      message: 'Visit requests awaiting staff review and visits awaiting your confirmation will appear here.',
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
        <Text style={styles.timeText}>{visitTimeText(item)}</Text>
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
 * My Visits — upcoming, pending confirmation, and completed visits (v2.1 / BJMP),
 * plus the Schedule tab: visitation schedule, PDL / date / time-slot selection
 * (Phase 3). Open a tab directly with `navigate('Schedule', { tab: 'schedule' })`.
 */
export default function MyAssignedVisitsScreen({ navigation, route }) {
  const {
    visits,
    confirmVisit,
    submitVisitRequest,
    refreshVisits,
    error: visitsError,
  } = useVisits();
  const { isApprovedVisitor } = useAuth();
  // Availability is an approved-visitor API (visitor.approved middleware).
  const schedule = useScheduleAvailability({ enabled: isApprovedVisitor });
  const [activeTab, setActiveTab] = useState('upcoming');

  const requestedTab = route?.params?.tab;
  useEffect(() => {
    if (requestedTab && TABS.some((t) => t.key === requestedTab)) {
      setActiveTab(requestedTab);
      navigation.setParams({ tab: undefined });
    }
  }, [requestedTab, navigation]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loadError, setLoadError] = useState(null);

  const fetchVisits = useCallback(async (isRefresh = false) => {
    if (isRefresh) setRefreshing(true);
    else setLoading(true);
    setLoadError(null);
    try {
      await refreshVisits();
      if (!isRefresh) await new Promise((r) => setTimeout(r, LOADING_MS));
    } catch (e) {
      setLoadError(
        typeof e?.message === 'string' && e.message.trim()
          ? e.message
          : 'Could not load visits. Pull to refresh or try again.',
      );
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [refreshVisits]);

  // The tab stays mounted, so reload on every focus: the first one with the
  // full loading state, later ones quietly so staff decisions show up.
  const loadedOnce = useRef(false);
  useFocusEffect(
    useCallback(() => {
      if (!loadedOnce.current) {
        loadedOnce.current = true;
        fetchVisits(false);
        return;
      }
      refreshVisits().catch(() => {
        // error already stored in VisitsContext
      });
    }, [fetchVisits, refreshVisits]),
  );

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
      } catch (e) {
        const message =
          typeof e?.message === 'string' && e.message.trim()
            ? e.message
            : 'Could not confirm attendance. Please try again.';
        Alert.alert('Error', message);
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
    navigation.navigate('MainTabs', { screen: 'Dashboard' });
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
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right']}>
      <Text style={styles.screenTitle} accessibilityRole="header">
        My Visits
      </Text>

      <View style={commonStyles.segmentedControl}>
        {TABS.map((tab) => {
          const active = activeTab === tab.key;
          return (
            <Pressable
              key={tab.key}
              onPress={() => setActiveTab(tab.key)}
              style={[commonStyles.segmentedTab, active && commonStyles.segmentedTabActive]}
              accessibilityRole="tab"
              accessibilityState={{ selected: active }}
            >
              <Text
                style={[
                  commonStyles.segmentedTabLabel,
                  active && commonStyles.segmentedTabLabelActive,
                ]}
              >
                {tab.label}
              </Text>
            </Pressable>
          );
        })}
      </View>

      {activeTab === 'schedule' ? (
        <ScrollView
          contentContainerStyle={[styles.list, { paddingBottom: tabBarInset }]}
          refreshControl={
            <RefreshControl
              refreshing={schedule.loading && Boolean(schedule.relationship)}
              onRefresh={schedule.reload}
              tintColor={colors.primaryTeal}
              colors={[colors.primaryTeal]}
            />
          }
          showsVerticalScrollIndicator={false}
          keyboardShouldPersistTaps="handled"
        >
          <VisitationScheduleSection
            schedule={schedule}
            onSubmitRequest={submitVisitRequest}
            onViewPending={() => setActiveTab('pending')}
          />
        </ScrollView>
      ) : loading && !refreshing ? (
        <View style={styles.loadingWrap}>
          <LoadingSpinner message="Loading visits…" compact />
        </View>
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
          ListHeaderComponent={
            loadError || visitsError ? (
              <Text style={styles.loadErrorText}>{loadError || visitsError}</Text>
            ) : null
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
  screenTitle: {
    ...typography.pageTitle,
    color: colors.textPrimary,
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.sm,
    marginBottom: spacing.sm,
  },
  list: {
    paddingHorizontal: layout.screenPadding,
  },
  emptyList: {
    flexGrow: 1,
    paddingHorizontal: layout.screenPadding,
    justifyContent: 'center',
  },
  visitCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.cardGap,
  },
  cardPressable: {
    gap: spacing.xs,
  },
  dateText: {
    ...typography.cardTitle,
    color: colors.primaryNavy,
  },
  pdlName: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  timeText: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  chipRow: {
    alignSelf: 'flex-start',
    marginTop: spacing.xs,
  },
  pendingActions: {
    marginTop: spacing.sm,
    paddingTop: spacing.sm,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
    gap: spacing.sm,
  },
  pressed: { opacity: 0.92 },
  emptyWrap: {
    flexGrow: 1,
    justifyContent: 'center',
    paddingVertical: spacing.lg,
  },
  loadingWrap: {
    flex: 1,
    justifyContent: 'center',
    paddingHorizontal: layout.screenPadding,
  },
  loadErrorText: {
    ...typography.metadata,
    color: colors.danger,
    marginBottom: spacing.sm,
    paddingHorizontal: spacing.xs,
  },
});
