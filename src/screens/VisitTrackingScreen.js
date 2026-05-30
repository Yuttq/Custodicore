import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useMemo } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import VisitProgressTimeline from '../components/VisitProgressTimeline';
import { Card, StatusChip, colors, layout, spacing, typography } from '../designSystem';
import { useVisits } from '../context/VisitsContext';
import { getMockVisitTrackingTimeline } from '../mock/visitTrackingTimeline.mock';

/**
 * Visit tracking — full BJMP 11-step vertical timeline (v2.1).
 * Shares courier-style layout with `TimelineScreen` via `VisitProgressTimeline`.
 */
export default function VisitTrackingScreen({ navigation, route }) {
  const visitId = route?.params?.visitId ?? route?.params?.scheduleId;
  const paramPdl = route?.params?.pdlName;
  const paramStatus = route?.params?.visitStatus;

  const { getVisitById } = useVisits();
  const visit = getVisitById(visitId);

  const pdlName = visit?.pdlName ?? paramPdl;
  const visitStatus = visit?.status ?? paramStatus;
  const referenceNumber = visit?.referenceNumber ?? visitId;

  const steps = useMemo(
    () => getMockVisitTrackingTimeline(String(visitId || ''), visitStatus),
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
        <Text style={styles.screenTitle}>Visit Tracking</Text>
        <View style={styles.backPlaceholder} />
      </View>

      <ScrollView
        contentContainerStyle={styles.scroll}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        <Card style={styles.summaryCard}>
          <Text style={styles.summaryEyebrow}>VISIT TRACKING</Text>
          <Text style={styles.summaryRef}>
            Reference: {referenceNumber || '—'}
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

        <VisitProgressTimeline
          steps={steps}
          subtitle="Chronological status from registration through visit completion."
        />
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
    paddingHorizontal: layout.screenPadding,
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
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing[32],
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
});
