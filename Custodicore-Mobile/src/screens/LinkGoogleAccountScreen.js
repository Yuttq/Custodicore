import Ionicons from '@expo/vector-icons/Ionicons';
import GoogleGLogo from '../components/GoogleGLogo';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  Alert,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Button,
  Card,
  StackScreenHeader,
  colors,
  commonStyles,
  formStyles,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { useAuth } from '../hooks/useAuth';
import { validateRequired } from '../utils';

/**
 * Backend `code` → visitor-facing copy for rejections that end the linking
 * flow (useAuth drops the held Google token for these). Raw server text is
 * never shown for them.
 */
const RESTART_MESSAGES = {
  invalid_google_token: 'Your Google sign-in has expired. Please sign in with Google again.',
  google_link_expired: 'Your Google sign-in has expired. Please sign in with Google again.',
  google_account_mismatch:
    'This Google account is already linked to a CustodiCore account. Please use Sign in with Google.',
  not_visitor_account: 'This account cannot be linked to Google. Please sign in with your email and password.',
  account_inactive: 'This account is not active. Contact facility staff.',
  account_not_found:
    'No CustodiCore account was found for this Google account. Please sign in again.',
};

/**
 * Existing-account Google linking (Google Sign-In answered `link_required`):
 * the visitor confirms their CustodiCore password; useAuth sends it with the
 * Google ID token it holds in memory. The token never reaches this screen —
 * route params carry only the display email.
 *
 * Leaving the screen in any way (back, cancel, hardware back) cancels the
 * Google flow. On success the navigator switches on the new session.
 */
export default function LinkGoogleAccountScreen({ navigation, route }) {
  const { linkGoogle, cancelGoogleFlow, hasPendingGoogleLink } = useAuth();
  const email = route?.params?.email ?? null;
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [passwordError, setPasswordError] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const submittingRef = useRef(false);

  // Any exit from this screen discards the held Google token (no-op after
  // a successful link, which already cleared it).
  useEffect(() => () => cancelGoogleFlow(), [cancelGoogleFlow]);

  const backToLogin = useCallback(() => {
    cancelGoogleFlow();
    navigation.navigate('Login');
  }, [cancelGoogleFlow, navigation]);

  // Nothing to link (e.g. screen restored without a Google sign-in).
  useEffect(() => {
    if (!hasPendingGoogleLink()) {
      navigation.navigate('Login');
    }
  }, [hasPendingGoogleLink, navigation]);

  const onSubmit = useCallback(async () => {
    if (submittingRef.current) return;

    if (!validateRequired(password)) {
      setPasswordError('Password is required');
      return;
    }
    setPasswordError(null);

    submittingRef.current = true;
    setSubmitting(true);
    try {
      await linkGoogle(password);
      // Session applied — the navigator switches to the app.
    } catch (e) {
      if (e?.code === 'invalid_password') {
        setPasswordError('Incorrect password. Please try again.');
        return;
      }
      if (e?.code && RESTART_MESSAGES[e.code]) {
        Alert.alert('Link Google Account', RESTART_MESSAGES[e.code]);
        backToLogin();
        return;
      }
      if (e?.status === 429) {
        Alert.alert(
          'Link Google Account',
          'Too many attempts. Please wait a minute and try again.',
        );
        return;
      }
      if (e?.code === 'google_unavailable') {
        Alert.alert(
          'Link Google Account',
          'Google Sign-In is temporarily unavailable. Please try again in a moment.',
        );
        return;
      }
      Alert.alert(
        'Link Google Account',
        typeof e?.message === 'string' && e.message.trim()
          ? e.message
          : 'Unable to link your Google account. Please try again.',
      );
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
      setPassword('');
    }
  }, [password, linkGoogle, backToLogin]);

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['left', 'right', 'bottom']}>
      <StackScreenHeader title="Link Google Account" onBack={backToLogin} />
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={commonStyles.scrollContent}>
          <View style={styles.header}>
            <Text style={styles.subtitle}>
              This Google account matches an existing CustodiCore visitor account. Enter your
              CustodiCore password to link them. After that you can sign in with Google.
            </Text>
          </View>

          <Card style={styles.card}>
            {email ? (
              <View style={styles.field}>
                <Text style={formStyles.label}>Google Account</Text>
                <View style={styles.emailRow}>
                  <GoogleGLogo size={18} />
                  <Text style={styles.emailText} numberOfLines={1} accessibilityLabel={`Google account ${email}`}>
                    {email}
                  </Text>
                </View>
              </View>
            ) : null}

            <View style={styles.field}>
              <Text style={formStyles.label} nativeID="link-password-label">
                CustodiCore Password
              </Text>
              <View style={[styles.passwordRow, passwordError ? styles.passwordRowError : null]}>
                <TextInput
                  value={password}
                  onChangeText={(value) => {
                    setPassword(value);
                    if (passwordError) setPasswordError(null);
                  }}
                  placeholder="Enter your password"
                  placeholderTextColor={colors.textSecondary}
                  secureTextEntry={!showPassword}
                  textContentType="password"
                  autoComplete="password"
                  autoCapitalize="none"
                  autoCorrect={false}
                  editable={!submitting}
                  onSubmitEditing={onSubmit}
                  returnKeyType="done"
                  accessibilityLabel="CustodiCore password"
                  accessibilityLabelledBy="link-password-label"
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
              {passwordError ? (
                <Text style={formStyles.error} accessibilityRole="alert">
                  {passwordError}
                </Text>
              ) : null}
            </View>

            <Button
              title="Link Google Account"
              onPress={onSubmit}
              loading={submitting}
              disabled={submitting}
              accessibilityLabel="Link Google account"
            />
            <View style={styles.cancelWrap}>
              <Button
                title="Cancel"
                variant="secondary"
                onPress={backToLogin}
                disabled={submitting}
                accessibilityLabel="Cancel and return to sign in"
              />
            </View>
          </Card>

          <Text style={styles.footer}>Your information is securely protected.</Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  header: {
    marginBottom: layout.pageTitleGap,
  },
  subtitle: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.sm,
  },
  card: {
    borderRadius: layout.cardRadius,
  },
  field: {
    marginBottom: spacing.md,
  },
  emailRow: {
    minHeight: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingHorizontal: spacing.md,
    backgroundColor: colors.background,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  emailText: {
    ...typography.body,
    color: colors.textPrimary,
    flex: 1,
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
  cancelWrap: {
    marginTop: spacing.sm,
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
