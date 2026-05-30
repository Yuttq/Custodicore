import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { CustomButton, EmptyState, LoadingSpinner } from '../components';
import VisitTimelineStep from '../components/VisitTimelineStep';
import { Card, StatusChip, colors, layout, spacing, typography } from '../designSystem';
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

/**
 * @param {ReturnType<typeof normalizeEvent>[]} events
 */
function deriveDisplaySteps(events) {
  const firstPendingIndex = events.findIndex((event) => !event.occurredAt && !event.stepState);

  return events.map((event, index) => {
    if (event.stepState) {
      return event;
    }

    if (event.occurredAt) {
      return { ...event, stepState: 'completed' };
    }

    if (index === firstPendingIndex) {
      return { ...event, stepState: 'current' };
    }

    return { ...event, stepState: 'pending' };
  });
}

export default function TimelineScreen({ navigation, route }) {
  const scheduleId = route?.params?.scheduleId;
  const referenceNumber = route?.params?.referenceNumber;
  const pdlName = route?.params?.pdlName;
  const visitStatus = route?.params?.visitStatus;

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

  const displaySteps = useMemo(() => deriveDisplaySteps(sortedEvents), [sortedEvents]);

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
        <Text style={styles.screenTitle}>Visit Progress</Text>
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
          <Card style={styles.summaryCard}>
            <Text style={styles.summaryEyebrow}>VISIT TRACKING</Text>
            <Text style={styles.summaryRef}>
              Reference: {referenceNumber || scheduleId || '—'}
            </Text>
            {pdlName ? (
              <Text style={styles.summaryPdl} numberOfLines={2}>
                {pdlName}
              </Text>
            ) : null}
            {visitStatus ? (
              <View style={styles.chipWrap}>
                <StatusChip status={visitStatus} />
              </View>
            ) : null}
          </Card>

          <Text style={styles.timelineHeading}>Progress Timeline</Text>
          <Text style={styles.timelineSub}>
            Track each milestone from registration through visit completion — like parcel
            delivery updates.
          </Text>

          {displaySteps.length === 0 ? (
            <View style={styles.timelineEmpty}>
              <EmptyState
                title="No timeline events"
                message="Steps such as assignment, confirmation, check-in, and check-out will appear here when recorded."
              />
            </View>
          ) : (
            <View style={styles.timelineCard}>
              {displaySteps.map((item, index) => (
                <VisitTimelineStep
                  key={item.id}
                  stepState={item.stepState}
                  title={item.title}
                  description={item.description}
                  occurredAt={item.occurredAt}
                  officerNote={item.officerNote}
                  isLast={index === displaySteps.length - 1}
                  carded
                />
              ))}
            </View>
          )}
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
    paddingHorizontal: spacing[20],
    paddingVertical: spacing[8],
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
  },
  scroll: {
    paddingHorizontal: spacing[20],
    paddingBottom: spacing[32],
    flexGrow: 1,
  },
  summaryCard: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.sectionGap,
  },
  summaryEyebrow: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textSecondary,
    letterSpacing: 0.5,
    marginBottom: spacing[4],
  },
  summaryRef: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[8],
  },
  summaryPdl: {
    ...typography.cardTitle,
    color: colors.textPrimary,
  },
  chipWrap: {
    marginTop: spacing[12],
    alignSelf: 'flex-start',
  },
  timelineHeading: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
    marginBottom: spacing[4],
  },
  timelineSub: {
    ...typography.body,
    color: colors.textSecondary,
    marginBottom: spacing[16],
    lineHeight: 22,
  },
  timelineCard: {
    backgroundColor: colors.card,
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    padding: spacing[16],
  },
  timelineEmpty: {
    minHeight: 320,
    justifyContent: 'center',
    paddingVertical: spacing[16],
  },
  emptyActions: {
    marginTop: spacing[16],
    alignSelf: 'stretch',
    maxWidth: 280,
    width: '100%',
  },
  centered: {
    flex: 1,
    minHeight: 280,
    padding: spacing[20],
    alignItems: 'center',
    justifyContent: 'center',
  },
  muted: {
    ...typography.caption,
    color: colors.textSecondary,
    marginTop: spacing[8],
  },
});
