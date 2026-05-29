import Ionicons from '@expo/vector-icons/Ionicons';
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
import { Button, Card, colors, layout, spacing, typography } from '../designSystem';
import { useVisits } from '../context/VisitsContext';
import { UNABLE_TO_ATTEND_REASONS } from '../mock/assignedVisits.mock';

/**
 * Unable to attend — reason selection and optional notes (v2.1 / mock API).
 */
export default function UnableToAttendScreen({ navigation, route }) {
  const visitId = route.params?.visitId;
  const { getVisitById, submitUnableToAttend } = useVisits();
  const visit = getVisitById(visitId);

  const [reason, setReason] = useState(null);
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);

  const onSubmit = useCallback(async () => {
    if (!visit || submitting) return;
    if (!reason) {
      setError('Please select a reason');
      return;
    }
    setError(null);
    setSubmitting(true);
    try {
      await submitUnableToAttend(visit.id, { reason, notes });
      Alert.alert(
        'Submitted',
        'Your response has been recorded. Status: Unable To Attend.',
        [
          {
            text: 'OK',
            onPress: () => {
              navigation.navigate('MainTabs', { screen: 'Schedule' });
            },
          },
        ],
      );
    } catch {
      Alert.alert('Error', 'Could not submit your response. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }, [visit, reason, notes, submitting, submitUnableToAttend, navigation]);

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <View style={styles.topBar}>
          <Pressable
            onPress={() => navigation.goBack()}
            style={styles.backButton}
            accessibilityRole="button"
            accessibilityLabel="Go back"
          >
            <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
          </Pressable>
          <Text style={styles.screenTitle}>Unable To Attend</Text>
          <View style={styles.backPlaceholder} />
        </View>

        <ScrollView
          contentContainerStyle={styles.scroll}
          keyboardShouldPersistTaps="handled"
        >
          {visit ? (
            <Text style={styles.subtitle}>
              Visit with {visit.pdlName} · {visit.dateDisplay}
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
              style={styles.notesInput}
            />
          </Card>

          <Button
            title="Submit"
            onPress={onSubmit}
            loading={submitting}
            disabled={submitting}
            accessibilityLabel="Submit unable to attend"
          />
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  flex: { flex: 1 },
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
  subtitle: {
    ...typography.body,
    color: colors.textSecondary,
    marginBottom: spacing[16],
  },
  sectionHeading: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textSecondary,
    letterSpacing: 0.6,
    marginBottom: spacing[8],
  },
  card: {
    borderRadius: layout.cardRadius,
    marginBottom: layout.cardGap,
  },
  error: {
    ...typography.caption,
    color: colors.danger,
    marginBottom: spacing[8],
  },
  optionRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing[12],
    paddingHorizontal: spacing[4],
    borderRadius: 10,
    marginBottom: spacing[4],
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
    marginRight: spacing[12],
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
