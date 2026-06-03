import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
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
import { EmptyState } from '../components';
import { useVisits } from '../context/VisitsContext';
import { goBackOr } from '../utils/safeNavigation';
import { fetchVisitationHistory } from '../repositories/visitHistoryRepository';

/** @typedef {import('../mock/visitationHistory.mock').MOCK_VISITATION_HISTORY[number]} HistoryRecord */

function DetailField({ label, value }) {
  return (
    <View style={styles.detailField}>
      <Text style={styles.detailLabel}>{label}</Text>
      <Text style={styles.detailValue}>{value}</Text>
    </View>
  );
}

/**
 * Visitation history record — extended visit details (v2.1 / BJMP).
 */
export default function VisitHistoryDetailScreen({ navigation, route }) {
  const visitId = route.params?.visitId;
  const { getVisitById } = useVisits();
  const [record, setRecord] = useState(/** @type {HistoryRecord | null} */ (null));
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setNotFound(false);
      try {
        const data = await fetchVisitationHistory();
        if (cancelled) return;
        const match = data.find((row) => row.id === visitId) ?? null;
        setRecord(match);
        setNotFound(!match);
      } catch {
        if (!cancelled) setNotFound(true);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [visitId]);

  const canOpenVisitDetails = useMemo(
    () => record?.status === 'completed' && Boolean(record && getVisitById(record.id)),
    [record, getVisitById],
  );

  const onOpenFullVisitDetails = useCallback(() => {
    if (!record) return;
    navigation.navigate('VisitDetails', { visitId: record.id });
  }, [navigation, record]);

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Visit Details" navigation={navigation} />

      {loading ? (
        <View style={styles.centered}>
          <ActivityIndicator size="large" color={colors.primaryTeal} />
          <Text style={styles.loadingLabel}>Loading visit record…</Text>
        </View>
      ) : notFound || !record ? (
        <EmptyState
          title="Visit Record Not Found"
          message="This record may have been removed or is no longer available."
          iconName="document-text-outline"
          iconColor={colors.textSecondary}
          style={styles.centered}
        >
          <Button
            title="Go Back"
            variant="secondary"
            onPress={() => goBackOr(navigation)}
            accessibilityLabel="Go back"
          />
        </EmptyState>
      ) : (
        <ScrollView
          contentContainerStyle={commonStyles.scrollContent}
          showsVerticalScrollIndicator={false}
          keyboardShouldPersistTaps="handled"
        >
          <Card style={styles.card}>
            <Text style={styles.visitDate}>{record.dateDisplay}</Text>
            <View style={styles.chipRow}>
              <StatusChip status={record.status} />
            </View>

            <DetailField label="Date" value={record.dateDisplay} />
            <DetailField label="Time" value={record.timeLabel} />
            <DetailField label="PDL Name" value={record.pdlName} />
            <DetailField label="Facility" value={record.facility} />
            <DetailField label="Reference" value={record.referenceNumber} />
            {record.visitType ? (
              <DetailField label="Visit Type" value={record.visitType} />
            ) : null}

            {record.status === 'cancelled' && record.cancellationReason ? (
              <View style={styles.cancellationBox}>
                <Text style={styles.cancellationTitle}>Cancellation Reason</Text>
                <Text style={styles.cancellationBody}>{record.cancellationReason}</Text>
              </View>
            ) : null}

            {canOpenVisitDetails ? (
              <Button
                title="Open Full Visit Details"
                onPress={onOpenFullVisitDetails}
                accessibilityLabel="Open full visit details"
              />
            ) : null}
          </Card>
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.md,
    gap: spacing.md,
  },
  loadingLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.sm,
  },
  card: {
    borderRadius: layout.cardRadius,
    padding: spacing.md,
  },
  visitDate: {
    ...typography.cardTitle,
    color: colors.primaryNavy,
    marginBottom: spacing.sm,
  },
  chipRow: {
    alignSelf: 'flex-start',
    marginBottom: spacing.md,
  },
  detailField: {
    marginBottom: spacing.md,
  },
  detailLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginBottom: spacing.xs,
  },
  detailValue: {
    ...typography.body,
    color: colors.textPrimary,
    lineHeight: 22,
  },
  cancellationBox: {
    marginBottom: spacing.md,
    padding: spacing.sm,
    borderRadius: layout.borderRadiusSm,
    backgroundColor: 'rgba(239, 68, 68, 0.06)',
    borderWidth: 1,
    borderColor: 'rgba(239, 68, 68, 0.2)',
  },
  cancellationTitle: {
    ...typography.statusLabel,
    color: colors.danger,
    marginBottom: spacing.xs,
  },
  cancellationBody: {
    ...typography.body,
    color: colors.textPrimary,
    lineHeight: 20,
  },
});
