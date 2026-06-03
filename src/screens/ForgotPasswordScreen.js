import React, { useCallback, useState } from 'react';
import {
  KeyboardAvoidingView,
  Platform,
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
  layout,
  spacing,
  typography,
} from '../designSystem';
import { requestPasswordReset } from '../services/forgotPasswordService';
import { validateEmail, validateRequired } from '../utils';

/**
 * Forgot password — mock reset link request (v2.1 visitor auth).
 */
export default function ForgotPasswordScreen({ navigation }) {
  const [email, setEmail] = useState('');
  const [errors, setErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);

  const onSubmit = useCallback(async () => {
    if (submitting) return;

    const trimmedEmail = String(email || '').trim();
    const next = {};
    if (!validateRequired(trimmedEmail)) {
      next.email = 'Email address is required';
    } else if (!validateEmail(trimmedEmail)) {
      next.email = 'Enter a valid email address';
    }
    setErrors(next);
    if (Object.keys(next).length) return;

    setSubmitting(true);
    try {
      await requestPasswordReset(trimmedEmail);
      navigation.navigate('ForgotPasswordSuccess', { email: trimmedEmail });
    } finally {
      setSubmitting(false);
    }
  }, [email, navigation, submitting]);

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['left', 'right', 'bottom']}>
      <StackScreenHeader
        title="Forgot Password"
        navigation={navigation}
        backFallback={{ name: 'Login' }}
      />
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={commonStyles.scrollContent}>
          <View style={styles.header}>
            <Text style={styles.subtitle}>
              Enter your email address and we will send a password reset link.
            </Text>
          </View>

          <Card style={styles.card}>
            <View style={styles.field}>
              <Text style={styles.label}>Email Address</Text>
              <TextInput
                value={email}
                onChangeText={(value) => {
                  setEmail(value);
                  if (errors.email) setErrors((prev) => ({ ...prev, email: undefined }));
                }}
                placeholder="Enter your email address"
                placeholderTextColor={colors.textSecondary}
                keyboardType="email-address"
                autoCapitalize="none"
                autoCorrect={false}
                textContentType="emailAddress"
                style={[styles.input, errors.email ? styles.inputError : null]}
              />
              {errors.email ? <Text style={styles.error}>{errors.email}</Text> : null}
            </View>

            <Button
              title="Send Reset Link"
              onPress={onSubmit}
              loading={submitting}
              disabled={submitting}
              accessibilityLabel="Send password reset link"
            />
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
  label: {
    ...typography.metadata,
    color: colors.textPrimary,
    marginBottom: spacing.sm,
    fontWeight: '600',
  },
  input: {
    height: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
    paddingHorizontal: spacing.sm,
    backgroundColor: colors.white,
    color: colors.textPrimary,
    ...typography.body,
  },
  inputError: {
    borderColor: colors.danger,
  },
  error: {
    ...typography.metadata,
    color: colors.danger,
    marginTop: spacing.sm,
  },
  footer: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
    marginTop: spacing.lg,
  },
});
