import React, { useCallback, useMemo } from 'react';
import { ScrollView, StyleSheet, Text } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Button,
  StackScreenHeader,
  colors,
  commonStyles,
  spacing,
  typography,
} from '../designSystem';
import { EmptyState, LoadingSpinner } from '../components';
import MyPdlsList from '../components/MyPdlsList';
import useMyPdlActions from '../hooks/useMyPdlActions';
import useVisitorVerification from '../hooks/useVisitorVerification';
import { relationshipsNeedingDocuments } from '../utils/myPdls';

/**
 * Profile → My PDLs: the visitor's PDL relationships and their status, plus
 * the current "Add Another PDL" request (Cancel Request / Request Again).
 * Same data as Verification Documents (GET /api/documents). New requests
 * start only from the (+) on Home → Visitation Calendar.
 */
export default function MyPdlsScreen({ navigation }) {
  const { verification, loading, error, reload } = useVisitorVerification();
  const { cancelling, cancelRequest, requestAgain } = useMyPdlActions({
    navigation,
    verification,
    reload,
  });

  const pdls = verification?.pdlRelationships ?? [];
  const pdlRequest = verification?.pdlRequest ?? null;
  const docsNeeded = useMemo(
    () => relationshipsNeedingDocuments(verification?.documents),
    [verification],
  );
  const openDocuments = useCallback(
    () => navigation.navigate('VisitorVerificationDocuments'),
    [navigation],
  );

  let content;
  if (loading && !verification) {
    content = <LoadingSpinner message="Loading your PDLs…" compact />;
  } else if (error && !verification) {
    content = (
      <EmptyState
        title="Couldn't load your PDLs"
        message={error}
        iconName="cloud-offline-outline"
        iconColor={colors.danger}
        emphasis="error"
        style={styles.empty}
      >
        <Button title="Retry" onPress={reload} accessibilityLabel="Retry loading your PDLs" />
      </EmptyState>
    );
  } else if (pdls.length === 0 && !pdlRequest) {
    content = (
      <EmptyState
        title="No PDLs yet"
        message="Add a PDL with the (+) on Home → Visitation Calendar."
        iconName="people-outline"
        iconColor={colors.primaryTeal}
        style={styles.empty}
      />
    );
  } else {
    content = (
      <>
        <MyPdlsList
          variant="screen"
          pdls={pdls}
          pdlRequest={pdlRequest}
          onCancelRequest={cancelRequest}
          cancelling={cancelling}
          onRequestAgain={requestAgain}
          docsNeeded={docsNeeded}
          onOpenDocuments={openDocuments}
        />
        <Text style={styles.footnote}>Add another PDL with the (+) on Home → Visitation Calendar.</Text>
      </>
    );
  }

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="My PDLs" navigation={navigation} />
      <ScrollView
        contentContainerStyle={commonStyles.scrollContent}
        showsVerticalScrollIndicator={false}
      >
        {content}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  empty: {
    paddingVertical: spacing.md,
  },
  footnote: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
    marginTop: -spacing.sm,
  },
});
