import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  FlatList,
  Platform,
  Pressable,
  RefreshControl,
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

  return { id, title, body, read, createdAt };
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

export default function NotificationsScreen() {
  const { refreshUnreadCount } = useNotificationBadge();
  const [items, setItems] = useState([]);
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
              ? `${item.title}. Read.`
              : `${item.title}. Unread. Tap to mark as read.`
          }
        >
          <Card style={styles.card}>
            <View style={styles.rowTop}>
              {!item.read ? (
                <View style={styles.unreadRail} accessibilityLabel="Unread">
                  <View style={styles.unreadDot} />
                </View>
              ) : (
                <View style={styles.readSpacer} />
              )}
              <View style={styles.textBlock}>
                <Text
                  style={[
                    typography.headline,
                    styles.title,
                    !item.read && styles.titleUnread,
                  ]}
                  numberOfLines={2}
                >
                  {item.title}
                </Text>
                {dateLabel ? (
                  <Text style={[typography.captionStrong, styles.date]}>{dateLabel}</Text>
                ) : null}
                {item.body ? (
                  <Text style={[typography.body, styles.body]} numberOfLines={4}>
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

  const keyExtractor = useCallback((it) => it.id, []);

  const listEmpty = useCallback(() => {
    if (loading && !refreshing) {
      return (
        <View style={styles.emptyWrap}>
          <LoadingSpinner />
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
    return (
      <EmptyState
        title="No notifications"
        message="Alerts about visit approvals, schedule changes, and reminders will show up here. Pull down to refresh."
        style={styles.emptyWrap}
      />
    );
  }, [loading, refreshing, error, fetchNotifications]);

  return (
    <ScreenContainer backgroundColor="lightGray">
      <Header title="Notifications" />
      <FlatList
        data={items}
        keyExtractor={keyExtractor}
        renderItem={renderItem}
        contentContainerStyle={
          items.length === 0 ? styles.listEmptyContent : styles.listContent
        }
        ListEmptyComponent={listEmpty}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={onRefresh}
            tintColor={colors.primary}
            colors={[colors.primary]}
          />
        }
        ListHeaderComponent={
          error && items.length > 0 ? (
            <View style={styles.inlineError}>
              <Text style={[typography.caption, styles.inlineErrorText]}>{error}</Text>
              <CustomButton
                title="Retry"
                onPress={() => fetchNotifications(false)}
                accessibilityLabel="Retry after error"
              />
            </View>
          ) : null
        }
      />
    </ScreenContainer>
  );
}

const styles = StyleSheet.create({
  listContent: {
    padding: layout.spacing.md,
    paddingBottom: layout.spacing.xl,
  },
  listEmptyContent: {
    flexGrow: 1,
  },
  card: {
    marginBottom: layout.spacing.sm,
  },
  rowTop: {
    flexDirection: 'row',
    alignItems: 'flex-start',
  },
  unreadRail: {
    width: 14,
    marginRight: layout.spacing.sm,
    alignItems: 'center',
    paddingTop: 4,
  },
  unreadDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
    backgroundColor: colors.primary,
    borderWidth: 2,
    borderColor: colors.white,
    ...Platform.select({
      ios: {
        shadowColor: colors.primary,
        shadowOffset: { width: 0, height: 0 },
        shadowOpacity: 0.35,
        shadowRadius: 3,
      },
      android: {},
    }),
  },
  readSpacer: {
    width: 14,
    marginRight: layout.spacing.sm,
  },
  textBlock: {
    flex: 1,
  },
  title: {
    color: colors.textPrimary,
  },
  titleUnread: {
    color: colors.primary,
  },
  date: {
    color: colors.textSecondary,
    marginTop: layout.spacing.xs,
    letterSpacing: 0.15,
  },
  body: {
    color: colors.textMuted,
    marginTop: layout.spacing.sm,
    lineHeight: 21,
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
    marginHorizontal: layout.spacing.md,
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
