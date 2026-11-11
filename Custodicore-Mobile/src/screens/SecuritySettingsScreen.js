import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
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
import { useAuth } from '../hooks/useAuth';
import { changePassword, requestPasswordReset } from '../services/api';
import { mapChangePasswordErrors, validateChangePasswordForm } from '../utils/changePassword';
import { inputFilters, passwordRequirementChecks } from '../utils/registrationValidation';

/**
 * One password box with a show/hide eye (same look as registration).
 * @param {{ label: string; value: string; onChangeText: (t: string) => void; error?: string; textContentType: 'password' | 'newPassword'; children?: React.ReactNode }} props
 */
function PasswordField({ label, value, onChangeText, error, textContentType, children }) {
  const [visible, setVisible] = useState(false);
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <View style={[styles.passwordRow, error ? styles.inputError : null]}>
        <TextInput
          value={value}
          onChangeText={(t) => onChangeText(inputFilters.password(t))}
          placeholder="••••••••"
          placeholderTextColor={colors.textSecondary}
          secureTextEntry={!visible}
          autoCapitalize="none"
          autoCorrect={false}
          textContentType={textContentType}
          autoComplete={textContentType === 'newPassword' ? 'new-password' : 'current-password'}
          accessibilityLabel={label}
          style={styles.passwordInput}
        />
        <Pressable
          onPress={() => setVisible((v) => !v)}
          accessibilityRole="button"
          accessibilityLabel={visible ? `Hide ${label.toLowerCase()}` : `Show ${label.toLowerCase()}`}
          hitSlop={10}
          style={styles.eye}
        >
          <Ionicons
            name={visible ? 'eye-off-outline' : 'eye-outline'}
            size={20}
            color={colors.textSecondary}
          />
        </Pressable>
      </View>
      {error ? <Text style={styles.error}>{error}</Text> : null}
      {children}
    </View>
  );
}

/**
 * Profile → Security Settings: change password (POST /api/me/password).
 * The current password is required; other devices are signed out and an
 * email confirms the change (both done by the backend). Passwords live only
 * in this screen's state and are cleared after a successful change.
 */
export default function SecuritySettingsScreen({ navigation }) {
  const { user } = useAuth();
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [errors, setErrors] = useState(/** @type {Record<string, string>} */ ({}));
  const [formError, setFormError] = useState(/** @type {string | null} */ (null));
  const [saving, setSaving] = useState(false);
  const [sendingReset, setSendingReset] = useState(false);

  const clearError = (key) => {
    setErrors((e) => (e[key] ? { ...e, [key]: '' } : e));
    setFormError(null);
  };

  const submit = useCallback(async () => {
    if (saving) return;
    const found = validateChangePasswordForm({
      currentPassword,
      password,
      confirmation,
      email: user?.email,
    });
    if (Object.keys(found).length > 0) {
      setErrors(found);
      return;
    }
    setErrors({});
    setFormError(null);
    setSaving(true);
    try {
      await changePassword({ currentPassword, password, passwordConfirmation: confirmation });
      setCurrentPassword('');
      setPassword('');
      setConfirmation('');
      Alert.alert(
        'Password Changed',
        'Your password has been updated. Other devices signed in to your account have been signed out.',
        [{ text: 'OK', onPress: () => navigation.goBack() }],
      );
    } catch (e) {
      if (e?.status === 422) {
        const mapped = mapChangePasswordErrors(e.errors);
        setErrors(mapped);
        if (Object.keys(mapped).length === 0) setFormError(e.message);
      } else if (e?.status !== 401) {
        // 429 (too many wrong attempts), network and server errors.
        setFormError(e?.message || 'Could not change your password. Please try again.');
      }
    } finally {
      setSaving(false);
    }
  }, [saving, currentPassword, password, confirmation, user?.email, navigation]);

  const sendResetLink = useCallback(() => {
    const email = user?.email;
    if (!email || sendingReset) return;
    Alert.alert(
      'Reset Password by Email',
      `We'll send a password reset link to ${email}.`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Send Link',
          onPress: async () => {
            setSendingReset(true);
            try {
              await requestPasswordReset(email);
              Alert.alert('Check Your Email', `If the link doesn't arrive, check your spam folder.`);
            } catch (e) {
              Alert.alert('Could Not Send Link', e?.message || 'Please try again later.');
            } finally {
              setSendingReset(false);
            }
          },
        },
      ],
    );
  }, [user?.email, sendingReset]);

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Security Settings" navigation={navigation} />

      <ScrollView
        contentContainerStyle={commonStyles.scrollContent}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        <Card style={styles.card}>
          <Text style={styles.title}>Change Password</Text>

          <PasswordField
            label="Current Password"
            value={currentPassword}
            onChangeText={(t) => {
              setCurrentPassword(t);
              clearError('currentPassword');
            }}
            error={errors.currentPassword}
            textContentType="password"
          />

          <PasswordField
            label="New Password"
            value={password}
            onChangeText={(t) => {
              setPassword(t);
              clearError('password');
              clearError('confirmation');
            }}
            error={errors.password}
            textContentType="newPassword"
          >
            <View style={styles.checklist}>
              {passwordRequirementChecks(password).map((req) => (
                <View
                  key={req.key}
                  style={styles.checkItem}
                  accessible
                  accessibilityLabel={`${req.label}, ${req.met ? 'met' : 'not met'}`}
                >
                  <Ionicons
                    name={req.met ? 'checkmark-circle' : 'ellipse-outline'}
                    size={16}
                    color={req.met ? colors.successStrong : colors.textSecondary}
                  />
                  <Text style={[styles.checkText, req.met && styles.checkTextMet]}>{req.label}</Text>
                </View>
              ))}
            </View>
          </PasswordField>

          <PasswordField
            label="Confirm New Password"
            value={confirmation}
            onChangeText={(t) => {
              setConfirmation(t);
              clearError('confirmation');
            }}
            error={errors.confirmation}
            textContentType="newPassword"
          />

          {formError ? (
            <Text style={styles.formError} accessibilityRole="alert">
              {formError}
            </Text>
          ) : null}

          <Button
            title={saving ? 'Updating…' : 'Update Password'}
            onPress={submit}
            loading={saving}
            disabled={saving}
            accessibilityLabel="Update password"
          />

          <Pressable
            onPress={sendResetLink}
            disabled={sendingReset || !user?.email}
            accessibilityRole="button"
            accessibilityLabel="Forgot your current password? Send a reset link by email"
            hitSlop={8}
            style={styles.forgot}
          >
            <Text style={styles.forgotText}>
              {sendingReset ? 'Sending link…' : 'Forgot your current password?'}
            </Text>
          </Pressable>
        </Card>

        <View style={styles.note}>
          <Ionicons name="information-circle-outline" size={16} color={colors.textSecondary} />
          <Text style={styles.noteText}>
            Changing your password signs you out on other devices. We&apos;ll email you to confirm the change.
          </Text>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  card: {
    borderRadius: layout.cardRadius,
    padding: spacing.md,
  },
  title: {
    ...typography.body,
    fontWeight: '700',
    color: colors.textPrimary,
    marginBottom: spacing.sm,
  },
  field: {
    marginBottom: spacing.md,
  },
  label: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.textPrimary,
    marginBottom: spacing.xs,
  },
  passwordRow: {
    height: 44,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.buttonRadius,
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
    color: colors.dangerStrong,
    marginTop: spacing.xs,
  },
  // Two columns when each item gets ≥ 140px; one column on narrower screens.
  checklist: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    marginTop: spacing.xs,
  },
  checkItem: {
    width: '50%',
    minWidth: 140,
    flexGrow: 1,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingVertical: 2,
    paddingRight: spacing.xs,
  },
  checkText: {
    ...typography.metadata,
    color: colors.textSecondary,
  },
  checkTextMet: { color: colors.successStrong },
  formError: {
    ...typography.metadata,
    color: colors.dangerStrong,
    marginBottom: spacing.sm,
  },
  forgot: {
    alignSelf: 'center',
    marginTop: spacing.md,
  },
  forgotText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.primaryTealDark,
  },
  note: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.xs,
    marginTop: spacing.md,
    paddingHorizontal: spacing.xs,
  },
  noteText: {
    ...typography.metadata,
    color: colors.textSecondary,
    flex: 1,
  },
});
