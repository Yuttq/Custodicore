import { useCallback, useState } from 'react';
import { Alert } from 'react-native';
import { cancelPdlRequest } from '../repositories/verificationRepository';
import {
  canAddAnotherPdl,
  pdlRequestPrefill,
  relationshipRequestPrefill,
} from '../utils/pdlLinkRequest';

/**
 * My PDLs request actions, shared by Profile → My PDLs and the inline list in
 * Verification Documents: Cancel Request (open request) and Request Again
 * (rejected request → the prefilled Add Another PDL form, Phase 4).
 * @param {object} params
 * @param {any} params.navigation
 * @param {ReturnType<typeof import('../repositories/verificationRepository').normalizeVerification> | null} params.verification
 * @param {() => void} params.reload
 */
export default function useMyPdlActions({ navigation, verification, reload }) {
  const [cancelling, setCancelling] = useState(false);
  const pdlRequest = verification?.pdlRequest ?? null;

  // AddPdlRequest is only registered for approved visitors (AppNavigator).
  const canRequestAgain =
    pdlRequest?.status === 'rejected' &&
    canAddAnotherPdl(verification?.backendStatus, pdlRequest) &&
    Boolean(navigation.getState?.()?.routeNames?.includes('AddPdlRequest'));

  const requestAgain = useCallback(() => {
    navigation.navigate('AddPdlRequest', { prefill: pdlRequestPrefill(pdlRequest) });
  }, [navigation, pdlRequest]);

  // A rejected PDL relationship takes no new documents: the visitor sends a
  // new Add Another PDL request for it (prefilled), same rules as above.
  const canRequestForRelationship =
    canAddAnotherPdl(verification?.backendStatus, pdlRequest) &&
    Boolean(navigation.getState?.()?.routeNames?.includes('AddPdlRequest'));

  const requestAgainForRelationship = useCallback(
    (pdl) => {
      navigation.navigate('AddPdlRequest', { prefill: relationshipRequestPrefill(pdl) });
    },
    [navigation],
  );

  // Cancelling works whatever the account status (the backend allows it).
  const cancelRequest = useCallback(() => {
    if (!pdlRequest || cancelling) return;
    Alert.alert(
      'Cancel request?',
      `Your request to add ${pdlRequest.pdlName} and the documents you sent with it will be removed.`,
      [
        { text: 'Keep Request', style: 'cancel' },
        {
          text: 'Cancel Request',
          style: 'destructive',
          onPress: async () => {
            setCancelling(true);
            try {
              await cancelPdlRequest(pdlRequest.id);
            } catch (e) {
              // e.g. 409: staff already linked or reviewed it — the refresh shows the outcome.
              Alert.alert(
                'Could not cancel',
                e instanceof Error && e.message ? e.message : 'Please try again.',
              );
            } finally {
              setCancelling(false);
              reload();
            }
          },
        },
      ],
    );
  }, [pdlRequest, cancelling, reload]);

  return {
    cancelling,
    cancelRequest,
    requestAgain: canRequestAgain ? requestAgain : null,
    requestAgainForRelationship: canRequestForRelationship ? requestAgainForRelationship : null,
  };
}
