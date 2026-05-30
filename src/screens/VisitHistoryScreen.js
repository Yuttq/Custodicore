import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
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
import { useVisits } from '../context/VisitsContext';
import { getVisitationHistorySummary } from '../mock/visitationHistory.mock';
import { fetchVisitationHistory } from '../repositories/visitHistoryRepository';

const TABS = [
  { key: 'all', label: 'All' },
  { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' },
];

const LOADING_MS = 520;

function HistorySkeleton() {
  return (
    <View style={styles.skeletonList}>
      {[1, 2, 3].map((n) => (
        <Card key={n} style={styles.skeletonCard}>
          <View style={styles.skeletonRow}>
            <View style={styles.skeletonIcon} />
            <View style={styles.skeletonBody}>
              <View style={styles.skeletonLine} />
              <View style={[styles.skeletonLine, styles.skeletonLineMid]} />
              <View style={[styles.skeletonLine, styles.skeletonLineShort]} />
            </View>
          </View>
        </Card>
      ))}
    </View>
  );
}

function SummaryCard({ total, completed, cancelled }) {
  return (
    <Card style={styles.summaryCard}>
      <View style={styles.summaryItem}>
        <Text style={styles.summaryValue}>{total}</Text>
        <Text style={styles.summaryLabel}>Total Visits</Text>
      </View>
      <View style={styles.summaryDivider} />
      <View style={styles.summaryItem}>
        <Text style={[styles.summaryValue, styles.summaryValueSuccess]}>{completed}</Text>
        <Text style={styles.summaryLabel}>Completed</Text>
      </View>
      <View style={styles.summaryDivider} />
      <View style={styles.summaryItem}>
        <Text style={[styles.summaryValue, styles.summaryValueDanger]}>{cancelled}</Text>
        <Text style={styles.summaryLabel}>Cancelled</Text>
      </View>
    </Card>
  );
}

function HistoryMetaRow({ icon, label, value }) {
  return (
    <View style={styles.metaRow}>
      <Ionicons name={icon} size={16} color={colors.textSecondary} />
      <Text style={styles.metaLabel}>{label}</Text>
      <Text style={styles.metaValue} numberOfLines={2}>
        {value}
      </Text>
    </View>
  );
}

function VisitationHistoryCard({ item, onViewDetails, showViewDetails }) {
  const isCompleted = item.status === 'completed';
  const statusIcon = isCompleted ? 'checkmark-circle' : 'close-circle';
  const statusIconColor = isCompleted ? colors.success : colors.danger;

  return (
    <Card style={styles.historyCard}>
      <View style={styles.cardHeader}>
        <View style={styles.statusIconWrap}>
          <Ionicons name={statusIcon} size={28} color={statusIconColor} />
        </View>
        <View style={styles.cardHeaderText}>
          <StatusChip status={item.status} />
          <Text style={styles.cardDate}>{item.dateDisplay}</Text>
          <Text style={styles.cardTime}>{item.timeLabel}</Text>
        </View>
      </View>

      <HistoryMetaRow icon="person-outline" label="PDL" value={item.pdlName} />

      {isCompleted ? (
        <>
          <HistoryMetaRow icon="business-outline" label="Facility" value={item.facility} />
          <HistoryMetaRow
            icon="document-text-outline"
            label="Reference"
            value={item.referenceNumber}
          />
          {showViewDetails ? (
            <View style={styles.viewDetailsWrap}>
              <Button
                title="View Details"
                variant="secondary"
                onPress={() => onViewDetails(item)}
                accessibilityLabel={`View details for visit with ${item.pdlName}`}
              />
            </View>
          ) : null}
        </>
      ) : (
        <HistoryMetaRow
          icon="information-circle-outline"
          label="Reason"
          value={item.cancellationReason ?? 'Visit cancelled'}
        />
      )}
    </Card>
  );
}

function HistoryEmpty({ onReturnDashboard }) {
  return (
    <View style={styles.emptyWrap}>
      <View style={styles.emptyIconCircle}>
        <Ionicons name="time-outline" size={48} color={colors.primaryNavy} />
      </View>
      <Text style={styles.emptyTitle}>No Visitation Records</Text>
      <Text style={styles.emptyMessage}>
        Your completed visitation records will appear here.
      </Text>
      <View style={styles.emptyButtonWrap}>
        <Button
          title="Return To Dashboard"
          onPress={onReturnDashboard}
          accessibilityLabel="Return to dashboard"
        />
      </View>
    </View>
  );
}

function HistoryError({ onRetry }) {
  return (
    <View style={styles.emptyWrap}>
      <View style={[styles.emptyIconCircle, styles.errorIconCircle]}>
        <Ionicons name="cloud-offline-outline" size={48} color={colors.danger} />
      </View>
      <Text style={styles.emptyTitle}>Unable to load visitation history.</Text>
      <Text style={styles.emptyMessage}>Please try again.</Text>
      <View style={styles.emptyButtonWrap}>
        <Button title="Retry" onPress={onRetry} accessibilityLabel="Retry loading history" />
      </View>
    </View>
  );
}

/**
 * Visitation history — past completed and cancelled visits (v2.1 / BJMP).
 */
export default function VisitHistoryScreen({ navigation }) {
  const { getVisitById } = useVisits();
  const [items, setItems] = useState([]);
  const [activeTab, setActiveTab] = useState('all');
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState(null);

  const loadHistory = useCallback(async (isRefresh = false) => {
    if (isRefresh) setRefreshing(true);
    else setLoading(true);
    setError(null);
    try {
      const data = await fetchVisitationHistory();
      if (!isRefresh) await new Promise((r) => setTimeout(r, LOADING_MS));
      setItems(data);
    } catch (e) {
      const message =
        e instanceof Error && e.message.trim()
          ? e.message
          : 'Unable to load visitation history.';
      setError(message);
      if (!isRefresh) setItems([]);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    loadHistory(false);
  }, [loadHistory]);

  const summary = useMemo(() => getVisitationHistorySummary(items), [items]);

  const filteredItems = useMemo(() => {
    if (activeTab === 'all') return items;
    return items.filter((r) => r.status === activeTab);
  }, [items, activeTab]);

  const onViewDetails = useCallback(
    (item) => {
      navigation.navigate('VisitDetails', { visitId: item.id });
    },
    [navigation],
  );

  const onReturnDashboard = useCallback(() => {
    navigation.navigate('MainTabs', { screen: 'Dashboard' });
  }, [navigation]);

  const renderItem = useCallback(
    ({ item }) => (
      <VisitationHistoryCard
        item={item}
        onViewDetails={onViewDetails}
        showViewDetails={item.status === 'completed' && Boolean(getVisitById(item.id))}
      />
    ),
    [onViewDetails, getVisitById],
  );

  const listHeader = useMemo(
    () => (
      <>
        <SummaryCard
          total={summary.total}
          completed={summary.completed}
          cancelled={summary.cancelled}
        />
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
      </>
    ),
    [activeTab, summary],
  );

  const listEmpty = useCallback(() => {
    if (error) return <HistoryError onRetry={() => loadHistory(false)} />;
    return <HistoryEmpty onReturnDashboard={onReturnDashboard} />;
  }, [error, loadHistory, onReturnDashboard]);

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <View style={styles.topBar}>
        <Pressable
          onPress={() => navigation.goBack()}
          style={styles.backButton}
          accessibilityRole="button"
          accessibilityLabel="Go back"
        >
          <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
        </Pressable>
        <Text style={styles.screenTitle}>Visitation History</Text>
        <View style={styles.backPlaceholder} />
      </View>

      {loading && !refreshing ? (
        <View style={styles.loadingContainer}>
          {listHeader}
          <Text style={styles.loadingLabel}>Loading history…</Text>
          <HistorySkeleton />
        </View>
      ) : (
        <FlatList
          data={filteredItems}
          keyExtractor={(it) => it.id}
          renderItem={renderItem}
          ListHeaderComponent={listHeader}
          ListEmptyComponent={listEmpty}
          contentContainerStyle={
            filteredItems.length === 0 ? styles.listEmptyContent : styles.listContent
          }
          refreshControl={
            <RefreshControl
              refreshing={refreshing}
              onRefresh={() => loadHistory(true)}
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
  topBar: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[20],
    paddingTop: spacing[8],
    paddingBottom: spacing[12],
  },
  backButton: {
    width: 44,
    height: 44,
    borderRadius: 22,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  backPlaceholder: { width: 44 },
  screenTitle: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
    flex: 1,
    textAlign: 'center',
  },
  loadingContainer: {
    flex: 1,
    paddingHorizontal: spacing[20],
    paddingBottom: spacing[24],
  },
  loadingLabel: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[12],
  },
  listContent: {
    paddingHorizontal: spacing[20],
    paddingBottom: spacing[24],
  },
  listEmptyContent: {
    flexGrow: 1,
    paddingHorizontal: spacing[20],
    paddingBottom: spacing[24],
  },
  summaryCard: {
    borderRadius: layout.cardRadius,
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: spacing[16],
    paddingVertical: spacing[16],
  },
  summaryItem: {
    flex: 1,
    alignItems: 'center',
  },
  summaryValue: {
    ...typography.screenTitle,
    fontSize: 24,
    color: colors.primaryNavy,
    marginBottom: spacing[4],
  },
  summaryValueSuccess: {
    color: colors.success,
  },
  summaryValueDanger: {
    color: colors.danger,
  },
  summaryLabel: {
    ...typography.caption,
    color: colors.textSecondary,
    textAlign: 'center',
  },
  summaryDivider: {
    width: StyleSheet.hairlineWidth,
    height: 40,
    backgroundColor: colors.border,
  },
  tabBar: {
    flexDirection: 'row',
    marginBottom: spacing[16],
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
  historyCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.cardGap,
    padding: spacing[16],
  },
  cardHeader: {
    flexDirection: 'row',
    marginBottom: spacing[12],
    gap: spacing[12],
  },
  statusIconWrap: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: colors.background,
    alignItems: 'center',
    justifyContent: 'center',
  },
  cardHeaderText: {
    flex: 1,
    gap: spacing[4],
  },
  cardDate: {
    ...typography.cardTitle,
    color: colors.textPrimary,
  },
  cardTime: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textSecondary,
  },
  metaRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing[8],
    marginBottom: spacing[8],
  },
  metaLabel: {
    ...typography.caption,
    color: colors.textSecondary,
    width: 56,
  },
  metaValue: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
    fontWeight: '500',
  },
  viewDetailsWrap: {
    marginTop: spacing[8],
  },
  skeletonList: {
    gap: spacing[12],
  },
  skeletonCard: {
    borderRadius: layout.cardRadius,
    padding: spacing[16],
  },
  skeletonRow: {
    flexDirection: 'row',
    gap: spacing[12],
  },
  skeletonIcon: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: colors.border,
  },
  skeletonBody: {
    flex: 1,
    gap: spacing[8],
  },
  skeletonLine: {
    height: 12,
    borderRadius: 6,
    backgroundColor: colors.border,
  },
  skeletonLineMid: {
    width: '75%',
  },
  skeletonLineShort: {
    width: '50%',
  },
  emptyWrap: {
    alignItems: 'center',
    paddingVertical: spacing[32],
    paddingHorizontal: spacing[12],
  },
  emptyIconCircle: {
    width: 96,
    height: 96,
    borderRadius: 48,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing[16],
  },
  errorIconCircle: {
    backgroundColor: 'rgba(239, 68, 68, 0.08)',
    borderColor: 'rgba(239, 68, 68, 0.2)',
  },
  emptyTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    textAlign: 'center',
    marginBottom: spacing[8],
  },
  emptyMessage: {
    ...typography.body,
    color: colors.textSecondary,
    textAlign: 'center',
    lineHeight: 22,
    marginBottom: spacing[16],
  },
  emptyButtonWrap: {
    alignSelf: 'stretch',
    maxWidth: 320,
    width: '100%',
  },
});
