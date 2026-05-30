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
import {
  Card,
  CustomButton,
  EmptyState,
  Header,
  LoadingSpinner,
  ScreenContainer,
} from '../components';
import { colors, layout, typography } from '../constants';
import { useNotificationBadge } from '../context/NotificationBadgeContext';
import {
  fetchNotificationsList,
  markNotificationReadById,
} from '../repositories/notificationsRepository';
import { formatDate } from '../utils';
import {
  NOTIFICATION_FILTERS,
  filterNotificationsByCategory,
  groupNotificationsByDate,
  inferNotificationCategory,
} from '../utils/notificationFilters';

/** Feed data comes from `notificationsRepository` (local mock or HTTP per `src/mock/devFlags.js`). */

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

  return { id, title, body, read, createdAt, category };
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
              style={[
                typography.captionStrong,
                styles.filterChipLabel,
                isActive && styles.filterChipLabelActive,
              ]}
            >
              {filter.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

export default function NotificationsScreen() {
  const { refreshUnreadCount } = useNotificationBadge();
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
      setItems(normalizeList(data));
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

  const onRefresh = useCallback(() => {
    fetchNotifications(true);
  }, [fetchNotifications]);

  const sections = useMemo(() => {
    const filtered = filterNotificationsByCategory(items, activeFilter);
    return groupNotificationsByDate(filtered);
  }, [items, activeFilter]);

  const handlePress = useCallback(async (item) => {
    if (item.read) return;

    setItems((prev) =>
      prev.map((n) => (n.id === item.id ? { ...n, read: true } : n)),
    );

    try {
      await markNotificationReadById(item.id);
      refreshUnreadCount();
    } catch (e) {
      setItems((prev) =>
        prev.map((n) => (n.id === item.id ? { ...n, read: false } : n)),
      );
      const message = e instanceof Error ? e.message : 'Could not mark as read.';
      Alert.alert('Something went wrong', message);
    }
  }, [refreshUnreadCount]);

  const renderItem = useCallback(
    ({ item }) => {
      const dateLabel = item.createdAt ? formatDate(item.createdAt) : '';

      return (
        <Pressable
          onPress={() => handlePress(item)}
          accessibilityRole="button"
          accessibilityState={{ selected: item.read }}
          accessibilityLabel={
            item.read
              ? `${item.title}. Read.${item.body ? ` ${item.body}` : ''}`
              : `${item.title}. Unread. Tap to mark as read.${item.body ? ` ${item.body}` : ''}`
          }
        >
          <Card style={[styles.card, !item.read && styles.cardUnread]}>
            <View style={styles.cardRow}>
              <View style={styles.textBlock}>
                <View style={styles.titleRow}>
                  <Text
                    style={[
                      typography.bodyStrong,
                      styles.title,
                      !item.read && styles.titleUnread,
                    ]}
                    numberOfLines={1}
                  >
                    {item.title}
                  </Text>
                  {!item.read ? (
                    <View style={styles.unreadDot} accessibilityLabel="Unread" />
                  ) : null}
                </View>
                {dateLabel ? (
                  <Text style={[typography.caption, styles.date]} numberOfLines={1}>
                    {dateLabel}
                  </Text>
                ) : null}
                {item.body ? (
                  <Text style={[typography.caption, styles.body]} numberOfLines={2}>
                    {item.body}
                  </Text>
                ) : null}
              </View>
            </View>
          </Card>
        </Pressable>
      );
    },
    [handlePress],
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
            <Text style={[typography.caption, styles.inlineErrorText]}>{error}</Text>
            <CustomButton
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
            <CustomButton
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
          style={styles.emptyWrap}
        />
      );
    }
    return (
      <EmptyState
        title="No Notifications"
        message="Alerts about visit assignments, confirmations, schedule changes, and facility updates will appear here when available."
        iconName="notifications-off-outline"
        style={styles.emptyWrap}
      />
    );
  }, [loading, refreshing, error, fetchNotifications, items.length, activeFilter]);

  return (
    <ScreenContainer backgroundColor="lightGray" tabScreen>
      <Header title="Notifications" />
      <SectionList
        sections={sections}
        keyExtractor={keyExtractor}
        renderItem={renderItem}
        renderSectionHeader={renderSectionHeader}
        stickySectionHeadersEnabled={false}
        initialNumToRender={12}
        maxToRenderPerBatch={12}
        windowSize={8}
        removeClippedSubviews={Platform.OS === 'android'}
        contentContainerStyle={
          sections.length === 0 ? styles.listEmptyContent : styles.listContent
        }
        ListEmptyComponent={listEmpty}
        ListHeaderComponent={listHeader}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={onRefresh}
            tintColor={colors.primary}
            colors={[colors.primary]}
          />
        }
      />
    </ScreenContainer>
  );
}

const styles = StyleSheet.create({
  listContent: {
    paddingHorizontal: layout.screenPadding,
    paddingTop: layout.spacing.sm,
    paddingBottom: layout.spacing.md,
  },
  listEmptyContent: {
    flexGrow: 1,
    paddingHorizontal: layout.screenPadding,
    paddingBottom: layout.spacing.md,
  },
  filterBar: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: layout.spacing.sm,
    marginBottom: layout.spacing.sm,
  },
  filterChip: {
    paddingHorizontal: layout.spacing.md,
    paddingVertical: layout.spacing.sm,
    borderRadius: 999,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border,
  },
  filterChipActive: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
  },
  filterChipPressed: {
    opacity: 0.92,
  },
  filterChipLabel: {
    color: colors.textSecondary,
  },
  filterChipLabelActive: {
    color: colors.white,
  },
  sectionHeader: {
    ...typography.captionStrong,
    color: colors.textSecondary,
    textTransform: 'uppercase',
    letterSpacing: 0.6,
    marginTop: layout.spacing.sm,
    marginBottom: layout.spacing.xs,
  },
  sectionHeaderFirst: {
    marginTop: 0,
  },
  card: {
    marginBottom: layout.spacing.sm,
    paddingVertical: layout.spacing.sm,
    paddingHorizontal: layout.spacing.md,
  },
  cardUnread: {
    borderLeftWidth: 3,
    borderLeftColor: colors.primary,
  },
  cardRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
  },
  textBlock: {
    flex: 1,
    minWidth: 0,
  },
  titleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: layout.spacing.sm,
  },
  unreadDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.primary,
    flexShrink: 0,
  },
  title: {
    color: colors.textPrimary,
    flex: 1,
  },
  titleUnread: {
    color: colors.primary,
  },
  date: {
    color: colors.textSecondary,
    marginTop: 2,
  },
  body: {
    color: colors.textMuted,
    marginTop: layout.spacing.xs,
    lineHeight: 17,
  },
  emptyWrap: {
    flex: 1,
    minHeight: 360,
  },
  emptyActions: {
    marginTop: layout.spacing.md,
    alignSelf: 'stretch',
    maxWidth: 280,
    width: '100%',
  },
  inlineError: {
    padding: layout.spacing.md,
    marginBottom: layout.spacing.sm,
    backgroundColor: colors.white,
    borderRadius: layout.borderRadius,
    borderWidth: 1,
    borderColor: colors.borderLight,
    borderLeftWidth: 4,
    borderLeftColor: colors.danger,
    gap: layout.spacing.sm,
  },
  inlineErrorText: {
    color: colors.danger,
  },
});
