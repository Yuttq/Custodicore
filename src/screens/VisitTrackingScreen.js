import React, { useMemo } from 'react';
import { ScrollView, StyleSheet, Text } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import CompactVisitTimeline from '../components/CompactVisitTimeline';
import { StackScreenHeader, colors, commonStyles, spacing, typography } from '../designSystem';
import { useVisits } from '../context/VisitsContext';
import { getCompactVisitSteps } from '../utils/visitProgressSnapshot';

/**
 * Visit tracking — compact progress timeline (v2.1).
 */
export default function VisitTrackingScreen({ navigation, route }) {
  const visitId = route?.params?.visitId ?? route?.params?.scheduleId;
  const paramStatus = route?.params?.visitStatus;

  const { getVisitById } = useVisits();
  const visit = getVisitById(visitId);
  const visitStatus = visit?.status ?? paramStatus;

  const steps = useMemo(
    () => getCompactVisitSteps(String(visitId || ''), visitStatus),
    [visitId, visitStatus],
  );

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Visit Progress" navigation={navigation} />

      <ScrollView
        contentContainerStyle={commonStyles.scrollContent}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        <Text style={styles.hint}>
          Tap a step to expand details. For visit information, open Visit Details.
        </Text>
        <CompactVisitTimeline steps={steps} />
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
});
