import React, { useCallback, useMemo } from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Button,
  StackScreenHeader,
  colors,
  commonStyles,
  spacing,
} from '../designSystem';
import { EmptyState, LoadingSpinner } from '../components';
import MyPdlsList from '../components/MyPdlsList';
import useMyPdlActions from '../hooks/useMyPdlActions';
import { useAuth } from '../hooks/useAuth';
import useVisitorVerification from '../hooks/useVisitorVerification';
import { relationshipsNeedingDocuments } from '../utils/myPdls';
import { canAddAnotherPdl } from '../utils/pdlLinkRequest';

/**
 * Profile → My PDLs: the visitor's PDL relationships and their status, plus
 * the current "Add Another PDL" request (Cancel Request / Request Again).
 * Same data as Verification Documents (GET /api/documents). New requests
 * start from "+ Add Another PDL" here or the (+) on Home → Visitation
 * Calendar, under the same rule (canAddAnotherPdl).
 */
export default function MyPdlsScreen({ navigation }) {
  const { isApprovedVisitor } = useAuth();
  const { verification, loading, error, reload } = useVisitorVerification();
  const { cancelling, cancelRequest, requestAgain, requestAgainForRelationship } = useMyPdlActions({
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
  // Same rule as the (+) on Home: approved account and no request already
  // open (the backend checks both again). Reloads on focus, so the button
  // hides once a request is sent.
  const canAddPdl = isApprovedVisitor && canAddAnotherPdl(verification?.backendStatus, pdlRequest);
  const addPdl = useCallback(() => navigation.navigate('AddPdlRequest'), [navigation]);

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
        message={canAddPdl ? 'Add the PDL you want to visit.' : 'Your PDLs will appear here once facility staff link them.'}
        iconName="people-outline"
        iconColor={colors.primaryTeal}
        style={styles.empty}
      >
        {canAddPdl ? (
          <Button title="+ Add Another PDL" onPress={addPdl} accessibilityLabel="Add another PDL" />
        ) : null}
      </EmptyState>
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
          onRequestAgainForPdl={requestAgainForRelationship}
          docsNeeded={docsNeeded}
          onOpenDocuments={openDocuments}
        />
        {canAddPdl ? (
          <View style={styles.addButton}>
            <Button
              title="+ Add Another PDL"
              variant="secondary"
              onPress={addPdl}
              accessibilityLabel="Add another PDL"
            />
          </View>
        ) : null}
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
  addButton: {
    marginTop: spacing.xs,
  },
});
