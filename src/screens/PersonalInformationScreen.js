import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { CustomInput } from '../components';
import { Button, Card, colors, layout, spacing, typography } from '../designSystem';
import { useAuth } from '../hooks/useAuth';
import { DEFAULT_LOCAL_PROFILE } from '../mock/profile.mock';
import { loadLocalProfile, persistLocalProfile } from '../services/localProfileStorage';
import { validateProfileFields } from '../utils/profileValidation';
import { getRelationshipLabel } from '../utils/registrationRequirements';

const inputLabelStyle = { color: colors.textSecondary };

/**
 * @param {{ label: string; value: string }} props
 */
function InfoField({ label, value }) {
  return (
    <View style={styles.infoField}>
      <Text style={styles.infoFieldLabel}>{label}</Text>
      <Text style={styles.infoFieldValue} accessibilityLabel={`${label}: ${value}`}>
        {value}
      </Text>
    </View>
  );
}

/**
 * Visitor personal information — view and edit profile fields (v2.1 / BJMP).
 */
export default function PersonalInformationScreen({ navigation }) {
  const { registrationSummary } = useAuth();
  const [profile, setProfile] = useState(DEFAULT_LOCAL_PROFILE);
  const [isEditing, setIsEditing] = useState(false);
  const [draft, setDraft] = useState({
    fullName: DEFAULT_LOCAL_PROFILE.fullName,
    email: DEFAULT_LOCAL_PROFILE.email,
    phone: DEFAULT_LOCAL_PROFILE.phone,
    address: DEFAULT_LOCAL_PROFILE.address,
  });
  const [fieldErrors, setFieldErrors] = useState(/** @type {Record<string, string>} */ ({}));

  const relationshipId =
    profile.relationshipToPdl ??
    registrationSummary?.relationship ??
    DEFAULT_LOCAL_PROFILE.relationshipToPdl ??
    'spouse';
  const relationshipLabel = getRelationshipLabel(relationshipId) || '—';

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
      address: profile.address ?? '',
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
      address: draft.address,
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
      address: draft.address.trim(),
    };
    try {
      await persistLocalProfile(next);
      setProfile(next);
      setIsEditing(false);
    } catch {
      Alert.alert('Could not save', 'Please try again.');
    }
  }, [profile, draft]);

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <View style={styles.topBar}>
        <Pressable
          onPress={() => navigation.goBack()}
          style={styles.backButton}
          accessibilityRole="button"
          accessibilityLabel="Go back"
        >
          <Ionicons name="chevron-back" size={24} color={colors.primaryNavy} />
        </Pressable>
        <Text style={styles.screenTitle}>Personal Information</Text>
        <View style={styles.backPlaceholder} />
      </View>

      <ScrollView
        contentContainerStyle={styles.scroll}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        <Card style={styles.card}>
          {isEditing ? (
            <>
              <CustomInput
                label="Full Name"
                value={draft.fullName}
                onChangeText={(t) => {
                  setDraft((d) => ({ ...d, fullName: t }));
                  setFieldErrors((e) => ({ ...e, fullName: '' }));
                }}
                placeholder="Full name"
                autoCapitalize="words"
                error={fieldErrors.fullName}
                labelStyle={inputLabelStyle}
                style={styles.inputSpacing}
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
                style={styles.inputSpacing}
                accessibilityLabel="Email address"
              />
              <CustomInput
                label="Phone"
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
                style={styles.inputSpacing}
                accessibilityLabel="Phone number"
              />
              <CustomInput
                label="Address"
                value={draft.address}
                onChangeText={(t) => {
                  setDraft((d) => ({ ...d, address: t }));
                  setFieldErrors((e) => ({ ...e, address: '' }));
                }}
                placeholder="Street, city, province"
                autoCapitalize="words"
                error={fieldErrors.address}
                labelStyle={inputLabelStyle}
                style={styles.inputSpacing}
                accessibilityLabel="Address"
              />
              <InfoField label="Relationship to PDL" value={relationshipLabel} />
              <View style={styles.editActionsRow}>
                <View style={styles.editActionGrow}>
                  <Button title="Save" onPress={saveProfile} accessibilityLabel="Save profile" />
                </View>
                <View style={styles.editActionGrow}>
                  <Pressable
                    onPress={cancelEditing}
                    style={({ pressed }) => [
                      styles.cancelButton,
                      pressed && styles.cancelButtonPressed,
                    ]}
                    accessibilityRole="button"
                    accessibilityLabel="Cancel editing"
                  >
                    <Text style={styles.cancelButtonText}>Cancel</Text>
                  </Pressable>
                </View>
              </View>
            </>
          ) : (
            <>
              <InfoField label="Full Name" value={profile.fullName} />
              <InfoField label="Email" value={profile.email} />
              <InfoField label="Phone" value={profile.phone} />
              <InfoField label="Address" value={profile.address || '—'} />
              <InfoField label="Relationship to PDL" value={relationshipLabel} />
              <Button
                title="Edit Profile"
                variant="secondary"
                onPress={startEditing}
                accessibilityLabel="Edit profile"
              />
            </>
          )}
        </Card>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  topBar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing[20],
    paddingVertical: spacing[8],
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
  },
  backPlaceholder: { width: 44 },
  screenTitle: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
  },
  scroll: {
    paddingHorizontal: spacing[20],
    paddingBottom: spacing[32],
  },
  card: {
    borderRadius: layout.cardRadius,
    padding: spacing[16],
  },
  infoField: {
    marginBottom: spacing[14],
  },
  infoFieldLabel: {
    ...typography.caption,
    color: colors.textSecondary,
    marginBottom: spacing[4],
  },
  infoFieldValue: {
    ...typography.body,
    color: colors.textPrimary,
    fontWeight: '500',
    lineHeight: 22,
  },
  inputSpacing: {
    marginBottom: spacing[8],
  },
  editActionsRow: {
    flexDirection: 'row',
    gap: spacing[12],
    marginTop: spacing[8],
  },
  editActionGrow: {
    flex: 1,
  },
  cancelButton: {
    height: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing[16],
    backgroundColor: colors.white,
  },
  cancelButtonPressed: {
    opacity: 0.92,
  },
  cancelButtonText: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
  },
});
