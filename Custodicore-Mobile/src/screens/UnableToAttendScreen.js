import React, { useCallback, useState } from 'react';
import {
  Alert,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Button,
  Card,
  StackScreenHeader,
  colors,
  commonStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { useVisits } from '../context/VisitsContext';
import { UNABLE_TO_ATTEND_REASONS } from '../utils/visitRules';

/**
 * Reason selection and optional notes, for two actions:
 * - default: decline a staff-assigned visit awaiting the visitor's answer
 *   ("Unable To Attend", pending_confirmation);
 * - `mode: 'cancel'`: cancel the visitor's own assigned or confirmed visit.
 */
export default function UnableToAttendScreen({ navigation, route }) {
  const visitId = route.params?.visitId;
  const isCancel = route.params?.mode === 'cancel';
  const { getVisitById, submitUnableToAttend, cancelVisit } = useVisits();
  const visit = getVisitById(visitId);

  const [reason, setReason] = useState(null);
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);

  const showError = useCallback((e) => {
    const message =
      typeof e?.message === 'string' && e.message.trim()
        ? e.message
        : 'Could not submit your response. Please try again.';
    Alert.alert('Error', message);
  }, []);

  const goToSchedule = useCallback(() => {
    navigation.navigate('MainTabs', { screen: 'Schedule' });
  }, [navigation]);

  const submitCancel = useCallback(async () => {
    setSubmitting(true);
    try {
      await cancelVisit(visit.id, { reason, notes });
      Alert.alert('Visit cancelled', 'Your visit was cancelled and your seat was released.', [
        { text: 'OK', onPress: goToSchedule },
      ]);
    } catch (e) {
      showError(e);
    } finally {
      setSubmitting(false);
    }
  }, [visit, reason, notes, cancelVisit, goToSchedule, showError]);

  const onSubmit = useCallback(async () => {
    if (!visit || submitting) return;
    if (!reason) {
      setError('Please select a reason');
      return;
    }
    setError(null);

    if (isCancel) {
      Alert.alert(
        'Cancel this visit?',
        "This can't be undone. You can request a new visit afterwards.",
        [
          { text: 'Keep Visit', style: 'cancel' },
          { text: 'Cancel Visit', style: 'destructive', onPress: submitCancel },
        ],
      );
      return;
    }

    setSubmitting(true);
    try {
      await submitUnableToAttend(visit.id, { reason, notes });
      Alert.alert(
        'Submitted',
        'Your response has been recorded. Status: Declined.',
        [{ text: 'OK', onPress: goToSchedule }],
      );
    } catch (e) {
      showError(e);
    } finally {
      setSubmitting(false);
    }
  }, [visit, reason, notes, submitting, isCancel, submitCancel, submitUnableToAttend, goToSchedule, showError]);

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title={isCancel ? 'Cancel Visit' : 'Unable To Attend'} navigation={navigation} />
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView
          contentContainerStyle={commonStyles.scrollContent}
          keyboardShouldPersistTaps="handled"
        >
          {visit ? (
            <Text style={styles.subtitle}>
              Visit with {visit.pdlName} · {visit.dateDisplay}
            </Text>
          ) : null}
          {isCancel ? (
            <Text style={styles.subtitle}>
              Your seat will be released and your QR pass will stop working.
            </Text>
          ) : null}

          <Text style={styles.sectionHeading}>REASON</Text>
          <Card style={styles.card}>
            {error && !reason ? <Text style={styles.error}>{error}</Text> : null}
            {UNABLE_TO_ATTEND_REASONS.map((option) => {
              const selected = reason === option;
              return (
                <Pressable
                  key={option}
                  onPress={() => {
                    setReason(option);
                    setError(null);
                  }}
                  style={[styles.optionRow, selected && styles.optionRowSelected]}
                  accessibilityRole="radio"
                  accessibilityState={{ selected }}
                >
                  <View style={[styles.radio, selected && styles.radioSelected]}>
                    {selected ? <View style={styles.radioDot} /> : null}
                  </View>
                  <Text style={[styles.optionLabel, selected && styles.optionLabelSelected]}>
                    {option}
                  </Text>
                </Pressable>
              );
            })}
          </Card>

          <Text style={styles.sectionHeading}>OPTIONAL NOTES</Text>
          <Card style={styles.card}>
            <TextInput
              value={notes}
              onChangeText={setNotes}
              placeholder="Add details (optional)"
              placeholderTextColor={colors.textSecondary}
              multiline
              maxLength={isCancel ? 150 : undefined}
              style={styles.notesInput}
            />
          </Card>

          <Button
            title={isCancel ? 'Cancel Visit' : 'Submit'}
            onPress={onSubmit}
            loading={submitting}
            disabled={submitting}
            accessibilityLabel={isCancel ? 'Cancel this visit' : 'Submit unable to attend'}
          />
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  subtitle: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.md,
  },
  sectionHeading: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  card: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.cardGap,
  },
  error: {
    ...typography.metadata,
    color: colors.danger,
    marginBottom: spacing.sm,
  },
  optionRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.xs,
    borderRadius: 10,
    marginBottom: spacing.xs,
  },
  optionRowSelected: {
    backgroundColor: 'rgba(13, 165, 138, 0.06)',
  },
  radio: {
    width: 22,
    height: 22,
    borderRadius: 11,
    borderWidth: 2,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing.sm,
  },
  radioSelected: { borderColor: colors.primaryTeal },
  radioDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
    backgroundColor: colors.primaryTeal,
  },
  optionLabel: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
  },
  optionLabelSelected: { fontWeight: '600' },
  notesInput: {
    minHeight: 100,
    ...typography.body,
    color: colors.textPrimary,
    textAlignVertical: 'top',
    padding: 0,
  },
});
