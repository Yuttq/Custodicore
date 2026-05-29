import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {
  Card,
  CustomButton,
  CustomInput,
  Header,
  ScreenContainer,
  StatusBadge,
} from '../components';
import { colors, layout, typography } from '../constants';
import { useAuth } from '../hooks/useAuth';
// TEMP MOCK DATA — Replace with backend API response later
import { DEFAULT_LOCAL_PROFILE } from '../mock/profile.mock';
import { loadLocalProfile, persistLocalProfile } from '../services/localProfileStorage';
import { validateProfileFields } from '../utils/profileValidation';

/** Matches read-only `Field` label color for visual consistency in edit mode. */
const inputLabelStyle = { color: colors.textSecondary };

export default function ProfileScreen({ navigation }) {
  const { logout } = useAuth();
  const [profile, setProfile] = useState(DEFAULT_LOCAL_PROFILE);
  const [isEditing, setIsEditing] = useState(false);
  const [draft, setDraft] = useState({
    fullName: DEFAULT_LOCAL_PROFILE.fullName,
    email: DEFAULT_LOCAL_PROFILE.email,
    phone: DEFAULT_LOCAL_PROFILE.phone,
  });
  const [fieldErrors, setFieldErrors] = useState(/** @type {Record<string, string>} */ ({}));

  // Hydrate from AsyncStorage on mount (offline-first until backend exists).
  useEffect(() => {
    let cancelled = false;
    (async () => {
      const loaded = await loadLocalProfile(DEFAULT_LOCAL_PROFILE);
      if (!cancelled) setProfile(loaded);
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const startEditing = useCallback(() => {
    setDraft({
      fullName: profile.fullName,
      email: profile.email,
      phone: profile.phone,
    });
    setFieldErrors({});
    setIsEditing(true);
  }, [profile]);

  const cancelEditing = useCallback(() => {
    setFieldErrors({});
    setIsEditing(false);
  }, []);

  const saveProfile = useCallback(async () => {
    const { valid, errors } = validateProfileFields({
      fullName: draft.fullName,
      email: draft.email,
      phone: draft.phone,
    });
    if (!valid) {
      setFieldErrors(errors);
      return;
    }
    setFieldErrors({});
    const next = {
      ...profile,
      fullName: draft.fullName.trim(),
      email: draft.email.trim(),
      phone: draft.phone.trim(),
    };
    try {
      // TODO: Connect to backend/database in production — PATCH visitor profile, then persist response locally if needed.
      await persistLocalProfile(next);
      setProfile(next);
      setIsEditing(false);
    } catch {
      Alert.alert('Could not save', 'Please try again.');
    }
  }, [profile, draft]);

  return (
    <ScreenContainer backgroundColor="lightGray">
      <Header title="Profile" />
      <ScrollView
        contentContainerStyle={styles.content}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        <Card style={styles.infoCard}>
          {isEditing ? (
            <>
              <CustomInput
                label="Name"
                value={draft.fullName}
                onChangeText={(t) => {
                  setDraft((d) => ({ ...d, fullName: t }));
                  setFieldErrors((e) => ({ ...e, fullName: '' }));
                }}
                placeholder="Full name"
                autoCapitalize="words"
                error={fieldErrors.fullName}
                labelStyle={inputLabelStyle}
                style={styles.profileInputSpacing}
                accessibilityLabel="Full name"
              />
              <CustomInput
                label="Email"
                value={draft.email}
                onChangeText={(t) => {
                  setDraft((d) => ({ ...d, email: t }));
                  setFieldErrors((e) => ({ ...e, email: '' }));
                }}
                placeholder="Email address"
                keyboardType="email-address"
                autoCapitalize="none"
                autoCorrect={false}
                error={fieldErrors.email}
                labelStyle={inputLabelStyle}
                style={styles.profileInputSpacing}
                accessibilityLabel="Email address"
              />
              <CustomInput
                label="Contact number"
                value={draft.phone}
                onChangeText={(t) => {
                  setDraft((d) => ({ ...d, phone: t }));
                  setFieldErrors((e) => ({ ...e, phone: '' }));
                }}
                placeholder="+63 917 000 0000"
                keyboardType="phone-pad"
                autoCorrect={false}
                error={fieldErrors.phone}
                labelStyle={inputLabelStyle}
                style={styles.profileInputSpacing}
                accessibilityLabel="Contact number"
              />
            </>
          ) : (
            <>
              <Field label="Name" value={profile.fullName} />
              <Field label="Email" value={profile.email} />
              <Field label="Contact number" value={profile.phone} />
            </>
          )}
          <View style={styles.registrationBlock}>
            <Text style={[typography.meta, styles.registrationEyebrow]}>
              Registration status
            </Text>
            <View style={styles.badgeRow}>
              <StatusBadge status={profile.registrationStatus} />
            </View>
          </View>
        </Card>

        {isEditing ? (
          <View style={styles.editActionsRow}>
            <View style={styles.editActionGrow}>
              <CustomButton
                title="Save"
                onPress={saveProfile}
                accessibilityLabel="Save profile changes"
              />
            </View>
            <View style={styles.editActionGrow}>
              <Pressable
                onPress={cancelEditing}
                style={({ pressed }) => [
                  styles.cancelButton,
                  pressed && styles.cancelButtonPressed,
                ]}
                accessibilityRole="button"
                accessibilityLabel="Cancel editing profile"
              >
                <Text style={[typography.button, styles.cancelButtonText]}>Cancel</Text>
              </Pressable>
            </View>
          </View>
        ) : (
          <CustomButton
            title="Edit profile"
            onPress={startEditing}
            accessibilityLabel="Edit profile"
          />
        )}

        <View style={styles.gap} />

        <CustomButton
          title="Visitor Verification"
          onPress={() =>
            navigation.navigate('UploadID', {
              relationshipId: profile.relationshipToPdl ?? 'spouse',
            })
          }
          accessibilityLabel="Open visitor verification documents"
        />

        <View style={styles.gap} />

        <CustomButton
          title="Visitation History"
          onPress={() => navigation.navigate('VisitHistory')}
          accessibilityLabel="Open visitation history"
        />

        <View style={styles.gap} />

        <CustomButton
          title="Log out"
          variant="danger"
          onPress={() => {
            Alert.alert('Log out', 'You will need to sign in again to access your visits.', [
              { text: 'Cancel', style: 'cancel' },
              {
                text: 'Log out',
                style: 'destructive',
                onPress: () => {
                  logout();
                },
              },
            ]);
          }}
          accessibilityLabel="Log out of CustodiCore"
        />
      </ScrollView>
    </ScreenContainer>
  );
}

/**
 * @param {{ label: string; value: string }} props
 */
function Field({ label, value }) {
  return (
    <View style={styles.fieldBlock}>
      <Text style={[typography.meta, styles.fieldEyebrow]}>{label}</Text>
      <Text
        style={[typography.bodyStrong, styles.value]}
        accessibilityLabel={`${label}: ${value}`}
      >
        {value}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  content: {
    padding: layout.spacing.md,
    paddingBottom: layout.spacing.lg,
  },
  infoCard: {
    marginBottom: layout.spacing.md,
  },
  profileInputSpacing: {
    marginBottom: layout.spacing.sm,
  },
  fieldBlock: {
    marginBottom: layout.spacing.sm,
  },
  registrationBlock: {
    marginTop: layout.spacing.md,
    paddingTop: layout.spacing.md,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.borderLight,
  },
  registrationEyebrow: {
    color: colors.textSecondary,
    textTransform: 'uppercase',
    marginBottom: layout.spacing.xs,
  },
  fieldEyebrow: {
    color: colors.textSecondary,
    textTransform: 'uppercase',
    marginBottom: layout.spacing.xs,
  },
  value: {
    color: colors.textPrimary,
    lineHeight: 20,
  },
  badgeRow: {
    marginTop: layout.spacing.xs,
    alignSelf: 'flex-start',
  },
  editActionsRow: {
    flexDirection: 'row',
    gap: layout.spacing.sm,
    alignItems: 'stretch',
    marginTop: layout.spacing.md,
  },
  editActionGrow: {
    flex: 1,
  },
  cancelButton: {
    minHeight: layout.minTouchHeight,
    borderRadius: layout.borderRadius,
    borderWidth: 1,
    borderColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: layout.spacing.md,
    backgroundColor: colors.white,
  },
  cancelButtonPressed: {
    opacity: 0.88,
  },
  cancelButtonText: {
    color: colors.primary,
  },
  gap: {
    height: layout.spacing.md,
  },
});
