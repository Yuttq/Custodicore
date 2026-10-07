import Ionicons from '@expo/vector-icons/Ionicons';
import * as Linking from 'expo-linking';
import React, { useCallback, useEffect, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, colors, layout, spacing, typography } from '../designSystem';
import { useAuth } from '../hooks/useAuth';
import { isEmailVerifiedLink } from '../utils/emailVerificationLink';

/** Seconds before "Resend" can be tapped again (the backend also limits it). */
const RESEND_COOLDOWN_SECONDS = 60;

/**
 * Shown after a successful registration (and when login is refused because
 * the email is not verified yet). The account exists but there is no
 * session: the visitor must open the link in the verification email, then
 * log in. Route params: `email` (display only), `fromLogin`.
 *
 * Nothing secret is held here — the verification token only ever exists in
 * the emailed link.
 */
export default function CheckEmailScreen({ navigation, route }) {
  const { resendVerificationEmail } = useAuth();
  const email = route.params?.email ?? '';
  const fromLogin = route.params?.fromLogin === true;
  const [resending, setResending] = useState(false);
  const [cooldown, setCooldown] = useState(0);
  const incomingUrl = Linking.useURL();

  const goToLogin = useCallback(
    (emailVerified = false) => {
      navigation.reset({
        index: 0,
        routes: [{ name: 'Login', params: emailVerified ? { emailVerified: true } : undefined }],
      });
    },
    [navigation],
  );

  // The verification page's "Open the CustodiCore app" button.
  useEffect(() => {
    if (isEmailVerifiedLink(incomingUrl)) goToLogin(true);
  }, [incomingUrl, goToLogin]);

  useEffect(() => {
    if (cooldown <= 0) return undefined;
    const id = setTimeout(() => setCooldown((s) => s - 1), 1000);
    return () => clearTimeout(id);
  }, [cooldown]);

  const onOpenEmail = useCallback(async () => {
    try {
      await Linking.openURL('mailto:');
    } catch {
      Alert.alert('Open Email', 'Open your email app and look for the message from CustodiCore.');
    }
  }, []);

  const onResend = useCallback(async () => {
    if (resending || cooldown > 0 || !email) return;
    setResending(true);
    try {
      const result = await resendVerificationEmail(email);
      setCooldown(RESEND_COOLDOWN_SECONDS);
      Alert.alert(
        'Verification email',
        result?.message ||
          'If your account still needs verification, a new link has been sent. Please check your inbox and spam folder.',
      );
    } catch (e) {
      const message =
        e?.status === 429
          ? 'Too many verification emails were requested. Please wait a few minutes and try again.'
          : typeof e?.message === 'string' && e.message.trim()
            ? e.message
            : 'Could not request a new verification email. Please try again.';
      Alert.alert('Verification email', message);
    } finally {
      setResending(false);
    }
  }, [cooldown, email, resendVerificationEmail, resending]);

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
              <Ionicons name="mail-unread-outline" size={56} color={colors.primaryTeal} />
            </View>
            {!fromLogin ? (
              <View style={styles.successBadge}>
                <Ionicons name="checkmark" size={22} color={colors.white} />
              </View>
            ) : null}
          </View>
        </View>

        <Text style={styles.title}>Check your email</Text>
        <Text style={styles.message}>
          {fromLogin
            ? 'Please verify your email address before logging in.'
            : 'Your account has been created.'}
          {'\n\n'}
          We sent a verification link to:
        </Text>
        {email ? <Text style={styles.email}>{email}</Text> : null}
        <Text style={styles.message}>
          Please verify your email before logging in. If you don&apos;t see the message, check
          your spam folder.
        </Text>

        <View style={styles.actions}>
          <Button title="Open Email" onPress={onOpenEmail} accessibilityLabel="Open email app" />
          <View style={styles.secondaryWrap}>
            <Button
              variant="secondary"
              title={
                cooldown > 0
                  ? `Resend Verification Email (${cooldown}s)`
                  : 'Resend Verification Email'
              }
              onPress={onResend}
              loading={resending}
              disabled={resending || cooldown > 0 || !email}
              accessibilityLabel="Resend verification email"
            />
          </View>
          <View style={styles.secondaryWrap}>
            <Button
              variant="secondary"
              title="Back to Login"
              onPress={() => goToLogin(false)}
              accessibilityLabel="Back to login"
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
    marginBottom: spacing.md,
  },
  email: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    textAlign: 'center',
    marginBottom: spacing.md,
  },
  actions: {
    width: '100%',
    marginTop: spacing.md,
  },
  secondaryWrap: {
    marginTop: spacing.sm,
  },
});
