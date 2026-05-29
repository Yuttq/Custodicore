import AsyncStorage from '@react-native-async-storage/async-storage';
import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react';
// Phase 4: import * as api from '../services/api';

const TOKEN_KEY = '@custodicore/auth_token';
const PENDING_VERIFICATION_KEY = '@custodicore/pending_verification';
const REGISTRATION_SUMMARY_KEY = '@custodicore/registration_summary';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [token, setToken] = useState(null);
  const [initializing, setInitializing] = useState(true);
  const [error, setError] = useState(null);
  const [pendingVerification, setPendingVerification] = useState(false);
  const [registrationSummary, setRegistrationSummary] = useState(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const [storedToken, pending, summaryJson] = await Promise.all([
          AsyncStorage.getItem(TOKEN_KEY),
          AsyncStorage.getItem(PENDING_VERIFICATION_KEY),
          AsyncStorage.getItem(REGISTRATION_SUMMARY_KEY),
        ]);
        if (!cancelled) {
          setToken(storedToken);
          setPendingVerification(pending === '1');
          if (summaryJson) {
            try {
              setRegistrationSummary(JSON.parse(summaryJson));
            } catch {
              setRegistrationSummary(null);
            }
          }
        }
      } finally {
        if (!cancelled) setInitializing(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const login = useCallback(async (email, password) => {
    setError(null);
    try {
      // Phase 4: const data = await api.login(email, password); then persist data.token
      await new Promise((r) => setTimeout(r, 350));
      if (!String(email || '').trim() || !password) {
        throw new Error('Please enter email and password.');
      }
      await AsyncStorage.setItem(TOKEN_KEY, 'placeholder-token');
      setToken('placeholder-token');
    } catch (e) {
      const message = e?.message ?? 'Login failed';
      setError(message);
      throw e;
    }
  }, []);

  const register = useCallback(async (payload) => {
    setError(null);
    try {
      // Phase 4: await api.register(payload)
      await new Promise((r) => setTimeout(r, 350));

      const summary = {
        fullName: payload?.fullName,
        relationship: payload?.relationship,
        relationshipLabel: payload?.relationshipLabel,
        documents: payload?.documentsSummary ?? [],
      };

      await AsyncStorage.multiSet([
        [TOKEN_KEY, 'placeholder-token'],
        [PENDING_VERIFICATION_KEY, '1'],
        [REGISTRATION_SUMMARY_KEY, JSON.stringify(summary)],
      ]);

      setRegistrationSummary(summary);
      setPendingVerification(true);
      setToken('placeholder-token');
    } catch (e) {
      const message = e?.message ?? 'Registration failed';
      setError(message);
      throw e;
    }
  }, []);

  const completeVerificationReview = useCallback(async () => {
    await AsyncStorage.removeItem(PENDING_VERIFICATION_KEY);
    setPendingVerification(false);
  }, []);

  const logout = useCallback(async () => {
    setError(null);
    await AsyncStorage.multiRemove([
      TOKEN_KEY,
      PENDING_VERIFICATION_KEY,
      REGISTRATION_SUMMARY_KEY,
    ]);
    setToken(null);
    setPendingVerification(false);
    setRegistrationSummary(null);
  }, []);

  const value = useMemo(
    () => ({
      token,
      initializing,
      error,
      setError,
      pendingVerification,
      registrationSummary,
      login,
      register,
      completeVerificationReview,
      logout,
    }),
    [
      token,
      initializing,
      error,
      pendingVerification,
      registrationSummary,
      login,
      register,
      completeVerificationReview,
      logout,
    ],
  );

  return (
    <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return ctx;
}
