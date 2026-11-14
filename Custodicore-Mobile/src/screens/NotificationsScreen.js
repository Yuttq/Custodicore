import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Platform,
  Pressable,
  RefreshControl,
  SectionList,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { EmptyState, LoadingSpinner, NotificationRow } from '../components';
import { Button, colors, commonStyles, layout, spacing, typography } from '../designSystem';
import useAutoRefresh from '../hooks/useAutoRefresh';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import useVisitorVerification from '../hooks/useVisitorVerification';
import { useNotificationBadge } from '../context/NotificationBadgeContext';
import {
  fetchNotificationsList,
  markNotificationReadById,
} from '../repositories/notificationsRepository';
import { formatDate } from '../utils';
import {
  collapseDuplicateNotifications,
  extractNotificationReason,
  notificationIconName,
  resolveNotificationAction,
} from '../utils/notificationActions';
import {
  NOTIFICATION_FILTERS,
  filterNotificationsByCategory,
  groupNotificationsByDate,
  inferNotificationCategory,
  inferNotificationTone,
} from '../utils/notificationFilters';

/** Feed data comes from `notificationsRepository` (local mock or HTTP per `src/mock/devFlags.js`). */

const DESCRIPTION_PREVIEW_MAX = 72;

/**
 * @param {string} text
 */
function shortDescription(text) {
  const trimmed = String(text || '').trim();
  if (trimmed.length <= DESCRIPTION_PREVIEW_MAX) return trimmed;
  return `${trimmed.slice(0, DESCRIPTION_PREVIEW_MAX - 1).trim()}…`;
}

/**
 * @param {Record<string, unknown>} raw
 * @param {number} index
 */
function normalizeNotification(raw, index) {
  const id = String(raw.id ?? raw.notificationId ?? `n-${index}`).trim();
  const title =
    (typeof raw.title === 'string' && raw.title) ||
    (typeof raw.subject === 'string' && raw.subject) ||
    'Notification';
  const body =
    (typeof raw.body === 'string' && raw.body) ||
    (typeof raw.message === 'string' && raw.message) ||
    (typeof raw.content === 'string' && raw.content) ||
    '';
  const read =
    Boolean(raw.read) ||
    Boolean(raw.isRead) ||
    (raw.readAt != null && raw.readAt !== '') ||
    (raw.read_at != null && raw.read_at !== '');
  const createdAt =
    (typeof raw.createdAt === 'string' && raw.createdAt) ||
    (typeof raw.sentAt === 'string' && raw.sentAt) ||
    (typeof raw.timestamp === 'string' && raw.timestamp) ||
    null;
  const category = inferNotificationCategory(raw);
  const tone = inferNotificationTone(raw);
  // The backend notification_type (e.g. `document_rejected`), before it is
  // folded into a filter category — drives the reason, action and icon.
  const rawCategory = typeof raw.category === 'string' ? raw.category : null;
  // The record the notification is about (e.g. `visitor_pdl_relationships`
  // and its id), so the shortcut does not depend on the wording.
  const relatedType = typeof raw.relatedType === 'string' && raw.relatedType ? raw.relatedType : null;
  const relatedId = raw.relatedId != null && raw.relatedId !== '' ? String(raw.relatedId) : null;

  return { id, title, body, read, createdAt, category, tone, rawCategory, relatedType, relatedId };
}

/**
 * @param {unknown} data
 */
function normalizeList(data) {
  if (Array.isArray(data)) {
    return data.map((row, i) => normalizeNotification(row, i));
  }
  if (data && typeof data === 'object') {
    const o = /** @type {Record<string, unknown>} */ (data);
    if (Array.isArray(o.notifications)) {
      return o.notifications.map((row, i) => normalizeNotification(row, i));
    }
    if (Array.isArray(o.data)) {
      return o.data.map((row, i) => normalizeNotification(row, i));
    }
    if (Array.isArray(o.results)) {
      return o.results.map((row, i) => normalizeNotification(row, i));
    }
  }
  return [];
}

/**
 * @param {object} props
 * @param {string} props.activeFilter
 * @param {(filter: string) => void} props.onFilterChange
 */
function NotificationFilterBar({ activeFilter, onFilterChange }) {
  return (
    <View style={styles.filterBar}>
      {NOTIFICATION_FILTERS.map((filter) => {
        const isActive = activeFilter === filter.key;
        return (
          <Pressable
            key={filter.key}
            onPress={() => onFilterChange(filter.key)}
            style={({ pressed }) => [
              styles.filterChip,
              isActive && styles.filterChipActive,
              pressed && styles.filterChipPressed,
            ]}
            accessibilityRole="button"
            accessibilityState={{ selected: isActive }}
            accessibilityLabel={`Filter notifications by ${filter.label}`}
          >
            <Text
              style={[styles.filterChipLabel, isActive && styles.filterChipLabelActive]}
            >
              {filter.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

export default function NotificationsScreen({ navigation }) {
  const tabBarInset = useTabBarScrollInset();
  const { refreshUnreadCount } = useNotificationBadge();
  // Current document / PDL request state (reloads on focus), so a shortcut
  // only shows while there is still something to replace or request again.
  const { verification } = useVisitorVerification();
  const [items, setItems] = useState([]);
  const [activeFilter, setActiveFilter] = useState('all');
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState(null);

  const fetchNotifications = useCallback(async (isRefresh) => {
    if (isRefresh) setRefreshing(true);
    else setLoading(true);
    setError(null);
    try {
      const data = await fetchNotificationsList();
      setItems(collapseDuplicateNotifications(normalizeList(data)));
    } catch (e) {
      const message = e instanceof Error ? e.message : 'Could not load notifications.';
      setError(message);
      if (!isRefresh) setItems([]);
    } finally {
      setLoading(false);
      setRefreshing(false);
      refreshUnreadCount();
    }
  }, [refreshUnreadCount]);

  useEffect(() => {
    fetchNotifications(false);
  }, [fetchNotifications]);

  // While the screen stays open: no spinner, and a failure keeps the list.
  useAutoRefresh(async () => {
    const data = await fetchNotificationsList();
    setItems(collapseDuplicateNotifications(normalizeList(data)));
    setError(null);
    refreshUnreadCount();
  });

  const onRefresh = useCallback(() => {
    fetchNotifications(true);
  }, [fetchNotifications]);

  const sections = useMemo(() => {
    const filtered = filterNotificationsByCategory(items, activeFilter);
    return groupNotificationsByDate(filtered);
  }, [items, activeFilter]);

  const markRead = useCallback(
    async (item) => {
      if (item.read) return;

      setItems((prev) =>
        prev.map((n) => (n.id === item.id ? { ...n, read: true } : n)),
      );

      try {
        // A collapsed duplicate marks every copy read.
        await Promise.all((item.ids ?? [item.id]).map((id) => markNotificationReadById(id)));
        refreshUnreadCount();
      } catch (e) {
        setItems((prev) =>
          prev.map((n) => (n.id === item.id ? { ...n, read: false } : n)),
        );
        const message = e instanceof Error ? e.message : 'Could not mark as read.';
        Alert.alert('Something went wrong', message);
      }
    },
    [refreshUnreadCount],
  );

  const handleRowPress = useCallback(
    (item) => {
      if (!item.read) {
        markRead(item);
      }

      Alert.alert(
        item.title,
        item.body?.trim() || 'No additional details for this notification.',
        [{ text: 'OK' }],
      );
    },
    [markRead],
  );

  // Inline shortcut: opens an existing screen. It counts as opening the
  // notification, so it is marked read the same way a row tap does.
  const handleAction = useCallback(
    (item, action) => {
      if (!item.read) markRead(item);
      navigation.navigate(action.route, action.params);
    },
    [markRead, navigation],
  );

  const renderItem = useCallback(
    ({ item }) => {
      const dateLabel = item.createdAt ? formatDate(item.createdAt) : '';
      const preview = shortDescription(item.body);
      const reason = extractNotificationReason(item.rawCategory, item.body);
      const action = resolveNotificationAction(
        {
          category: item.rawCategory,
          body: item.body,
          relatedType: item.relatedType,
          relatedId: item.relatedId,
        },
        verification,
      );

      return (
        <NotificationRow
          title={item.title}
          description={preview}
          dateLabel={dateLabel}
          tone={item.tone}
          read={item.read}
          onPress={() => handleRowPress(item)}
          iconName={notificationIconName(item.rawCategory)}
          reason={reason}
          action={action ? { label: action.label, onPress: () => handleAction(item, action) } : null}
        />
      );
    },
    [handleRowPress, handleAction, verification],
  );

  const renderSectionHeader = useCallback(
    ({ section }) => (
      <Text
        style={[styles.sectionHeader, section.isFirst && styles.sectionHeaderFirst]}
        accessibilityRole="header"
      >
        {section.title}
      </Text>
    ),
    [],
  );

  const keyExtractor = useCallback((it) => it.id, []);

  const listHeader = useMemo(
    () => (
      <>
        <NotificationFilterBar activeFilter={activeFilter} onFilterChange={setActiveFilter} />
        {error && items.length > 0 ? (
          <View style={styles.inlineError}>
            <Text style={styles.inlineErrorText}>{error}</Text>
            <Button
              title="Retry"
              onPress={() => fetchNotifications(false)}
              accessibilityLabel="Retry after error"
            />
          </View>
        ) : null}
      </>
    ),
    [activeFilter, error, items.length, fetchNotifications],
  );

  const listEmpty = useCallback(() => {
    if (loading && !refreshing) {
      return (
        <View style={styles.emptyWrap}>
          <LoadingSpinner message="Loading notifications…" compact />
        </View>
      );
    }
    if (error) {
      return (
        <EmptyState
          title="Couldn't load notifications"
          message={error}
          emphasis="error"
          accessibilityRole="alert"
          style={styles.emptyWrap}
        >
          <View style={styles.emptyActions}>
            <Button
              title="Retry"
              onPress={() => fetchNotifications(false)}
              accessibilityLabel="Retry loading notifications"
            />
          </View>
        </EmptyState>
      );
    }
    if (items.length > 0 && activeFilter !== 'all') {
      const filterLabel =
        NOTIFICATION_FILTERS.find((filter) => filter.key === activeFilter)?.label ?? 'selected';
      return (
        <EmptyState
          title={`No ${filterLabel.toLowerCase()} notifications`}
          message="Nothing in this category right now. Try another filter or pull down to refresh."
          iconName="filter-outline"
          iconColor={colors.primaryTeal}
          style={styles.emptyWrap}
        />
      );
    }
    return (
      <EmptyState
        title="No Notifications"
        message="We'll notify you about approvals, schedules and updates."
        iconName="notifications-off-outline"
        iconColor={colors.primaryTeal}
        style={styles.emptyWrap}
      />
    );
  }, [loading, refreshing, error, fetchNotifications, items.length, activeFilter]);

  const listContentStyle = [
    sections.length === 0 ? styles.listEmptyContent : styles.listContent,
    { paddingBottom: tabBarInset },
  ];

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right']}>
      <Text style={styles.screenTitle} accessibilityRole="header">
        Notifications
      </Text>
      <SectionList
        sections={sections}
        keyExtractor={keyExtractor}
        renderItem={renderItem}
        renderSectionHeader={renderSectionHeader}
        stickySectionHeadersEnabled={false}
        initialNumToRender={24}
        maxToRenderPerBatch={20}
        windowSize={10}
        removeClippedSubviews={Platform.OS === 'android'}
        ItemSeparatorComponent={() => <View style={styles.separator} />}
        SectionSeparatorComponent={() => <View style={styles.sectionGap} />}
        contentContainerStyle={listContentStyle}
        ListEmptyComponent={listEmpty}
        ListHeaderComponent={listHeader}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={onRefresh}
            tintColor={colors.primaryTeal}
            colors={[colors.primaryTeal]}
          />
        }
        showsVerticalScrollIndicator={false}
      />
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
  listContent: {
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.xs,
  },
  listEmptyContent: {
    flexGrow: 1,
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.sm,
  },
  filterBar: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    marginBottom: spacing.sm + spacing.xs,
  },
  filterChip: {
    paddingHorizontal: spacing.md,
    paddingVertical: 6,
    borderRadius: 999,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  filterChipActive: {
    // White label on bright teal is 3.10:1; on dark teal 5.26:1.
    backgroundColor: colors.primaryTealDark,
    borderColor: colors.primaryTealDark,
  },
  filterChipPressed: {
    opacity: 0.92,
  },
  filterChipLabel: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textSecondary,
  },
  filterChipLabelActive: {
    color: colors.white,
  },
  sectionHeader: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginTop: spacing.md,
    marginBottom: spacing.sm,
    paddingHorizontal: spacing.xs,
  },
  sectionHeaderFirst: {
    marginTop: 0,
  },
  separator: {
    height: StyleSheet.hairlineWidth,
    backgroundColor: colors.border,
    marginLeft: spacing.sm + 32 + spacing.sm,
  },
  sectionGap: {
    height: spacing.md,
  },
  emptyWrap: {
    flex: 1,
    minHeight: 280,
  },
  emptyActions: {
    marginTop: spacing.sm,
    alignSelf: 'stretch',
    maxWidth: 280,
    width: '100%',
  },
  inlineError: {
    padding: spacing.sm,
    marginBottom: spacing.sm,
    backgroundColor: colors.card,
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    borderLeftWidth: 3,
    borderLeftColor: colors.danger,
    gap: spacing.sm,
  },
  inlineErrorText: {
    ...typography.metadata,
    color: colors.danger,
  },
});
