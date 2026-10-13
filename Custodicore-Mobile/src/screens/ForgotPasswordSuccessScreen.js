import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, colors, layout, spacing, typography } from '../designSystem';
import {
  PASSWORD_RESET_GENERIC_MESSAGE,
  PASSWORD_RESET_RESEND_COOLDOWN_SECONDS,
  describePasswordResetError,
  resendPasswordReset,
} from '../services/forgotPasswordService';
import { cooldownAfterResendError } from '../utils/passwordResetCooldown';

/**
 * Shown after POST /api/auth/password/forgot succeeded. The message never
 * says whether the address is registered. The emailed link opens a web page
 * to choose a new password; the visitor then signs in here as usual.
 * Route params: `email`, `message` (the backend's generic message).
 */
export default function ForgotPasswordSuccessScreen({ navigation, route }) {
  const email = route.params?.email ?? '';
  const message = route.params?.message || PASSWORD_RESET_GENERIC_MESSAGE;
  const [resending, setResending] = useState(false);
  // A link was just requested: start in cooldown.
  const [cooldown, setCooldown] = useState(PASSWORD_RESET_RESEND_COOLDOWN_SECONDS);
  const inFlight = useRef(false);

  useEffect(() => {
    if (cooldown <= 0) return undefined;
    const id = setTimeout(() => setCooldown((s) => s - 1), 1000);
    return () => clearTimeout(id);
  }, [cooldown]);

  const onBackToLogin = useCallback(() => {
    navigation.reset({
      index: 0,
      routes: [{ name: 'Login' }],
    });
  }, [navigation]);

  const onResend = useCallback(async () => {
    if (inFlight.current || cooldown > 0 || !email) return;
    inFlight.current = true;
    setResending(true);
    try {
      const result = await resendPasswordReset(email);
      setCooldown(PASSWORD_RESET_RESEND_COOLDOWN_SECONDS);
      Alert.alert('Reset link', result.message, [{ text: 'OK' }]);
    } catch (e) {
      // Rate-limited: start the countdown so it can't be retried at once.
      const wait = cooldownAfterResendError(e, PASSWORD_RESET_RESEND_COOLDOWN_SECONDS);
      if (wait > 0) setCooldown(wait);
      Alert.alert('Reset link', describePasswordResetError(e), [{ text: 'OK' }]);
    } finally {
      inFlight.current = false;
      setResending(false);
    }
  }, [cooldown, email]);

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <ScrollView
        contentContainerStyle={styles.scroll}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        <View style={styles.illustrationWrap}>
          <View style={styles.illustrationOuter}>
            <View style={styles.illustrationInner}>
              <Ionicons name="mail-open-outline" size={56} color={colors.primaryTeal} />
            </View>
            <View style={styles.successBadge}>
              <Ionicons name="checkmark" size={22} color={colors.white} />
            </View>
          </View>
        </View>

        <Text style={styles.title}>Check Your Email</Text>
        <Text style={styles.message}>
          {message}
          {'\n\n'}
          Please check your inbox and spam folder. Open the link to choose a new password, then
          sign in here.
        </Text>

        <View style={styles.actions}>
          <Button
            title="Back To Login"
            onPress={onBackToLogin}
            accessibilityLabel="Back to login"
          />
          <View style={styles.secondaryWrap}>
            <Button
              variant="secondary"
              title={cooldown > 0 ? `Resend Link (${cooldown}s)` : 'Resend Link'}
              onPress={onResend}
              loading={resending}
              disabled={resending || cooldown > 0 || !email}
              accessibilityLabel="Resend password reset link"
            />
          </View>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  scroll: {
    flexGrow: 1,
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.xl,
    paddingBottom: spacing.xl,
    justifyContent: 'center',
  },
  illustrationWrap: {
    alignItems: 'center',
    marginBottom: spacing.lg,
  },
  illustrationOuter: {
    width: 120,
    height: 120,
    alignItems: 'center',
    justifyContent: 'center',
  },
  illustrationInner: {
    width: 112,
    height: 112,
    borderRadius: 56,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  successBadge: {
    position: 'absolute',
    right: 4,
    bottom: 4,
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.success,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 3,
    borderColor: colors.background,
  },
  title: {
    ...typography.pageTitle,
    color: colors.textPrimary,
    textAlign: 'center',
    marginBottom: spacing.md,
  },
  message: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
    marginBottom: spacing.xl,
  },
  actions: {
    width: '100%',
  },
  secondaryWrap: {
    marginTop: spacing.sm,
  },
});
