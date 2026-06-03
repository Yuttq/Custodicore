import Ionicons from '@expo/vector-icons/Ionicons';
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
import { SafeAreaView } from 'react-native-safe-area-context';
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
import { useAuth } from '../hooks/useAuth';
import { validateEmail, validatePassword, validateRequired } from '../utils';

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
    if (!validateRequired(trimmedEmail)) {
      next.email = 'Email or phone number is required';
    } else if (trimmedEmail.includes('@') && !validateEmail(trimmedEmail)) {
      next.email = 'Enter a valid email';
    }
    if (!validateRequired(password)) next.password = 'Password is required';
    else if (!validatePassword(password)) {
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
  }, [email, password, login, submitting]);

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['left', 'right', 'bottom']}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.scroll}>
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

          <View style={commonStyles.pageIntro}>
            <Text style={commonStyles.pageTitle}>Welcome Back</Text>
            <Text style={commonStyles.pageSubtitle}>Sign in to your account</Text>
          </View>

          <Card style={styles.card}>
            <View style={formStyles.field}>
              <Text style={formStyles.label}>Email or Phone Number</Text>
              <TextInput
                value={email}
                onChangeText={setEmail}
                placeholder="Enter email or phone"
                placeholderTextColor={colors.textSecondary}
                keyboardType="email-address"
                autoCapitalize="none"
                autoCorrect={false}
                style={[formStyles.input, errors.email ? formStyles.inputError : null]}
              />
              {errors.email ? <Text style={formStyles.error}>{errors.email}</Text> : null}
            </View>

            <View style={formStyles.field}>
              <Text style={formStyles.label}>Password</Text>
              <View
                style={[formStyles.passwordRow, errors.password ? formStyles.inputError : null]}
              >
                <TextInput
                  value={password}
                  onChangeText={setPassword}
                  placeholder="••••••••"
                  placeholderTextColor={colors.textSecondary}
                  secureTextEntry={!showPassword}
                  style={formStyles.passwordInput}
                />
                <Pressable
                  onPress={() => setShowPassword((v) => !v)}
                  accessibilityRole="button"
                  accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
                  hitSlop={spacing.sm}
                  style={formStyles.iconButton}
                >
                  <Ionicons
                    name={showPassword ? 'eye-off-outline' : 'eye-outline'}
                    size={20}
                    color={colors.textSecondary}
                  />
                </Pressable>
              </View>
              {errors.password ? <Text style={formStyles.error}>{errors.password}</Text> : null}
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
  flex: { flex: 1 },
  scroll: {
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.sm,
    paddingBottom: spacing.xl,
  },
  card: {
    borderRadius: layout.cardRadius,
  },
  backButton: {
    alignSelf: 'flex-start',
    marginBottom: spacing.md,
  },
  forgotWrap: {
    alignSelf: 'flex-end',
    marginBottom: spacing.md,
    marginTop: spacing.xs,
  },
  forgot: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.primaryNavy,
  },
  secondaryWrap: {
    marginTop: spacing.md,
  },
  footer: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
    marginTop: spacing.lg,
    lineHeight: 20,
  },
});
