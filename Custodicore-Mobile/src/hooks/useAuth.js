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
  linkGoogleAccount as apiLinkGoogleAccount,
  login as apiLogin,
  logout as apiLogout,
  persistToken,
  register as apiRegister,
  registerWithGoogle as apiRegisterWithGoogle,
  resendVerificationEmail as apiResendVerificationEmail,
  TOKEN_KEY,
  updateMe as apiUpdateMe,
} from '../services/api';
import {
  appendRegistrationGovernmentId,
  uploadGovernmentId,
} from '../repositories/verificationRepository';
import {
  authenticateWithGoogle,
  getFreshGoogleRegistrationToken,
  GoogleSignInCancelledError,
  GoogleSignInNotConfiguredError,
} from '../services/socialAuthHandlers';
import { signOutGoogle } from '../services/googleAuthService';

const PENDING_VERIFICATION_KEY = '@custodicore/pending_verification';
const REGISTRATION_SUMMARY_KEY = '@custodicore/registration_summary';
const USER_KEY = '@custodicore/auth_user';

const AuthContext = createContext(null);

/**
 * /auth/google/link rejections after which the held Google ID token is
 * useless (or must not be retried): the visitor starts over from Login.
 * `invalid_password`, `google_unavailable`, 429 and network errors keep it
 * for another try.
 */
const UNRECOVERABLE_LINK_CODES = new Set([
  'invalid_google_token',
  'google_account_mismatch',
  'not_visitor_account',
  'account_inactive',
  'account_not_found',
]);

/**
 * Google registration failures after which the held Google ID token is
 * dropped and the visitor starts over from Login. Validation (422), 409
 * conflicts, `google_unavailable`, 429 and network errors keep it so the
 * visitor can fix the form or retry.
 */
const UNRECOVERABLE_GOOGLE_REGISTRATION_CODES = new Set([
  'google_registration_expired',
  'google_session_expired',
  'google_email_mismatch',
  'invalid_google_token',
  'google_account_mismatch',
  'link_required',
  'not_visitor_account',
  'account_inactive',
]);

/**
 * Map registration gender UI labels (Male / Female) onto the Laravel
 * contract (male / female — the only values the backend accepts).
 * @param {string} gender
 */
function mapGenderForApi(gender) {
  const g = String(gender || '').trim().toLowerCase();
  return g || undefined;
}

/**
 * Build POST /api/auth/register body from RegisterScreen payload.
 * Does NOT send PDL IDs or create visitor-PDL relationships.
 * @param {Record<string, unknown>} payload
 */
function buildRegisterBody(payload) {
  const body = {
    fullName: String(payload.fullName || '').trim(),
    // Phase 1 First/Last Name, stored separately as well as in fullName.
    firstName: payload.firstName ? String(payload.firstName).trim() : undefined,
    lastName: payload.lastName ? String(payload.lastName).trim() : undefined,
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

/**
 * Build POST /api/auth/google/register body: the same registration fields as
 * buildRegisterBody() minus email (the backend reads it from the verified
 * Google ID token), plus the fresh ID token.
 * @param {Record<string, unknown>} payload
 * @param {string} idToken
 */
function buildGoogleRegisterBody(payload, idToken) {
  const body = buildRegisterBody(payload);
  delete body.email;
  return { ...body, idToken };
}

/**
 * POST /api/auth/register body. With a government ID picked during
 * registration this is multipart (the visitor gets no token until they
 * verify their email, so it cannot be uploaded afterwards); otherwise JSON.
 * @param {Record<string, unknown>} payload
 */
function buildRegisterRequest(payload) {
  const body = buildRegisterBody(payload);
  const governmentId = payload?.documents?.government_id;
  if (!governmentId?.uri) return body;

  const form = new FormData();
  Object.entries(body).forEach(([key, value]) => {
    form.append(key, String(value));
  });
  appendRegistrationGovernmentId(form, governmentId);
  return form;
}

/** Local "what you submitted" summary shown on the verification screen. */
function buildRegistrationSummary(payload) {
  return {
    fullName: payload?.fullName,
    relationship: payload?.relationship,
    relationshipLabel: payload?.relationshipLabel,
    documents: payload?.documentsSummary ?? [],
  };
}

function normalizeUser(raw) {
  if (!raw || typeof raw !== 'object') return null;
  return {
    id: raw.id != null ? String(raw.id) : null,
    email: raw.email ?? null,
    fullName: raw.fullName ?? raw.full_name ?? null,
    role: raw.role ?? null,
    // Email ownership and staff review are separate backend states.
    emailVerified: raw.emailVerified ?? raw.email_verified ?? null,
    verificationStatus: raw.verificationStatus ?? raw.verification_status ?? null,
    verifiedAt: raw.verifiedAt ?? null,
    rejectionReason: raw.rejectionReason ?? null,
    firstName: raw.firstName ?? null,
    lastName: raw.lastName ?? null,
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
  // Google ID token held between /auth/google (link_required) and
  // /auth/google/link. Memory only: never persisted, logged, put in state
  // or navigation params, or returned to screens.
  const pendingGoogleLinkRef = useRef(null);
  // Google ID token + verified email held between /auth/google
  // (registration_required) and /auth/google/register. Same rules as above:
  // memory only, never handed to screens. The email is used to check that a
  // refreshed token belongs to the same Google account.
  const pendingGoogleRegistrationRef = useRef(null);

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
   * - registration_required: no session exists — the ID token is held in
   *   memory for registerWithGoogle(); returns only
   *   `{ status, profile: { email, fullName }, consentVersion }` for Register.
   * - link_required: no session exists — the ID token is held in memory for
   *   linkGoogle(); returns only `{ status, email }` (email for display).
   * - cancelled: returns null.
   * Backend rejections are rethrown with `status`/`code` intact.
   */
  const loginWithGoogle = useCallback(async () => {
    setError(null);
    // A new Google attempt always discards any earlier pending link/registration.
    pendingGoogleLinkRef.current = null;
    pendingGoogleRegistrationRef.current = null;
    try {
      const result = await authenticateWithGoogle();
      if (result.status === 'authenticated') {
        await applySession(result.token, result.user);
      }
      if (result.status === 'link_required') {
        pendingGoogleLinkRef.current = { idToken: result.idToken };
        return { status: 'link_required', email: result.email };
      }
      if (result.status === 'registration_required') {
        pendingGoogleRegistrationRef.current = {
          idToken: result.idToken,
          email: result.profile.email,
        };
        return {
          status: 'registration_required',
          profile: { email: result.profile.email, fullName: result.profile.fullName },
          consentVersion: result.consentVersion,
        };
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

  /** True while a Google ID token from link_required is held for linking. */
  const hasPendingGoogleLink = useCallback(() => pendingGoogleLinkRef.current !== null, []);

  /**
   * POST /auth/google/link with the held Google ID token + the visitor's
   * Custodicore password. On success the session is applied like login()
   * and the navigator switches on `token`. Errors are rethrown with
   * `status`/`code`; unrecoverable ones also drop the held token.
   * @param {string} password
   */
  const linkGoogle = useCallback(
    async (password) => {
      setError(null);
      const pending = pendingGoogleLinkRef.current;
      if (!pending) {
        const err = new Error('Your Google sign-in has expired. Please sign in with Google again.');
        err.code = 'google_link_expired';
        throw err;
      }

      try {
        const data = await apiLinkGoogleAccount({ idToken: pending.idToken, password });
        if (data?.status !== 'authenticated' || !data?.token) {
          throw new Error('Linking succeeded but no session token was returned.');
        }
        pendingGoogleLinkRef.current = null;
        await applySession(data.token, data.user);
      } catch (e) {
        if (UNRECOVERABLE_LINK_CODES.has(e?.code)) {
          pendingGoogleLinkRef.current = null;
        }
        throw e;
      }
    },
    [applySession],
  );

  /**
   * Abandons a pending Google link (back/cancel/leaving the screen): drops
   * the held ID token and clears the native Google session so the account
   * picker shows next time. Never touches a CustodiCore session.
   */
  const cancelGoogleFlow = useCallback(() => {
    if (!pendingGoogleLinkRef.current) return;
    pendingGoogleLinkRef.current = null;
    signOutGoogle().catch(() => {});
  }, []);

  /**
   * Finishes a successful Google registration (password registration never
   * signs in — see register()): the account exists now — upload the
   * government ID picked during registration
   * (POST /api/documents, stored as pending), store the summary, then apply
   * the session so the navigator switches to the app. Relationship documents
   * can't be uploaded yet: staff must first link the visitor to a PDL. A
   * failed upload does not undo registration; the visitor can retry from
   * Verification Documents.
   */
  const completeRegistration = useCallback(
    async (sessionToken, sessionUser, payload, summary) => {
      await persistToken(sessionToken);
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

      await applySession(sessionToken, sessionUser, {
        forcePendingVerification: true,
      });
    },
    [applySession],
  );

  /** True while a Google ID token from registration_required is held. */
  const hasPendingGoogleRegistration = useCallback(
    () => pendingGoogleRegistrationRef.current !== null,
    [],
  );

  /**
   * POST /auth/google/register for a visitor whose Google Sign-In answered
   * registration_required. `payload` is the RegisterScreen form (same shape
   * as register(), without email) — the Google ID token never comes from or
   * goes to the screen.
   *
   * Before submitting, a fresh ID token is obtained silently (the held one
   * may have expired during the multi-step form) and must belong to the same
   * Google account; the stale token is never submitted. On success the
   * session is applied exactly like register(). Errors are rethrown with
   * `status`/`code`; unrecoverable ones also end the Google flow.
   * @param {Record<string, unknown>} payload
   */
  const registerWithGoogle = useCallback(
    async (payload) => {
      setError(null);
      const pending = pendingGoogleRegistrationRef.current;
      if (!pending) {
        const err = new Error('Your Google sign-in has expired. Please sign in with Google again.');
        err.code = 'google_registration_expired';
        throw err;
      }

      try {
        const idToken = await getFreshGoogleRegistrationToken(pending.email);
        const data = await apiRegisterWithGoogle(buildGoogleRegisterBody(payload, idToken));
        if (data?.status !== 'authenticated' || !data?.token) {
          throw new Error('Registration succeeded but no session token was returned.');
        }
        pendingGoogleRegistrationRef.current = null;
        await completeRegistration(
          data.token,
          data.user,
          payload,
          buildRegistrationSummary(payload),
        );
      } catch (e) {
        if (UNRECOVERABLE_GOOGLE_REGISTRATION_CODES.has(e?.code)) {
          pendingGoogleRegistrationRef.current = null;
          signOutGoogle().catch(() => {});
        }
        setError(e?.message ?? 'Registration failed');
        throw e;
      }
    },
    [completeRegistration],
  );

  /**
   * Abandons a pending Google registration (back/cancel/leaving the screen):
   * drops the held ID token and clears the native Google session. No account
   * or CustodiCore session exists at this point, so nothing else to undo.
   */
  const cancelGoogleRegistration = useCallback(() => {
    if (!pendingGoogleRegistrationRef.current) return;
    pendingGoogleRegistrationRef.current = null;
    signOutGoogle().catch(() => {});
  }, []);

  /**
   * POST /api/auth/register (password registration). Creates the account
   * and sends the government ID with it, but does NOT sign in: the visitor
   * must verify their email first, then log in. No token or session is
   * stored. Returns `{ email, message }` for the "Check your email" screen.
   */
  const register = useCallback(async (payload) => {
    setError(null);
    try {
      if (USE_MOCK_AUTH) {
        await new Promise((r) => setTimeout(r, 350));
        return { email: payload?.email ?? null, message: null };
      }

      const data = await apiRegister(buildRegisterRequest(payload));
      if (data?.status !== 'verification_required') {
        throw new Error('Registration could not be confirmed. Please try logging in.');
      }

      const summary = buildRegistrationSummary(payload);
      await AsyncStorage.setItem(REGISTRATION_SUMMARY_KEY, JSON.stringify(summary));
      setRegistrationSummary(summary);

      return { email: data.email ?? payload?.email ?? null, message: data.message ?? null };
    } catch (e) {
      const message = e?.message ?? 'Registration failed';
      setError(message);
      throw e;
    }
  }, []);

  /**
   * POST /api/auth/email/resend. The backend replies the same way for any
   * address (no account enumeration); errors keep `status` (429 when limited).
   * @param {string} email
   */
  const resendVerificationEmail = useCallback(async (email) => {
    if (USE_MOCK_AUTH) return { message: null };
    return apiResendVerificationEmail(String(email || '').trim());
  }, []);

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

  // Staff approved the visitor's information/documents. Backend truth from
  // /me — the API refuses visit features otherwise (EnsureVisitorApproved).
  const isApprovedVisitor = user?.verificationStatus === 'verified';

  const value = useMemo(
    () => ({
      token,
      user,
      isApprovedVisitor,
      initializing,
      error,
      setError,
      pendingVerification,
      registrationSummary,
      login,
      loginWithGoogle,
      linkGoogle,
      cancelGoogleFlow,
      hasPendingGoogleLink,
      registerWithGoogle,
      cancelGoogleRegistration,
      hasPendingGoogleRegistration,
      register,
      resendVerificationEmail,
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
      isApprovedVisitor,
      resendVerificationEmail,
      initializing,
      error,
      pendingVerification,
      registrationSummary,
      login,
      loginWithGoogle,
      linkGoogle,
      cancelGoogleFlow,
      hasPendingGoogleLink,
      registerWithGoogle,
      cancelGoogleRegistration,
      hasPendingGoogleRegistration,
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
