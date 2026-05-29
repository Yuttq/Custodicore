import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useState } from 'react';
import {
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
import { Button, Card, colors, layout, spacing, typography } from '../designSystem';
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
      next.email = 'Email is required';
    } else if (!validateEmail(trimmedEmail)) {
      next.email = 'Please enter a valid email address';
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
    <SafeAreaView style={styles.safe} edges={['left', 'right', 'bottom']}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.scroll}>
          <Pressable
            onPress={() => navigation.goBack()}
            accessibilityRole="button"
            accessibilityLabel="Go back"
            hitSlop={10}
            style={styles.backButton}
          >
            <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
          </Pressable>

          <View style={styles.header}>
            <Text style={styles.title}>Forgot Password</Text>
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
  safe: { flex: 1, backgroundColor: colors.background },
  flex: { flex: 1 },
  scroll: {
    paddingHorizontal: spacing[20],
    paddingTop: spacing[12],
    paddingBottom: spacing[24],
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
    marginBottom: spacing[16],
  },
  header: {
    marginBottom: spacing[16],
  },
  title: {
    ...typography.screenTitle,
    color: colors.textPrimary,
  },
  subtitle: {
    ...typography.body,
    color: colors.textSecondary,
    marginTop: spacing[8],
    lineHeight: 22,
  },
  card: {
    borderRadius: layout.cardRadius,
  },
  field: {
    marginBottom: spacing[16],
  },
  label: {
    ...typography.caption,
    color: colors.textPrimary,
    marginBottom: spacing[8],
    fontWeight: '600',
  },
  input: {
    height: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 12,
    paddingHorizontal: spacing[12],
    backgroundColor: colors.white,
    color: colors.textPrimary,
    ...typography.body,
  },
  inputError: {
    borderColor: colors.danger,
  },
  error: {
    ...typography.caption,
    color: colors.danger,
    marginTop: spacing[8],
  },
  footer: {
    ...typography.caption,
    color: colors.textSecondary,
    textAlign: 'center',
    marginTop: spacing[16],
  },
});
