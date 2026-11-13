import { useCallback, useState } from 'react';
import { Alert } from 'react-native';
import { cancelPdlRequest } from '../repositories/verificationRepository';
import {
  PDL_REQUEST_LIMIT_TITLE,
  canAddAnotherPdl,
  pdlRequestLimitMessage,
  pdlRequestLimitReached,
  pdlRequestPrefill,
  relationshipRequestPrefill,
} from '../utils/pdlLinkRequest';

/**
 * My PDLs request actions, shared by Profile → My PDLs and the inline list in
 * Verification Documents. Each action takes the request it is for, so one
 * request's Cancel Request (open) or Request Again (rejected → the prefilled
 * Add Another PDL form, Phase 4) never touches another.
 * @param {object} params
 * @param {any} params.navigation
 * @param {ReturnType<typeof import('../repositories/verificationRepository').normalizeVerification> | null} params.verification
 * @param {() => void} params.reload
 */
export default function useMyPdlActions({ navigation, verification, reload }) {
  // The id of the request being cancelled, so only its button spins.
  const [cancellingId, setCancellingId] = useState(/** @type {string | null} */ (null));
  const limit = verification?.pdlRequestLimit ?? null;

  // AddPdlRequest is only registered for approved visitors (AppNavigator).
  const canOpenForm =
    canAddAnotherPdl(verification?.backendStatus) &&
    Boolean(navigation.getState?.()?.routeNames?.includes('AddPdlRequest'));

  // At the limit the button stays, and explains why no new request can go yet.
  const openForm = useCallback(
    (prefill) => {
      if (pdlRequestLimitReached(limit)) {
        Alert.alert(PDL_REQUEST_LIMIT_TITLE, pdlRequestLimitMessage(limit.max), [{ text: 'OK' }]);
        return;
      }
      navigation.navigate('AddPdlRequest', prefill ? { prefill } : undefined);
    },
    [navigation, limit],
  );

  const addPdl = useCallback(() => openForm(null), [openForm]);

  /** @param {import('../utils/pdlLinkRequest').PdlRequestStatus} request */
  const requestAgain = useCallback((request) => openForm(pdlRequestPrefill(request)), [openForm]);

  // A rejected PDL relationship takes no new documents: the visitor sends a
  // new Add Another PDL request for it (prefilled), same rules as above.
  const requestAgainForRelationship = useCallback(
    (pdl) => openForm(relationshipRequestPrefill(pdl)),
    [openForm],
  );

  // Cancelling works whatever the account status (the backend allows it).
  /** @param {import('../utils/pdlLinkRequest').PdlRequestStatus} request */
  const cancelRequest = useCallback(
    (request) => {
      if (!request || cancellingId) return;
      Alert.alert(
        'Cancel request?',
        `Your request to add ${request.pdlName} and the documents you sent with it will be removed.`,
        [
          { text: 'Keep Request', style: 'cancel' },
          {
            text: 'Cancel Request',
            style: 'destructive',
            onPress: async () => {
              setCancellingId(request.id);
              try {
                await cancelPdlRequest(request.id);
              } catch (e) {
                // e.g. 409: staff already linked or reviewed it — the refresh shows the outcome.
                Alert.alert(
                  'Could not cancel',
                  e instanceof Error && e.message ? e.message : 'Please try again.',
                );
              } finally {
                setCancellingId(null);
                reload();
              }
            },
          },
        ],
      );
    },
    [cancellingId, reload],
  );

  return {
    cancellingId,
    cancelRequest,
    addPdl: canOpenForm ? addPdl : null,
    requestAgain: canOpenForm ? requestAgain : null,
    requestAgainForRelationship: canOpenForm ? requestAgainForRelationship : null,
  };
}
