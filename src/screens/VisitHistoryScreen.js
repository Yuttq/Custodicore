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
  StackScreenHeader,
  colors,
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
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
      <View style={[commonStyles.segmentedControl, styles.segmentedInList]}>
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
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Visitation History" navigation={navigation} />

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
  segmentedInList: {
    marginHorizontal: 0,
    marginBottom: spacing.sm,
  },
  loadingContainer: {
    flex: 1,
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.xl,
  },
  loadingLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  listContent: {
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.xl,
  },
  listEmptyContent: {
    flexGrow: 1,
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.xl,
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
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    gap: spacing.sm,
    backgroundColor: colors.card,
    minHeight: 48,
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
    gap: spacing.xs,
  },
  historyDate: {
    ...typography.cardTitle,
    fontSize: 16,
    color: colors.primaryNavy,
  },
  historyPdl: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
  historyStatus: {
    ...typography.metadata,
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
    paddingVertical: spacing.lg,
  },
  skeletonList: {
    backgroundColor: colors.card,
  },
  skeletonRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.sm,
    gap: spacing.sm,
  },
  skeletonCol: {
    flex: 1,
    gap: spacing.sm,
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
