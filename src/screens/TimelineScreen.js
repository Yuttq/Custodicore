import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { CustomButton, EmptyState, LoadingSpinner } from '../components';
import CompactVisitTimeline from '../components/CompactVisitTimeline';
import { colors, layout, spacing, typography } from '../designSystem';
import { fetchVisitTimeline } from '../repositories/timelineRepository';
import {
  buildCompactVisitStepsFromTimeline,
  getCompactVisitSteps,
} from '../utils/visitProgressSnapshot';

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
  const officerNote =
    (typeof raw.officerNote === 'string' && raw.officerNote) ||
    (typeof raw.staffNote === 'string' && raw.staffNote) ||
    (typeof raw.officer_note === 'string' && raw.officer_note) ||
    null;
  const stepStateRaw = raw.stepState ?? raw.step_state;
  const stepState =
    stepStateRaw === 'completed' || stepStateRaw === 'current' || stepStateRaw === 'pending'
      ? stepStateRaw
      : null;

  return { id, status, title, description, occurredAt, officerNote, stepState };
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

/** @param {unknown[]} events */
function isMockTimelineFormat(events) {
  return (
    events.length > 0 &&
    events.every(
      (event) =>
        event &&
        typeof event === 'object' &&
        'stepState' in event &&
        typeof /** @type {Record<string, unknown>} */ (event).stepState === 'string',
    )
  );
}

export default function TimelineScreen({ navigation, route }) {
  const scheduleId = route?.params?.scheduleId;
  const visitStatus = route?.params?.visitStatus;

  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const compactSteps = useMemo(() => {
    if (isMockTimelineFormat(events)) {
      return buildCompactVisitStepsFromTimeline(events);
    }
    if (events.length > 0) {
      return buildCompactVisitStepsFromTimeline(
        events.map((e, i) => ({
          id: e.id,
          stepState: e.stepState ?? 'pending',
          title: e.title,
          description: e.description,
          occurredAt: e.occurredAt,
          officerNote: e.officerNote,
        })),
      );
    }
    return getCompactVisitSteps(String(scheduleId || ''), visitStatus);
  }, [events, scheduleId, visitStatus]);

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
      const result = await fetchVisitTimeline(String(scheduleId), visitStatus);
      if (result.source === 'mock') {
        setEvents(result.steps);
      } else {
        setEvents(normalizeTimelineResponse(result.data));
      }
    } catch (e) {
      const message = e instanceof Error ? e.message : 'Could not load timeline.';
      setError(message);
      setEvents([]);
    } finally {
      setLoading(false);
    }
  }, [scheduleId, visitStatus]);

  useEffect(() => {
    load();
  }, [load]);

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
        <Text style={styles.screenTitle}>Visit Timeline</Text>
        <View style={styles.backPlaceholder} />
      </View>

      {loading ? (
        <View style={styles.centered}>
          <LoadingSpinner message="Loading timeline…" compact />
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
          <Text style={styles.hint}>Tap a step to view date, time, officer, and remarks.</Text>
          <CompactVisitTimeline steps={compactSteps} />
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: {
    flex: 1,
    backgroundColor: colors.background,
  },
  topBar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: layout.screenPadding,
    paddingVertical: spacing.sm,
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
    ...typography.pageTitle,
    fontSize: 20,
    lineHeight: 24,
    color: colors.textPrimary,
  },
  scroll: {
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.xl,
    flexGrow: 1,
  },
  hint: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.md,
  },
  emptyActions: {
    marginTop: spacing.md,
    alignSelf: 'stretch',
    maxWidth: 280,
    width: '100%',
  },
  centered: {
    flex: 1,
    minHeight: 280,
    padding: layout.screenPadding,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
