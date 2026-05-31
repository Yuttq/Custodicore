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
import { Button, colors, layout, spacing, typography } from '../designSystem';
import { EmptyState } from '../components';
import { fetchVisitationHistory } from '../repositories/visitHistoryRepository';

const TABS = [
  { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' },
];

const LOADING_MS = 520;

/** @typedef {import('../mock/visitationHistory.mock').MOCK_VISITATION_HISTORY[number]} HistoryRecord */

function HistorySkeleton() {
  return (
    <View style={styles.skeletonList}>
      {[1, 2, 3, 4].map((n) => (
        <View key={n} style={[styles.skeletonRow, n < 4 && styles.historyRowBorder]}>
          <View style={styles.skeletonCol}>
            <View style={styles.skeletonLine} />
            <View style={[styles.skeletonLine, styles.skeletonLineWide]} />
          </View>
          <View style={styles.skeletonStatus} />
        </View>
      ))}
    </View>
  );
}

/**
 * @param {HistoryRecord} item
 */
function getStatusLabel(item) {
  return item.status === 'completed' ? 'Completed' : 'Cancelled';
}

/**
 * @param {object} props
 * @param {HistoryRecord} props.item
 * @param {boolean} props.isLast
 * @param {() => void} props.onPress
 */
function HistoryCompactRow({ item, isLast, onPress }) {
  const statusLabel = getStatusLabel(item);
  const isCompleted = item.status === 'completed';

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [
        styles.historyRow,
        !isLast && styles.historyRowBorder,
        pressed && styles.historyRowPressed,
      ]}
      accessibilityRole="button"
      accessibilityLabel={`${statusLabel} visit on ${item.dateDisplay} with ${item.pdlName}`}
    >
      <View style={styles.rowContent}>
        <Text style={styles.historyDate}>{item.dateDisplay}</Text>
        <Text style={styles.historyPdl} numberOfLines={1}>
          {item.pdlName}
        </Text>
      </View>
      <Text
        style={[
          styles.historyStatus,
          isCompleted ? styles.statusCompleted : styles.statusCancelled,
        ]}
      >
        {statusLabel}
      </Text>
    </Pressable>
  );
}

/**
 * Visitation history — past completed and cancelled visits only (v2.1 / BJMP).
 */
export default function VisitHistoryScreen({ navigation }) {
  const [items, setItems] = useState(/** @type {HistoryRecord[]} */ ([]));
  const [activeTab, setActiveTab] = useState('completed');
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

  const filteredItems = useMemo(
    () => items.filter((r) => r.status === activeTab),
    [items, activeTab],
  );

  const openDetail = useCallback(
    (item) => {
      navigation.navigate('VisitHistoryDetail', { visitId: item.id });
    },
    [navigation],
  );

  const onReturnDashboard = useCallback(() => {
    navigation.navigate('MainTabs', { screen: 'Dashboard' });
  }, [navigation]);

  const listData = useMemo(
    () => (filteredItems.length > 0 ? [{ key: 'group', items: filteredItems }] : []),
    [filteredItems],
  );

  const renderItem = useCallback(
    ({ item: group }) => (
      <View style={styles.historyList}>
        {group.items.map((row, index) => (
          <HistoryCompactRow
            key={row.id}
            item={row}
            isLast={index === group.items.length - 1}
            onPress={() => openDetail(row)}
          />
        ))}
      </View>
    ),
    [openDetail],
  );

  const listHeader = useMemo(
    () => (
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
              <Text style={[styles.tabLabel, active && styles.tabLabelActive]}>{tab.label}</Text>
            </Pressable>
          );
        })}
      </View>
    ),
    [activeTab],
  );

  const listEmpty = useCallback(() => {
    if (error) {
      return (
        <EmptyState
          title="Unable to load history"
          message="Please check your connection and try again."
          iconName="cloud-offline-outline"
          iconColor={colors.danger}
          emphasis="error"
          style={styles.emptyState}
        >
          <Button title="Retry" onPress={() => loadHistory(false)} accessibilityLabel="Retry" />
        </EmptyState>
      );
    }

    if (items.length === 0) {
      return (
        <EmptyState
          title="No Visit History Yet"
          message="Your completed visits will appear here."
          iconName="time-outline"
          iconColor={colors.primaryTeal}
          style={styles.emptyState}
        >
          <Button
            title="Return to Home"
            variant="secondary"
            onPress={onReturnDashboard}
            accessibilityLabel="Return to home"
          />
        </EmptyState>
      );
    }

    const tabEmpty =
      activeTab === 'completed'
        ? {
            title: 'No Completed Visits',
            message: 'Finished visitation sessions will appear here.',
            icon: 'checkmark-done-outline',
          }
        : {
            title: 'No Cancelled Visits',
            message: 'Cancelled visitation records will appear here.',
            icon: 'close-circle-outline',
          };

    return (
      <EmptyState
        title={tabEmpty.title}
        message={tabEmpty.message}
        iconName={tabEmpty.icon}
        iconColor={colors.primaryTeal}
        style={styles.emptyState}
      />
    );
  }, [error, items.length, activeTab, loadHistory, onReturnDashboard]);

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
          <View style={styles.historyList}>
            <HistorySkeleton />
          </View>
        </View>
      ) : (
        <FlatList
          data={listData}
          keyExtractor={(it) => it.key}
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
    paddingBottom: spacing[10],
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
    marginBottom: spacing[10],
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
  tabBar: {
    flexDirection: 'row',
    marginBottom: spacing[10],
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
  historyList: {
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    overflow: 'hidden',
  },
  historyRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing[12],
    paddingVertical: spacing[8],
    gap: spacing[10],
    backgroundColor: colors.card,
    minHeight: 44,
  },
  historyRowBorder: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  historyRowPressed: {
    backgroundColor: colors.background,
  },
  rowContent: {
    flex: 1,
    minWidth: 0,
    gap: spacing[2],
  },
  historyDate: {
    ...typography.caption,
    fontWeight: '700',
    color: colors.primaryNavy,
  },
  historyPdl: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  historyStatus: {
    ...typography.caption,
    fontWeight: '600',
    flexShrink: 0,
  },
  statusCompleted: {
    color: colors.success,
  },
  statusCancelled: {
    color: colors.danger,
  },
  emptyState: {
    paddingVertical: spacing[24],
  },
  skeletonList: {
    backgroundColor: colors.card,
  },
  skeletonRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing[12],
    paddingVertical: spacing[10],
    gap: spacing[10],
  },
  skeletonCol: {
    flex: 1,
    gap: spacing[6],
  },
  skeletonLine: {
    height: 10,
    borderRadius: 5,
    backgroundColor: colors.border,
    width: '35%',
  },
  skeletonLineWide: {
    width: '70%',
  },
  skeletonStatus: {
    width: 56,
    height: 10,
    borderRadius: 5,
    backgroundColor: colors.border,
  },
});
