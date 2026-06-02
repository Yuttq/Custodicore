import React, { useCallback, useState } from 'react';
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
import Ionicons from '@expo/vector-icons/Ionicons';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, Card, colors, layout, spacing, typography } from '../designSystem';
import { useAuth } from '../hooks/useAuth';
import { validateEmail, validatePassword, validateRequired } from '../utils';

/**
 * Visitor sign-in. On success, `login()` in `useAuth` writes a mock token to
 * AsyncStorage and updates auth state; the root navigator then shows the main
 * app with **Dashboard** as the first tab (no manual `navigate('Dashboard')` needed).
 */
export default function LoginScreen({ navigation }) {
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [errors, setErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);

  const onSubmit = useCallback(async () => {
    if (submitting) return;

    const trimmedEmail = String(email || '').trim();
    const next = {};
    if (!validateRequired(trimmedEmail))
      next.email = 'Email or phone number is required';
    else if (trimmedEmail.includes('@') && !validateEmail(trimmedEmail))
      next.email = 'Enter a valid email';
    if (!validateRequired(password)) next.password = 'Password is required';
    else if (!validatePassword(password))
      next.password = 'Password must be at least 6 characters';
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
  }, [email, password, login, submitting]);

  return (
    <SafeAreaView style={styles.safe} edges={['left', 'right', 'bottom']}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.scroll}>
          <Pressable
            onPress={() => (navigation.canGoBack() ? navigation.goBack() : navigation.navigate('Splash'))}
            accessibilityRole="button"
            accessibilityLabel="Go back"
            hitSlop={10}
            style={styles.backButton}
          >
            <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
          </Pressable>

          <View style={styles.header}>
            <Text style={styles.title}>Welcome Back</Text>
            <Text style={styles.subtitle}>Sign in to your account</Text>
          </View>

          <Card style={styles.card}>
            <View style={styles.field}>
              <Text style={styles.label}>Email or Phone Number</Text>
              <TextInput
                value={email}
                onChangeText={setEmail}
                placeholder="Enter email or phone"
                placeholderTextColor={colors.textSecondary}
                keyboardType="email-address"
                autoCapitalize="none"
                autoCorrect={false}
                style={[styles.input, errors.email ? styles.inputError : null]}
              />
              {errors.email ? <Text style={styles.error}>{errors.email}</Text> : null}
            </View>

            <View style={styles.field}>
              <Text style={styles.label}>Password</Text>
              <View style={[styles.passwordRow, errors.password ? styles.inputError : null]}>
                <TextInput
                  value={password}
                  onChangeText={setPassword}
                  placeholder="••••••••"
                  placeholderTextColor={colors.textSecondary}
                  secureTextEntry={!showPassword}
                  style={[styles.passwordInput]}
                />
                <Pressable
                  onPress={() => setShowPassword((v) => !v)}
                  accessibilityRole="button"
                  accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
                  hitSlop={10}
                  style={styles.eye}
                >
                  <Ionicons
                    name={showPassword ? 'eye-off-outline' : 'eye-outline'}
                    size={20}
                    color={colors.textSecondary}
                  />
                </Pressable>
              </View>
              {errors.password ? <Text style={styles.error}>{errors.password}</Text> : null}
            </View>

            <Pressable
              onPress={() => navigation.navigate('ForgotPassword')}
              accessibilityRole="link"
              accessibilityLabel="Forgot password"
              style={styles.forgotWrap}
            >
              <Text style={styles.forgot}>Forgot Password?</Text>
            </Pressable>

            <Button
              title="Sign In"
              onPress={onSubmit}
              loading={submitting}
              disabled={submitting}
              accessibilityLabel="Sign in to CustodiCore"
            />

            <View style={styles.secondaryWrap}>
              <Button
                variant="secondary"
                title="Create New Account"
                onPress={() => navigation.navigate('Register')}
                accessibilityLabel="Create a new account"
              />
            </View>
          </Card>

          <Text style={styles.footer}>
            Your data is protected and kept confidential.
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  flex: { flex: 1 },
  scroll: {
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.sm,
    paddingBottom: spacing.xl,
  },
  backButton: {
    width: 44,
    height: 44,
    borderRadius: 22,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    alignSelf: 'flex-start',
    marginBottom: spacing.md,
  },
  header: {
    marginBottom: layout.pageTitleGap,
  },
  title: {
    ...typography.pageTitle,
    color: colors.textPrimary,
  },
  subtitle: {
    ...typography.metadata,
    color: colors.textSecondary,
    marginTop: spacing.sm,
  },
  card: {
    borderRadius: layout.cardRadius,
  },
  field: { marginBottom: spacing.md },
  label: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  input: {
    height: 48,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 12,
    paddingHorizontal: spacing.sm,
    backgroundColor: colors.white,
    color: colors.textPrimary,
    ...typography.body,
  },
  passwordRow: {
    height: 48,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 12,
    paddingLeft: spacing.sm,
    paddingRight: spacing.sm,
    backgroundColor: colors.white,
    flexDirection: 'row',
    alignItems: 'center',
  },
  passwordInput: {
    flex: 1,
    color: colors.textPrimary,
    ...typography.body,
  },
  eye: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
  },
  inputError: { borderColor: colors.danger },
  error: {
    ...typography.metadata,
    color: colors.danger,
    marginTop: spacing.sm,
  },
  forgotWrap: {
    alignItems: 'flex-end',
    marginTop: spacing.xs,
    marginBottom: spacing.md,
  },
  forgot: {
    ...typography.metadata,
    color: colors.primaryNavy,
    fontWeight: '600',
  },
  secondaryWrap: { marginTop: spacing.md },
  footer: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
    marginTop: spacing.lg,
  },
});
