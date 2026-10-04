import React from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { CustomButton, EmptyState, LoadingSpinner } from '../components';
import CompactVisitTimeline from '../components/CompactVisitTimeline';
import {
  StackScreenHeader,
  colors,
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import useVisitTimeline from '../hooks/useVisitTimeline';

/**
 * Visit timeline — real events from GET /api/schedules/{id}/timeline.
 * The backend only returns timelines for the signed-in visitor’s own visits.
 */
export default function TimelineScreen({ navigation, route }) {
  const rawId = route?.params?.scheduleId ?? route?.params?.visitId;
  const scheduleId =
    rawId != null && String(rawId).trim() !== '' && rawId !== '—' ? String(rawId) : null;
  const visitStatus = route?.params?.visitStatus;

  const { steps, loading, error, reload } = useVisitTimeline(scheduleId, visitStatus);
  const missingVisit = !scheduleId;

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Visit Timeline" navigation={navigation} />

      {missingVisit ? (
        <View style={styles.centered}>
          <EmptyState
            title="No visit selected"
            message="Open this screen from a visit on your dashboard or in your history."
            iconName="git-commit-outline"
          />
        </View>
      ) : loading ? (
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
              <CustomButton title="Retry" onPress={reload} accessibilityLabel="Retry loading timeline" />
            </View>
          </EmptyState>
        </View>
      ) : steps.length === 0 ? (
        <View style={styles.centered}>
          <EmptyState
            title="No timeline events yet"
            message="Events will appear here as your visit progresses."
            iconName="git-commit-outline"
          />
        </View>
      ) : (
        <ScrollView
          contentContainerStyle={[commonStyles.scrollContent, styles.scrollGrow]}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          <Text style={styles.hint}>Tap a step to view date, time, officer, and remarks.</Text>
          <CompactVisitTimeline steps={steps} />
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  scrollGrow: {
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
