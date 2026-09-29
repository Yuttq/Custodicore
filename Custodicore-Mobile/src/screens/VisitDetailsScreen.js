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
import { EmptyState } from '../components';
import {
  Button,
  Card,
  StackScreenHeader,
  StatusChip,
  colors,
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { goBackOr } from '../utils/safeNavigation';
import { useVisits } from '../context/VisitsContext';
import {
  VISITATION_GUIDELINE_SECTIONS,
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
 * @param {object} props
 * @param {import('../mock/assignedVisits.mock').VisitationGuidelineSection} props.section
 * @param {boolean} props.isLast
 */
function GuidelineSection({ section, isLast }) {
  return (
    <View style={!isLast ? styles.guidelineSectionSpaced : undefined}>
      <Text style={styles.guidelineSectionTitle}>{section.title}</Text>
      {section.items.map((item) => (
        <View key={item} style={styles.guidelineBulletRow}>
          <Text style={styles.guidelineBullet}>•</Text>
          <Text style={styles.guidelineBulletText}>{item}</Text>
        </View>
      ))}
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
        [{ text: 'OK', onPress: () => goBackOr(navigation) }],
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
      <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
        <StackScreenHeader title="Visit Details" navigation={navigation} />
        <EmptyState
          title="Visit Not Found"
          message="This visit may have been removed or is no longer available."
          iconName="calendar-outline"
          iconColor={colors.textSecondary}
          style={styles.missingEmpty}
        >
          <Button
            title="Go Back"
            variant="secondary"
            onPress={() => goBackOr(navigation)}
            accessibilityLabel="Go back"
          />
        </EmptyState>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Visit Details" navigation={navigation} />

      <View style={styles.statusRow}>
        <StatusChip status={visit.status} />
      </View>

      <View style={commonStyles.segmentedControl}>
        {DETAIL_TABS.map((tab) => {
          const active = activeTab === tab.key;
          return (
            <Pressable
              key={tab.key}
              onPress={() => setActiveTab(tab.key)}
              style={[commonStyles.segmentedTab, active && commonStyles.segmentedTabActive]}
              accessibilityRole="tab"
              accessibilityState={{ selected: active }}
            >
              <Text
                style={[
                  commonStyles.segmentedTabLabel,
                  active && commonStyles.segmentedTabLabelActive,
                ]}
              >
                {tab.label}
              </Text>
            </Pressable>
          );
        })}
      </View>

      <ScrollView
        contentContainerStyle={commonStyles.scrollContent}
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
              <View style={commonStyles.actionsStack}>
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
              Please review these requirements before arriving at the facility.
            </Text>
            {VISITATION_GUIDELINE_SECTIONS.map((section, index) => (
              <GuidelineSection
                key={section.title}
                section={section}
                isLast={index === VISITATION_GUIDELINE_SECTIONS.length - 1}
              />
            ))}
          </Card>
        ) : null}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  statusRow: {
    alignSelf: 'flex-start',
    marginLeft: layout.screenPadding,
    marginBottom: spacing.md,
  },
  card: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.cardGap,
  },
  infoRow: {
    marginBottom: spacing.sm,
  },
  infoLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.xs,
  },
  infoValue: {
    ...typography.body,
    color: colors.textPrimary,
    fontWeight: '500',
  },
  guidelinesTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    marginBottom: spacing.xs,
  },
  guidelinesSub: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.md,
  },
  guidelineSectionSpaced: {
    marginBottom: spacing.md,
  },
  guidelineSectionTitle: {
    ...typography.body,
    fontWeight: '700',
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  guidelineBulletRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    marginBottom: spacing.xs,
    paddingLeft: spacing.xs,
  },
  guidelineBullet: {
    ...typography.body,
    color: colors.textSecondary,
    lineHeight: 20,
    width: spacing.sm,
  },
  guidelineBulletText: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
    lineHeight: 20,
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
  missingEmpty: {
    flex: 1,
    paddingHorizontal: layout.screenPadding,
  },
});
