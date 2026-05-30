import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { EmptyState } from './EmptyState';
import VisitTimelineStep from './VisitTimelineStep';
import { colors, layout, spacing, typography } from '../designSystem';

/**
 * Courier-style vertical visit progress timeline (Shopee / Lazada inspired).
 * Shared by `TimelineScreen` and `VisitTrackingScreen`.
 *
 * @param {object} props
 * @param {import('../mock/visitTrackingTimeline.mock').VisitTrackingTimelineEntry[]} props.steps
 * @param {string} [props.heading='Progress Timeline']
 * @param {string} [props.subtitle]
 */
export default function VisitProgressTimeline({
  steps,
  heading = 'Progress Timeline',
  subtitle = 'Track each milestone from registration through visit completion — like parcel delivery updates.',
}) {
  const completedCount = steps.filter((s) => s.stepState === 'completed').length;

  return (
    <>
      <View style={styles.header}>
        <Text style={styles.heading}>{heading}</Text>
        {steps.length > 0 ? (
          <Text style={styles.progressMeta}>
            {completedCount} of {steps.length} steps completed
          </Text>
        ) : null}
      </View>
      <Text style={styles.sub}>{subtitle}</Text>

      {steps.length === 0 ? (
        <View style={styles.empty}>
          <EmptyState
            title="No timeline events"
            message="Steps such as assignment, confirmation, check-in, and check-out will appear here when recorded."
          />
        </View>
      ) : (
        <View style={styles.timelinePanel}>
          {steps.map((item, index) => (
            <VisitTimelineStep
              key={item.id}
              stepState={item.stepState}
              title={item.title}
              description={item.description}
              occurredAt={item.occurredAt}
              officerNote={item.officerNote}
              isLast={index === steps.length - 1}
              carded
            />
          ))}
        </View>
      )}
    </>
  );
}

const styles = StyleSheet.create({
  header: {
    flexDirection: 'row',
    alignItems: 'baseline',
    justifyContent: 'space-between',
    gap: spacing[12],
    marginBottom: spacing[4],
  },
  heading: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
    flex: 1,
  },
  progressMeta: {
    ...typography.caption,
    color: colors.primaryTeal,
    fontWeight: '600',
  },
  sub: {
    ...typography.body,
    color: colors.textSecondary,
    marginBottom: spacing[16],
    lineHeight: 22,
  },
  timelinePanel: {
    backgroundColor: colors.card,
    borderRadius: layout.cardRadius,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing[16],
    paddingTop: spacing[20],
    paddingBottom: spacing[8],
  },
  empty: {
    minHeight: 320,
    justifyContent: 'center',
    paddingVertical: spacing[16],
  },
});
