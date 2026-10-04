import React from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { CustomButton, EmptyState, LoadingSpinner } from '../components';
import CompactVisitTimeline from '../components/CompactVisitTimeline';
import { StackScreenHeader, colors, commonStyles, spacing, typography } from '../designSystem';
import { useVisits } from '../context/VisitsContext';
import useVisitTimeline from '../hooks/useVisitTimeline';

/**
 * Visit tracking — compact progress timeline (v2.1), from the real visit timeline API.
 */
export default function VisitTrackingScreen({ navigation, route }) {
  const visitId = route?.params?.visitId ?? route?.params?.scheduleId;
  const paramStatus = route?.params?.visitStatus;

  const { getVisitById } = useVisits();
  const visit = getVisitById(visitId);
  const visitStatus = visit?.status ?? paramStatus;

  const { steps, loading, error, reload } = useVisitTimeline(visitId ? String(visitId) : null, visitStatus);

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Visit Progress" navigation={navigation} />

      <ScrollView
        contentContainerStyle={commonStyles.scrollContent}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        {loading ? (
          <LoadingSpinner message="Loading timeline…" compact />
        ) : error ? (
          <EmptyState title="Couldn't load timeline" message={error} emphasis="error">
            <View style={styles.retry}>
              <CustomButton title="Retry" onPress={reload} accessibilityLabel="Retry loading timeline" />
            </View>
          </EmptyState>
        ) : steps.length === 0 ? (
          <EmptyState
            title="No timeline events yet"
            message="Events will appear here as your visit progresses."
            iconName="git-commit-outline"
          />
        ) : (
          <>
            <Text style={styles.hint}>
              Tap a step to expand details. For visit information, open Visit Details.
            </Text>
            <CompactVisitTimeline steps={steps} />
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  hint: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.md,
  },
  retry: {
    marginTop: spacing.md,
    alignSelf: 'stretch',
  },
});
