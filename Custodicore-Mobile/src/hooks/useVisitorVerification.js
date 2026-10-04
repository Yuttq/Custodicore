import { useFocusEffect } from '@react-navigation/native';
import { useCallback, useRef, useState } from 'react';
import { useAuth } from './useAuth';
import { fetchVerification } from '../repositories/verificationRepository';

/**
 * Real verification state (GET /api/documents) for the signed-in visitor.
 * Reloads whenever the screen regains focus, so staff decisions and new
 * uploads show up without restarting the app.
 */
export default function useVisitorVerification() {
  const { user, registrationSummary } = useAuth();
  const [verification, setVerification] = useState(
    /** @type {ReturnType<typeof import('../repositories/verificationRepository').normalizeVerification> | null} */ (null),
  );
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(/** @type {string | null} */ (null));
  const loadedOnce = useRef(false);

  const relationshipHint = user?.relationshipHint ?? null;
  const fallbackRelationship = registrationSummary?.relationship ?? null;

  const reload = useCallback(async () => {
    // Keep showing the last good data during focus refreshes.
    if (!loadedOnce.current) setLoading(true);
    setError(null);
    try {
      const next = await fetchVerification({ relationshipHint, fallbackRelationship });
      setVerification(next);
      loadedOnce.current = true;
    } catch (e) {
      setError(
        e instanceof Error && e.message.trim() ? e.message : 'Could not load verification status.',
      );
    } finally {
      setLoading(false);
    }
  }, [relationshipHint, fallbackRelationship]);

  useFocusEffect(
    useCallback(() => {
      reload();
    }, [reload]),
  );

  return { verification, loading, error, reload };
}
