import AsyncStorage from '@react-native-async-storage/async-storage';
import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import { USE_MOCK_AUTH } from '../mock/devFlags';
import client, {
  getMe,
  getStoredToken,
  login as apiLogin,
  logout as apiLogout,
  persistToken,
  register as apiRegister,
  TOKEN_KEY,
  updateMe as apiUpdateMe,
} from '../services/api';
import { uploadGovernmentId } from '../repositories/verificationRepository';
import {
  authenticateWithGoogle,
  GoogleSignInCancelledError,
  GoogleSignInNotConfiguredError,
} from '../services/socialAuthHandlers';
import { signOutGoogle } from '../services/googleAuthService';

const PENDING_VERIFICATION_KEY = '@custodicore/pending_verification';
const REGISTRATION_SUMMARY_KEY = '@custodicore/registration_summary';
const USER_KEY = '@custodicore/auth_user';

const AuthContext = createContext(null);

/**
 * Map registration gender UI labels onto the Laravel contract.
 * "Prefer not to say" → prefer_not_to_say (backend stores NULL).
 * @param {string} gender
 */
function mapGenderForApi(gender) {
  const g = String(gender || '').trim().toLowerCase();
  if (!g) return undefined;
  if (g === 'prefer not to say') return 'prefer_not_to_say';
  if (g === 'male' || g === 'female' || g === 'other') return g;
  return g;
}

/**
 * Build POST /api/auth/register body from RegisterScreen payload.
 * Does NOT send PDL IDs or create visitor-PDL relationships.
 * @param {Record<string, unknown>} payload
 */
function buildRegisterBody(payload) {
  const body = {
    fullName: String(payload.fullName || '').trim(),
    email: String(payload.email || '').trim(),
    password: payload.password,
    password_confirmation: payload.password_confirmation || payload.password,
    dateOfBirth: payload.dateOfBirth || payload.birthdate,
    gender: mapGenderForApi(payload.gender),
    address: payload.address ? String(payload.address).trim() : undefined,
    contactNumber: payload.contactNumber
      ? String(payload.contactNumber).trim()
      : undefined,
    relationshipHint:
      payload.relationshipHint ||
      payload.relationshipLabel ||
      payload.relationship ||
      undefined,
    // Consent given on the first registration screen (Terms & Conditions +
    // Privacy Policy). The backend rejects registration without both.
    acceptedTerms: payload.acceptedTerms === true ? true : undefined,
    acceptedPrivacy: payload.acceptedPrivacy === true ? true : undefined,
    consentVersion: payload.consentVersion,
  };

  Object.keys(body).forEach((key) => {
    if (body[key] === undefined || body[key] === '') delete body[key];
  });

  return body;
}

function normalizeUser(raw) {
  if (!raw || typeof raw !== 'object') return null;
  return {
    id: raw.id != null ? String(raw.id) : null,
    email: raw.email ?? null,
    fullName: raw.fullName ?? raw.full_name ?? null,
    role: raw.role ?? null,
    verificationStatus: raw.verificationStatus ?? raw.verification_status ?? null,
    verifiedAt: raw.verifiedAt ?? null,
    dateOfBirth: raw.dateOfBirth ?? null,
    gender: raw.gender ?? null,
    address: raw.address ?? null,
    contactNumber: raw.contactNumber ?? null,
    emergencyContactName: raw.emergencyContactName ?? null,
    emergencyContactNumber: raw.emergencyContactNumber ?? null,
    relationshipHint: raw.relationshipHint ?? null,
  };
}

export function AuthProvider({ children }) {
  const [token, setToken] = useState(null);
  const [user, setUser] = useState(null);
  const [initializing, setInitializing] = useState(true);
  const [error, setError] = useState(null);
  const [pendingVerification, setPendingVerification] = useState(false);
  const [registrationSummary, setRegistrationSummary] = useState(null);
  const clearingRef = useRef(false);

  const clearLocalSession = useCallback(async () => {
    if (clearingRef.current) return;
    clearingRef.current = true;
    try {
      await AsyncStorage.multiRemove([
        TOKEN_KEY,
        PENDING_VERIFICATION_KEY,
        REGISTRATION_SUMMARY_KEY,
        USER_KEY,
      ]);
      setToken(null);
      setUser(null);
      setPendingVerification(false);
      setRegistrationSummary(null);
    } finally {
      clearingRef.current = false;
    }
  }, []);

  const applySession = useCallback(async (sessionToken, sessionUser, options = {}) => {
    const normalized = normalizeUser(sessionUser);
    await persistToken(sessionToken);
    if (normalized) {
      await AsyncStorage.setItem(USER_KEY, JSON.stringify(normalized));
    }

    const isPending =
      options.forcePendingVerification === true ||
      normalized?.verificationStatus === 'pending';

    if (isPending) {
      await AsyncStorage.setItem(PENDING_VERIFICATION_KEY, '1');
    } else {
      await AsyncStorage.removeItem(PENDING_VERIFICATION_KEY);
    }

    setToken(sessionToken);
    setUser(normalized);
    setPendingVerification(isPending);
  }, []);

  // Restore session on launch: token → GET /api/me
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const [storedToken, pending, summaryJson, userJson] = await Promise.all([
          getStoredToken(),
          AsyncStorage.getItem(PENDING_VERIFICATION_KEY),
          AsyncStorage.getItem(REGISTRATION_SUMMARY_KEY),
          AsyncStorage.getItem(USER_KEY),
        ]);

        if (cancelled) return;

        if (summaryJson) {
          try {
            setRegistrationSummary(JSON.parse(summaryJson));
          } catch {
            setRegistrationSummary(null);
          }
        }

        if (!storedToken) {
          setToken(null);
          setUser(null);
          setPendingVerification(false);
          return;
        }

        if (USE_MOCK_AUTH) {
          setToken(storedToken);
          setPendingVerification(pending === '1');
          if (userJson) {
            try {
              setUser(JSON.parse(userJson));
            } catch {
              setUser(null);
            }
          }
          return;
        }

        try {
          const me = await getMe();
          if (cancelled) return;
          await applySession(storedToken, me);
        } catch (e) {
          if (cancelled) return;
          if (e?.status === 401 || e?.status === 403) {
            await clearLocalSession();
          } else {
            // Network blip: keep token, hydrate cached user if available
            setToken(storedToken);
            setPendingVerification(pending === '1');
            if (userJson) {
              try {
                setUser(JSON.parse(userJson));
              } catch {
                setUser(null);
              }
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
  }, [applySession, clearLocalSession]);

  // Clear local session on 401 from any authenticated request
  useEffect(() => {
    const id = client.interceptors.response.use(
      (response) => response,
      async (error) => {
        if (error?.response?.status === 401 && token) {
          await clearLocalSession();
        }
        return Promise.reject(error);
      },
    );
    return () => client.interceptors.response.eject(id);
  }, [token, clearLocalSession]);

  const login = useCallback(
    async (email, password) => {
      setError(null);
      try {
        if (USE_MOCK_AUTH) {
          await new Promise((r) => setTimeout(r, 350));
          if (!String(email || '').trim() || !password) {
            throw new Error('Please enter your email address and password.');
          }
          await applySession('placeholder-token', {
            id: 'mock',
            email,
            fullName: 'Mock Visitor',
            role: 'Visitor',
            verificationStatus: 'verified',
          });
          return;
        }

        const data = await apiLogin(email, password);
        if (!data?.token) {
          throw new Error('Login succeeded but no session token was returned.');
        }
        await applySession(data.token, data.user);
        // Refresh from /me when login payload is thin
        try {
          const me = await getMe();
          await applySession(data.token, me);
        } catch {
          // login user payload is enough
        }
      } catch (e) {
        const message = e?.message ?? 'Login failed';
        setError(message);
        throw e;
      }
    },
    [applySession],
  );

  /**
   * Native Google Sign-In → POST /auth/google.
   * - authenticated: session applied; navigation follows from `token`.
   * - registration_required: no session exists — returns
   *   `{ status, profile, consentVersion }` for the caller to route to Register.
   * - cancelled: returns null.
   * Backend rejections are rethrown with `status`/`code` intact.
   */
  const loginWithGoogle = useCallback(async () => {
    setError(null);
    try {
      const result = await authenticateWithGoogle();
      if (result.status === 'authenticated') {
        await applySession(result.token, result.user);
      }
      return result;
    } catch (e) {
      if (e instanceof GoogleSignInCancelledError) {
        return null;
      }
      const message =
        e instanceof GoogleSignInNotConfiguredError
          ? e.message
          : (e?.message ?? 'Google Sign-In failed');
      setError(message);
      throw e;
    }
  }, [applySession]);

  const register = useCallback(
    async (payload) => {
      setError(null);
      try {
        const summary = {
          fullName: payload?.fullName,
          relationship: payload?.relationship,
          relationshipLabel: payload?.relationshipLabel,
          documents: payload?.documentsSummary ?? [],
        };

        if (USE_MOCK_AUTH) {
          await new Promise((r) => setTimeout(r, 350));
          await AsyncStorage.multiSet([
            [TOKEN_KEY, 'placeholder-token'],
            [PENDING_VERIFICATION_KEY, '1'],
            [REGISTRATION_SUMMARY_KEY, JSON.stringify(summary)],
          ]);
          setRegistrationSummary(summary);
          setPendingVerification(true);
          setToken('placeholder-token');
          setUser({
            id: 'mock',
            email: payload?.email ?? null,
            fullName: payload?.fullName ?? null,
            role: 'Visitor',
            verificationStatus: 'pending',
          });
          return;
        }

        const body = buildRegisterBody(payload);
        const data = await apiRegister(body);
        if (!data?.token) {
          throw new Error('Registration succeeded but no session token was returned.');
        }

        // The account exists now — upload the government ID picked during
        // registration (POST /api/documents, stored as pending). Relationship
        // documents can't be uploaded yet: staff must first link the visitor
        // to a PDL. A failed upload does not undo registration; the visitor
        // can retry from Verification Documents.
        await persistToken(data.token);
        const governmentId = payload?.documents?.government_id;
        if (governmentId?.uri) {
          try {
            await uploadGovernmentId({
              uri: governmentId.uri,
              fileName: governmentId.fileName,
              documentType: governmentId.idType,
            });
          } catch {
            summary.documents = summary.documents.map((doc) =>
              doc.label === 'Government ID'
                ? { ...doc, detail: 'Upload failed — re-upload from Verification Documents' }
                : doc,
            );
          }
        }

        await AsyncStorage.setItem(REGISTRATION_SUMMARY_KEY, JSON.stringify(summary));
        setRegistrationSummary(summary);

        await applySession(data.token, data.user, {
          forcePendingVerification: true,
        });
      } catch (e) {
        const message = e?.message ?? 'Registration failed';
        setError(message);
        throw e;
      }
    },
    [applySession],
  );

  /** Stores a fresh /me payload without touching token or pendingVerification. */
  const storeUser = useCallback(async (rawUser) => {
    const normalized = normalizeUser(rawUser);
    if (!normalized) return null;
    await AsyncStorage.setItem(USER_KEY, JSON.stringify(normalized));
    setUser(normalized);
    return normalized;
  }, []);

  /** Re-reads GET /api/me (profile + verification status). */
  const refreshUser = useCallback(async () => {
    if (USE_MOCK_AUTH || !token) return user;
    return storeUser(await getMe());
  }, [token, user, storeUser]);

  /** PATCH /api/me with editable fields; returns the updated user. */
  const updateProfile = useCallback(
    async (fields) => storeUser(await apiUpdateMe(fields)),
    [storeUser],
  );

  const completeVerificationReview = useCallback(async () => {
    await AsyncStorage.removeItem(PENDING_VERIFICATION_KEY);
    setPendingVerification(false);
  }, []);

  const logout = useCallback(async () => {
    setError(null);
    try {
      if (!USE_MOCK_AUTH && token) {
        try {
          await apiLogout();
        } catch {
          // Token may already be invalid — still clear local session.
        }
      }
      // Best effort: clears the native Google session (no-op when unavailable).
      await signOutGoogle().catch(() => {});
    } finally {
      await clearLocalSession();
    }
  }, [token, clearLocalSession]);

  const value = useMemo(
    () => ({
      token,
      user,
      initializing,
      error,
      setError,
      pendingVerification,
      registrationSummary,
      login,
      loginWithGoogle,
      register,
      completeVerificationReview,
      logout,
      clearLocalSession,
      refreshUser,
      updateProfile,
    }),
    [
      refreshUser,
      updateProfile,
      token,
      user,
      initializing,
      error,
      pendingVerification,
      registrationSummary,
      login,
      loginWithGoogle,
      register,
      completeVerificationReview,
      logout,
      clearLocalSession,
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
