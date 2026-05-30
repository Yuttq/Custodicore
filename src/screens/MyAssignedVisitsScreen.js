import Ionicons from '@expo/vector-icons/Ionicons';
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
import { getVisitListTab } from '../mock/assignedVisits.mock';

const TABS = [
  { key: 'assigned', label: 'Assigned' },
  { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' },
];

const LOADING_MS = 500;

function VisitListEmpty({ tab, onReturnHome }) {
  const config = {
    assigned: {
      title: 'No Assigned Visits',
      message:
        'Visit schedules are assigned by facility officers. When a visit is assigned to you, it will appear here.',
      icon: 'calendar-outline',
      actionTitle: 'Return to Home',
    },
    completed: {
      title: 'No Completed Visits',
      message: 'Visits you have completed will be listed here for your records.',
      icon: 'checkmark-done-outline',
      actionTitle: null,
    },
    cancelled: {
      title: 'No Cancelled Visits',
      message: 'Cancelled or missed visits will appear here when recorded by the facility.',
      icon: 'close-circle-outline',
      actionTitle: null,
    },
  };
  const state = config[tab] ?? config.assigned;

  return (
    <EmptyState
      title={state.title}
      message={`${state.message} Pull down to refresh.`}
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

function VisitCard({ item, onViewDetails, onConfirm, onUnableToAttend }) {
  const isPendingConfirmation = item.status === 'pending_confirmation';

  return (
    <Card style={styles.visitCard}>
      <View style={styles.cardRow}>
        <View style={styles.dateBlock}>
          <Text style={styles.dateText}>{item.dateDisplay}</Text>
        </View>
        <View style={styles.cardBody}>
          <View style={styles.cardTopRow}>
            <Text style={styles.timeText}>{item.timeLabel}</Text>
            <StatusChip status={item.status} />
          </View>
          <Text style={styles.pdlName}>{item.pdlName}</Text>
          <Text style={styles.facility}>{item.facility}</Text>
        </View>
      </View>

      <Pressable
        onPress={() => onViewDetails(item)}
        style={({ pressed }) => [styles.detailsLink, pressed && styles.pressed]}
        accessibilityRole="button"
        accessibilityLabel={`View details for visit with ${item.pdlName}`}
      >
        <Text style={styles.detailsLinkText}>View Details</Text>
        <Ionicons name="chevron-forward" size={16} color={colors.primaryTeal} />
      </Pressable>

      {isPendingConfirmation ? (
        <View style={styles.pendingActions}>
          <View style={styles.actionBtn}>
            <Button
              title="Confirm Attendance"
              onPress={() => onConfirm(item.id)}
              accessibilityLabel={`Confirm attendance for ${item.pdlName}`}
            />
          </View>
          <Pressable
            onPress={() => onUnableToAttend(item.id)}
            style={({ pressed }) => [
              styles.unableBtn,
              pressed && styles.pressed,
            ]}
            accessibilityRole="button"
            accessibilityLabel={`Unable to attend visit with ${item.pdlName}`}
          >
            <Text style={styles.unableBtnText}>Unable To Attend</Text>
          </Pressable>
        </View>
      ) : null}
    </Card>
  );
}

/**
 * My Assigned Visits — system-assigned schedules only (v2.1 / BJMP).
 */
export default function MyAssignedVisitsScreen({ navigation }) {
  const { visits, confirmVisit, refreshVisits } = useVisits();
  const [activeTab, setActiveTab] = useState('assigned');
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

  const filteredVisits = useMemo(
    () => visits.filter((v) => getVisitListTab(v.status) === activeTab),
    [visits, activeTab],
  );
  const tabBarInset = useTabBarScrollInset();

  const onViewDetails = useCallback(
    (item) => {
      navigation.navigate('VisitDetails', { visitId: item.id });
    },
    [navigation],
  );

  const onConfirm = useCallback(
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

  const onUnableToAttend = useCallback(
    (id) => {
      navigation.navigate('UnableToAttend', { visitId: id });
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
        onViewDetails={onViewDetails}
        onConfirm={onConfirm}
        onUnableToAttend={onUnableToAttend}
      />
    ),
    [onViewDetails, onConfirm, onUnableToAttend],
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <Text style={styles.screenTitle}>My Assigned Visits</Text>

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
          ListEmptyComponent={<VisitListEmpty tab={activeTab} onReturnHome={onReturnHome} />}
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
    padding: spacing[12],
    marginBottom: layout.cardGap,
  },
  cardRow: {
    flexDirection: 'row',
  },
  dateBlock: {
    width: 52,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.background,
    borderRadius: 10,
    paddingVertical: spacing[8],
    marginRight: spacing[12],
  },
  dateText: {
    ...typography.statusLabel,
    fontWeight: '700',
    color: colors.primaryNavy,
    textAlign: 'center',
  },
  cardBody: {
    flex: 1,
  },
  cardTopRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: spacing[8],
    marginBottom: spacing[4],
  },
  timeText: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  pdlName: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing[4],
  },
  facility: {
    ...typography.caption,
    color: colors.textSecondary,
  },
  detailsLink: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: spacing[12],
    paddingVertical: spacing[8],
  },
  detailsLinkText: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.primaryTeal,
    marginRight: spacing[4],
  },
  pendingActions: {
    marginTop: spacing[8],
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
    paddingTop: spacing[12],
  },
  actionBtn: {
    marginBottom: spacing[8],
  },
  unableBtn: {
    height: 44,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.danger,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.white,
  },
  unableBtnText: {
    ...typography.body,
    fontWeight: '600',
    color: colors.danger,
  },
  pressed: { opacity: 0.9 },
  emptyWrap: {
    flexGrow: 1,
    justifyContent: 'center',
    paddingVertical: spacing[24],
  },
});
