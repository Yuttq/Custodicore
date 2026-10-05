import { Image as ExpoImage } from 'expo-image';
import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useMemo, useState } from 'react';
import {
  Alert,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import AuthDivider from '../components/AuthDivider';
import GoogleSignInButton from '../components/GoogleSignInButton';
import {
  Button,
  Card,
  colors,
  commonStyles,
  formStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { isGoogleSignInConfigured } from '../config/authConfig';
import { useAuth } from '../hooks/useAuth';
import { GoogleSignInCancelledError } from '../services/socialAuthHandlers';
import { validateEmail, validatePassword, validateRequired } from '../utils';

const LOGO_ASPECT = 819 / 1024;
const CONTENT_MAX_WIDTH = 440;

/** Fixed for the life of the binary — false in Expo Go and without a Web Client ID. */
const GOOGLE_SIGN_IN_AVAILABLE = isGoogleSignInConfigured();

/** Backend `code` → visitor-facing copy. Raw server text is never shown for these. */
const GOOGLE_ERROR_MESSAGES = {
  not_visitor_account:
    'Google sign-in is only available for visitor accounts.',
  account_inactive: 'This account is not active. Contact facility staff.',
  invalid_google_token: 'Google Sign-In failed. Please try again.',
  google_unavailable:
    'Google Sign-In is temporarily unavailable. Please sign in with your email and password.',
};

function googleErrorMessage(e) {
  if (e?.code && GOOGLE_ERROR_MESSAGES[e.code]) return GOOGLE_ERROR_MESSAGES[e.code];
  if (e?.status === 429) return 'Too many sign-in attempts. Please wait a minute and try again.';
  return typeof e?.message === 'string' && e.message.trim()
    ? e.message
    : 'Google Sign-In is not available. Please sign in with your email and password.';
}

export default function LoginScreen({ navigation }) {
  const { width: windowWidth } = useWindowDimensions();
  const { login, loginWithGoogle } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [errors, setErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);
  const [googleSubmitting, setGoogleSubmitting] = useState(false);

  const logoSize = useMemo(() => {
    const width = Math.min(windowWidth * 0.44, 168);
    return { width, height: width / LOGO_ASPECT };
  }, [windowWidth]);

  const clearEmailError = useCallback(() => {
    setErrors((prev) => {
      if (!prev.email) return prev;
      const next = { ...prev };
      delete next.email;
      return next;
    });
  }, []);

  const clearPasswordError = useCallback(() => {
    setErrors((prev) => {
      if (!prev.password) return prev;
      const next = { ...prev };
      delete next.password;
      return next;
    });
  }, []);

  const onSubmit = useCallback(async () => {
    if (submitting || googleSubmitting) return;

    const trimmedEmail = String(email || '').trim();
    const next = {};
    if (!validateRequired(trimmedEmail)) {
      next.email = 'Email address is required';
    } else if (!validateEmail(trimmedEmail)) {
      next.email = 'Enter a valid email address';
    }
    if (!validateRequired(password)) {
      next.password = 'Password is required';
    } else if (!validatePassword(password)) {
      next.password = 'Password must be at least 6 characters';
    }
    setErrors(next);
    if (Object.keys(next).length) return;

    setSubmitting(true);
    try {
      await login(trimmedEmail, password);
    } catch (e) {
      const message =
        typeof e?.message === 'string' && e.message.trim()
          ? e.message
          : 'Check your details and try again.';
      Alert.alert('Login failed', message);
    } finally {
      setSubmitting(false);
    }
  }, [email, password, login, submitting, googleSubmitting]);

  const onGoogleSignIn = useCallback(async () => {
    if (submitting || googleSubmitting) return;

    setGoogleSubmitting(true);
    try {
      const result = await loginWithGoogle();
      // authenticated → AuthProvider's token switches the navigator; null → cancelled.
      if (result?.status === 'link_required') {
        // Display email only — the Google ID token stays inside useAuth.
        navigation.navigate('LinkGoogleAccount', { email: result.email });
      } else if (result?.status === 'registration_required') {
        // Display profile only — the Google ID token stays inside useAuth.
        // push (not navigate) so Google registration always starts from a
        // fresh form, never a half-filled normal registration left in the stack.
        navigation.push('Register', {
          googleProfile: {
            email: result.profile.email,
            fullName: result.profile.fullName,
          },
        });
      }
    } catch (e) {
      if (e instanceof GoogleSignInCancelledError) return;
      Alert.alert('Google Sign-In', googleErrorMessage(e));
    } finally {
      setGoogleSubmitting(false);
    }
  }, [loginWithGoogle, navigation, submitting, googleSubmitting]);

  const authBusy = submitting || googleSubmitting;

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['left', 'right', 'bottom']}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView
          keyboardShouldPersistTaps="handled"
          contentContainerStyle={styles.scroll}
          showsVerticalScrollIndicator={false}
        >
          <View style={styles.contentColumn}>
            <Pressable
              onPress={() =>
                navigation.canGoBack() ? navigation.goBack() : navigation.navigate('Splash')
              }
              accessibilityRole="button"
              accessibilityLabel="Go back"
              hitSlop={spacing.sm}
              style={[commonStyles.backButton, styles.backButton]}
            >
              <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
            </Pressable>

            <View style={styles.brandingSection}>
              <ExpoImage
                source={require('../../assets/custodicore-logo.png')}
                style={[styles.logo, logoSize]}
                contentFit="contain"
                accessibilityLabel="CustodiCore"
              />
              <Text style={styles.welcomeTitle} accessibilityRole="header">
                Welcome Back
              </Text>
              <Text style={styles.welcomeSubtitle}>Sign in to your account</Text>
            </View>

            <Card style={styles.formCard}>
              <View style={styles.field}>
                <Text style={formStyles.label} nativeID="login-email-label">
                  Email Address
                </Text>
                <TextInput
                  value={email}
                  onChangeText={(value) => {
                    setEmail(value);
                    clearEmailError();
                  }}
                  placeholder="Enter your email"
                  placeholderTextColor={colors.textSecondary}
                  keyboardType="email-address"
                  autoCapitalize="none"
                  autoCorrect={false}
                  textContentType="emailAddress"
                  autoComplete="email"
                  accessibilityLabel="Email address"
                  accessibilityLabelledBy="login-email-label"
                  style={[formStyles.input, errors.email ? formStyles.inputError : null]}
                />
                {errors.email ? (
                  <Text style={formStyles.error} accessibilityRole="alert">
                    {errors.email}
                  </Text>
                ) : null}
              </View>

              <View style={styles.passwordBlock}>
                <Text style={formStyles.label} nativeID="login-password-label">
                  Password
                </Text>
                <View
                  style={[
                    styles.passwordRow,
                    errors.password ? styles.passwordRowError : null,
                  ]}
                >
                  <TextInput
                    value={password}
                    onChangeText={(value) => {
                      setPassword(value);
                      clearPasswordError();
                    }}
                    placeholder="Enter your password"
                    placeholderTextColor={colors.textSecondary}
                    secureTextEntry={!showPassword}
                    textContentType="password"
                    autoComplete="password"
                    accessibilityLabel="Password"
                    accessibilityLabelledBy="login-password-label"
                    style={styles.passwordInput}
                  />
                  <Pressable
                    onPress={() => setShowPassword((v) => !v)}
                    accessibilityRole="button"
                    accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
                    hitSlop={spacing.sm}
                    style={styles.passwordToggle}
                  >
                    <Ionicons
                      name={showPassword ? 'eye-off-outline' : 'eye-outline'}
                      size={22}
                      color={colors.textSecondary}
                    />
                  </Pressable>
                </View>
                {errors.password ? (
                  <Text style={formStyles.error} accessibilityRole="alert">
                    {errors.password}
                  </Text>
                ) : null}
                <Pressable
                  onPress={() => navigation.navigate('ForgotPassword')}
                  accessibilityRole="link"
                  accessibilityLabel="Forgot password"
                  style={styles.forgotWrap}
                >
                  <Text style={styles.forgot}>Forgot Password?</Text>
                </Pressable>
              </View>

              <Button
                title="Sign In"
                onPress={onSubmit}
                loading={submitting}
                disabled={authBusy}
                accessibilityLabel="Sign in to CustodiCore"
              />
            </Card>

            {GOOGLE_SIGN_IN_AVAILABLE ? (
              <View style={styles.altAuthSection}>
                <AuthDivider />
                <GoogleSignInButton
                  onPress={onGoogleSignIn}
                  loading={googleSubmitting}
                  disabled={authBusy}
                />
              </View>
            ) : null}

            <View style={styles.registerRow}>
              <Text style={styles.registerPrompt}>Don&apos;t have an account?</Text>
              <Pressable
                onPress={() => navigation.navigate('Register')}
                accessibilityRole="link"
                accessibilityLabel="Create account"
                hitSlop={spacing.sm}
                style={({ pressed }) => [styles.registerLinkWrap, pressed && styles.pressed]}
              >
                <Text style={styles.registerLink}>Create Account</Text>
              </Pressable>
            </View>

            <Text style={styles.footer}>Your information is securely protected.</Text>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  scroll: {
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.sm,
    paddingBottom: spacing.xl,
    flexGrow: 1,
  },
  contentColumn: {
    width: '100%',
    maxWidth: CONTENT_MAX_WIDTH,
    alignSelf: 'center',
  },
  backButton: {
    alignSelf: 'flex-start',
    marginBottom: spacing.md,
  },
  brandingSection: {
    alignItems: 'center',
    marginBottom: spacing.lg,
  },
  logo: {
    marginBottom: spacing.md,
  },
  welcomeTitle: {
    ...typography.pageTitle,
    color: colors.textPrimary,
    textAlign: 'center',
    marginBottom: spacing.sm,
  },
  welcomeSubtitle: {
    ...typography.body,
    color: colors.textSecondary,
    textAlign: 'center',
    lineHeight: 24,
  },
  formCard: {
    borderRadius: layout.cardRadius,
    padding: spacing.lg,
  },
  field: {
    marginBottom: spacing.md,
  },
  passwordBlock: {
    marginBottom: spacing.md,
  },
  passwordRow: {
    minHeight: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingLeft: spacing.md,
    paddingRight: spacing.xs,
    backgroundColor: colors.white,
    flexDirection: 'row',
    alignItems: 'center',
  },
  passwordRowError: {
    borderColor: colors.danger,
  },
  passwordInput: {
    flex: 1,
    minHeight: layout.buttonHeight,
    color: colors.textPrimary,
    ...typography.body,
    paddingVertical: spacing.sm,
    paddingRight: spacing.sm,
  },
  passwordToggle: {
    width: layout.iconButtonSize,
    height: layout.iconButtonSize,
    alignItems: 'center',
    justifyContent: 'center',
  },
  forgotWrap: {
    alignSelf: 'flex-end',
    marginTop: spacing.sm,
    minHeight: layout.iconButtonSize,
    justifyContent: 'center',
    paddingLeft: spacing.sm,
  },
  forgot: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.primaryNavy,
  },
  altAuthSection: {
    marginTop: spacing.lg,
  },
  registerRow: {
    alignItems: 'center',
    marginTop: spacing.lg,
    gap: spacing.sm,
  },
  registerPrompt: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
  },
  registerLinkWrap: {
    minHeight: layout.iconButtonSize,
    justifyContent: 'center',
    paddingHorizontal: spacing.sm,
  },
  registerLink: {
    ...typography.body,
    fontWeight: '700',
    color: colors.primaryTeal,
    textAlign: 'center',
  },
  pressed: {
    opacity: 0.88,
  },
  footer: {
    ...typography.metadata,
    fontSize: 12,
    lineHeight: 18,
    color: colors.textSecondary,
    textAlign: 'center',
    marginTop: spacing.lg,
    paddingHorizontal: spacing.md,
  },
});
