import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useMemo } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import CompactVisitTimeline from '../components/CompactVisitTimeline';
import { colors, layout, spacing, typography } from '../designSystem';
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

      <ScrollView
        contentContainerStyle={styles.scroll}
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
  safe: { flex: 1, backgroundColor: colors.background },
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
  },
  hint: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[12],
    lineHeight: 18,
  },
});
