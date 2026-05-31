import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useMemo, useState } from 'react';
import {
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import CompactVisitTimeline from '../components/CompactVisitTimeline';
import {
  Button,
  Card,
  StatusChip,
  colors,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { useVisits } from '../context/VisitsContext';
import {
  VISITATION_GUIDELINES,
  canRespondToVisit,
} from '../mock/assignedVisits.mock';
import { getCompactVisitSteps } from '../utils/visitProgressSnapshot';

const DETAIL_TABS = [
  { key: 'overview', label: 'Overview' },
  { key: 'timeline', label: 'Timeline' },
  { key: 'guidelines', label: 'Guidelines' },
];

function InfoRow({ label, value }) {
  return (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      <Text style={styles.infoValue}>{value || '—'}</Text>
    </View>
  );
}

/**
 * Visit details — overview, timeline, and guidelines (v2.1).
 */
export default function VisitDetailsScreen({ navigation, route }) {
  const visitId = route.params?.visitId;
  const { getVisitById, confirmVisit } = useVisits();
  const visit = getVisitById(visitId);
  const [activeTab, setActiveTab] = useState('overview');
  const [submitting, setSubmitting] = useState(false);

  const showActions = visit && canRespondToVisit(visit.status);
  const showQrPass =
    visit && (visit.status === 'confirmed' || visit.status === 'checked_in');

  const compactSteps = useMemo(
    () => (visit ? getCompactVisitSteps(visit.id, visit.status) : []),
    [visit],
  );

  const onConfirm = useCallback(async () => {
    if (!visit || submitting) return;
    setSubmitting(true);
    try {
      await confirmVisit(visit.id);
      Alert.alert(
        'Attendance confirmed',
        'Your attendance has been recorded. Please arrive on time with valid ID.',
        [{ text: 'OK', onPress: () => navigation.goBack() }],
      );
    } catch {
      Alert.alert('Error', 'Could not confirm attendance. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }, [visit, submitting, confirmVisit, navigation]);

  const onUnableToAttend = useCallback(() => {
    if (!visit) return;
    navigation.navigate('UnableToAttend', { visitId: visit.id });
  }, [visit, navigation]);

  const onShowQrPass = useCallback(() => {
    if (!visit) return;
    navigation.navigate('MainTabs', {
      screen: 'QR',
      params: { scheduleId: visit.id, visitId: visit.id },
    });
  }, [navigation, visit]);

  if (!visit) {
    return (
      <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
        <View style={styles.missingWrap}>
          <Text style={styles.missingText}>Visit not found.</Text>
          <Button title="Go Back" onPress={() => navigation.goBack()} />
        </View>
      </SafeAreaView>
    );
  }

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
        <Text style={styles.screenTitle}>Visit Details</Text>
        <View style={styles.backPlaceholder} />
      </View>

      <View style={styles.statusRow}>
        <StatusChip status={visit.status} />
      </View>

      <View style={styles.tabBar}>
        {DETAIL_TABS.map((tab) => {
          const active = activeTab === tab.key;
          return (
            <Pressable
              key={tab.key}
              onPress={() => setActiveTab(tab.key)}
              style={[styles.tab, active && styles.tabActive]}
              accessibilityRole="tab"
              accessibilityState={{ selected: active }}
            >
              <Text style={[styles.tabLabel, active && styles.tabLabelActive]}>
                {tab.label}
              </Text>
            </Pressable>
          );
        })}
      </View>

      <ScrollView
        contentContainerStyle={styles.scroll}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        {activeTab === 'overview' ? (
          <>
            <Card style={styles.card}>
              <InfoRow label="Date" value={visit.dateDisplay} />
              <InfoRow label="Time" value={visit.timeLabel} />
              <InfoRow label="Facility" value={visit.facility} />
              <InfoRow label="PDL Name" value={visit.pdlName} />
              <InfoRow label="Visit Type" value={visit.visitType} />
              <InfoRow label="Reference Number" value={visit.referenceNumber} />
            </Card>

            {showQrPass ? (
              <Button
                title="Show QR Pass"
                onPress={onShowQrPass}
                accessibilityLabel="Show QR pass for this visit"
              />
            ) : null}

            {showActions ? (
              <View style={styles.actions}>
                <Button
                  title="Confirm Attendance"
                  onPress={onConfirm}
                  loading={submitting}
                  disabled={submitting}
                  accessibilityLabel="Confirm attendance"
                />
                <Pressable
                  onPress={onUnableToAttend}
                  style={({ pressed }) => [styles.unableBtn, pressed && styles.pressed]}
                  accessibilityRole="button"
                  accessibilityLabel="Unable to attend"
                >
                  <Text style={styles.unableBtnText}>Unable To Attend</Text>
                </Pressable>
              </View>
            ) : null}
          </>
        ) : null}

        {activeTab === 'timeline' ? (
          <CompactVisitTimeline steps={compactSteps} />
        ) : null}

        {activeTab === 'guidelines' ? (
          <Card style={styles.card}>
            <Text style={styles.guidelinesTitle}>Visitation Guidelines</Text>
            <Text style={styles.guidelinesSub}>
              Facility rules and reminders for your assigned visit.
            </Text>
            {VISITATION_GUIDELINES.map((item) => (
              <View key={item} style={styles.guidelineRow}>
                <Ionicons name="checkmark-circle" size={18} color={colors.success} />
                <Text style={styles.guidelineText}>{item}</Text>
              </View>
            ))}
          </Card>
        ) : null}
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
  statusRow: {
    alignSelf: 'flex-start',
    marginLeft: spacing[20],
    marginBottom: spacing[12],
  },
  tabBar: {
    flexDirection: 'row',
    marginHorizontal: spacing[20],
    marginBottom: spacing[12],
    backgroundColor: colors.card,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.border,
    padding: spacing[4],
  },
  tab: {
    flex: 1,
    paddingVertical: spacing[8],
    alignItems: 'center',
    borderRadius: 8,
  },
  tabActive: {
    backgroundColor: colors.primaryTeal,
  },
  tabLabel: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textSecondary,
  },
  tabLabelActive: {
    color: colors.white,
  },
  scroll: {
    paddingHorizontal: spacing[20],
    paddingBottom: spacing[32],
  },
  card: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.cardGap,
  },
  infoRow: {
    marginBottom: spacing[12],
  },
  infoLabel: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[4],
  },
  infoValue: {
    ...typography.body,
    color: colors.textPrimary,
    fontWeight: '500',
  },
  guidelinesTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing[4],
  },
  guidelinesSub: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[12],
    lineHeight: 18,
  },
  guidelineRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing[10],
    marginBottom: spacing[8],
  },
  guidelineText: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
    lineHeight: 20,
  },
  actions: {
    marginTop: spacing[12],
    gap: spacing[12],
  },
  unableBtn: {
    height: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.danger,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.white,
  },
  unableBtnText: {
    ...typography.body,
    fontWeight: '600',
    color: colors.danger,
  },
  pressed: { opacity: 0.92 },
  missingWrap: {
    flex: 1,
    justifyContent: 'center',
    padding: spacing[20],
    gap: spacing[16],
  },
  missingText: {
    ...typography.body,
    color: colors.textSecondary,
    textAlign: 'center',
  },
});
