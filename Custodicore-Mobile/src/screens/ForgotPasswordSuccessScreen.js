import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, colors, layout, spacing, typography } from '../designSystem';
import { resendPasswordReset } from '../services/forgotPasswordService';

/**
 * Post–forgot-password confirmation (mock — v2.1 visitor auth).
 */
export default function ForgotPasswordSuccessScreen({ navigation, route }) {
  const email = route.params?.email ?? '';
  const [resending, setResending] = useState(false);

  const onBackToLogin = useCallback(() => {
    navigation.reset({
      index: 0,
      routes: [{ name: 'Login' }],
    });
  }, [navigation]);

  const onResend = useCallback(async () => {
    if (resending) return;
    setResending(true);
    try {
      const result = await resendPasswordReset(email);
      Alert.alert('', result.message, [{ text: 'OK' }]);
    } finally {
      setResending(false);
    }
  }, [email, resending]);

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
          If an account exists for this email address, a password reset link has been sent.
          {'\n\n'}
          Please check your inbox and spam folder.
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
              title="Resend Link"
              onPress={onResend}
              loading={resending}
              disabled={resending}
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
