import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import {
  CustomButton,
  EmptyState,
  Header,
  LoadingSpinner,
  ScreenContainer,
  TimelineItem,
} from '../components';
import { colors, layout, typography } from '../constants';
import * as api from '../services/api';

/**
 * @param {Record<string, unknown>} raw
 * @param {number} index
 */
function normalizeEvent(raw, index) {
  const id = String(raw.id ?? raw.eventId ?? `evt-${index}`);
  const statusRaw = raw.status ?? raw.type ?? raw.step ?? 'pending';
  const status = String(statusRaw)
    .toLowerCase()
    .replace(/-/g, '_')
    .replace(/\s+/g, '_');
  const title =
    (typeof raw.title === 'string' && raw.title) ||
    (typeof raw.label === 'string' && raw.label) ||
    humanizeStatus(status);
  const description =
    (typeof raw.description === 'string' && raw.description) ||
    (typeof raw.message === 'string' && raw.message) ||
    (typeof raw.note === 'string' && raw.note) ||
    '';
  const occurredAt =
    (typeof raw.occurredAt === 'string' && raw.occurredAt) ||
    (typeof raw.createdAt === 'string' && raw.createdAt) ||
    (typeof raw.timestamp === 'string' && raw.timestamp) ||
    (typeof raw.at === 'string' && raw.at) ||
    null;

  return { id, status, title, description, occurredAt };
}

function humanizeStatus(status) {
  return String(status || '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * @param {unknown} data
 */
function normalizeTimelineResponse(data) {
  if (Array.isArray(data)) {
    return data.map((row, i) => normalizeEvent(row, i));
  }
  if (data && typeof data === 'object') {
    const o = /** @type {Record<string, unknown>} */ (data);
    if (Array.isArray(o.events)) return o.events.map((row, i) => normalizeEvent(row, i));
    if (Array.isArray(o.timeline)) return o.timeline.map((row, i) => normalizeEvent(row, i));
    if (Array.isArray(o.data)) return o.data.map((row, i) => normalizeEvent(row, i));
  }
  return [];
}

export default function TimelineScreen({ navigation, route }) {
  const scheduleId = route?.params?.scheduleId;

  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const sortedEvents = useMemo(() => {
    return [...events].sort((a, b) => {
      const ta = a.occurredAt ? new Date(a.occurredAt).getTime() : 0;
      const tb = b.occurredAt ? new Date(b.occurredAt).getTime() : 0;
      return ta - tb;
    });
  }, [events]);

  const load = useCallback(async () => {
    if (!scheduleId || String(scheduleId).trim() === '' || scheduleId === '—') {
      setError('Missing schedule. Open this screen from a visit in your history.');
      setEvents([]);
      setLoading(false);
      return;
    }

    setLoading(true);
    setError(null);
    try {
      const data = await api.getTimeline(String(scheduleId));
      setEvents(normalizeTimelineResponse(data));
    } catch (e) {
      const message = e instanceof Error ? e.message : 'Could not load timeline.';
      setError(message);
      setEvents([]);
    } finally {
      setLoading(false);
    }
  }, [scheduleId]);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <ScreenContainer backgroundColor="lightGray">
      <Header
        title="Visit timeline"
        showBackButton
        onBackPress={() => navigation.goBack()}
      />
      {loading ? (
        <View style={styles.centered}>
          <LoadingSpinner />
          <Text style={[typography.caption, styles.muted]}>Loading timeline…</Text>
        </View>
      ) : error ? (
        <View style={styles.centered}>
          <EmptyState
            title="Couldn't load timeline"
            message={error}
            emphasis="error"
            accessibilityRole="alert"
          >
            <View style={styles.emptyActions}>
              <CustomButton title="Retry" onPress={load} accessibilityLabel="Retry loading timeline" />
            </View>
          </EmptyState>
        </View>
      ) : (
        <ScrollView
          contentContainerStyle={styles.scroll}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          <Text style={[typography.caption, styles.ref]}>
            Schedule: {String(scheduleId)}
          </Text>
          {sortedEvents.length === 0 ? (
            <View style={styles.timelineEmpty}>
              <EmptyState
                title="No timeline events"
                message="Steps such as assignment, confirmation, check-in, and check-out will appear here when recorded."
              />
            </View>
          ) : (
            sortedEvents.map((item, index) => (
              <TimelineItem
                key={item.id}
                status={item.status}
                title={item.title}
                description={item.description || undefined}
                occurredAt={item.occurredAt}
                isLast={index === sortedEvents.length - 1}
              />
            ))
          )}
        </ScrollView>
      )}
    </ScreenContainer>
  );
}

const styles = StyleSheet.create({
  scroll: {
    padding: layout.spacing.md,
    paddingBottom: layout.spacing.xl,
    flexGrow: 1,
  },
  ref: {
    color: colors.textSecondary,
    marginBottom: layout.spacing.md,
  },
  timelineEmpty: {
    minHeight: 320,
    justifyContent: 'center',
    paddingVertical: layout.spacing.lg,
  },
  emptyActions: {
    marginTop: layout.spacing.md,
    alignSelf: 'stretch',
    maxWidth: 280,
    width: '100%',
  },
  centered: {
    flex: 1,
    minHeight: 280,
    padding: layout.spacing.lg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  muted: {
    color: colors.textSecondary,
    marginTop: layout.spacing.sm,
  },
});
