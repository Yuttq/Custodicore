import Ionicons from '@expo/vector-icons/Ionicons';
import { Image as ExpoImage } from 'expo-image';
import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { launchCamera, launchImageLibrary } from 'react-native-image-picker';
import { SafeAreaView } from 'react-native-safe-area-context';
import { CustomInput } from '../components';
import {
  Button,
  Card,
  StatusChip,
  colors,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { useAuth } from '../hooks/useAuth';
import { DEFAULT_LOCAL_PROFILE } from '../mock/profile.mock';
import { getVisitationHistorySummary } from '../mock/visitationHistory.mock';
import { fetchVisitationHistory } from '../repositories/visitHistoryRepository';
import { loadLocalProfile, persistLocalProfile } from '../services/localProfileStorage';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';
import { validateProfileFields } from '../utils/profileValidation';

/** Matches read-only field label color for edit mode. */
const inputLabelStyle = { color: colors.textSecondary };

const SECTIONS = [
  {
    key: 'personal',
    label: 'Personal Information',
    icon: 'person-outline',
    expandable: true,
  },
  {
    key: 'documents',
    label: 'Visitor Verification Documents',
    icon: 'document-text-outline',
  },
  {
    key: 'history',
    label: 'Visitation History',
    icon: 'time-outline',
  },
  {
    key: 'security',
    label: 'Security Settings',
    icon: 'shield-outline',
  },
];

/**
 * @param {string} fullName
 */
function getInitials(fullName) {
  const parts = fullName.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/**
 * @param {object} props
 * @param {string | null | undefined} props.photoUri
 * @param {string} props.initials
 * @param {() => void} props.onPress
 */
function ProfileAvatar({ photoUri, initials, onPress }) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.avatarPressable, pressed && styles.avatarPressed]}
      accessibilityRole="button"
      accessibilityLabel="Profile photo. Tap to change."
    >
      <View style={styles.avatar}>
        {photoUri ? (
          <ExpoImage
            source={{ uri: photoUri }}
            style={styles.avatarImage}
            contentFit="cover"
            accessibilityLabel="Profile photo"
          />
        ) : (
          <Text style={styles.avatarText}>{initials}</Text>
        )}
      </View>
      <View style={styles.avatarBadge}>
        <Ionicons name="camera" size={14} color={colors.white} />
      </View>
    </Pressable>
  );
}

function VisitStatisticsCard({ total, completed, cancelled }) {
  return (
    <Card style={styles.statsCard}>
      <View style={styles.statsItem}>
        <Text style={styles.statsValue}>{total}</Text>
        <Text style={styles.statsLabel}>Total Visits</Text>
      </View>
      <View style={styles.statsDivider} />
      <View style={styles.statsItem}>
        <Text style={[styles.statsValue, styles.statsValueSuccess]}>{completed}</Text>
        <Text style={styles.statsLabel}>Completed Visits</Text>
      </View>
      <View style={styles.statsDivider} />
      <View style={styles.statsItem}>
        <Text style={[styles.statsValue, styles.statsValueDanger]}>{cancelled}</Text>
        <Text style={styles.statsLabel}>Cancelled Visits</Text>
      </View>
    </Card>
  );
}

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
 * @param {object} props
 * @param {string} props.icon
 * @param {string} props.label
 * @param {boolean} [props.expanded]
 * @param {boolean} [props.isLast]
 * @param {() => void} props.onPress
 */
function SectionRow({ icon, label, expanded, isLast, onPress }) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [
        styles.sectionRow,
        !isLast && styles.sectionRowBorder,
        pressed && styles.sectionRowPressed,
      ]}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ expanded: expanded ?? undefined }}
    >
      <View style={styles.sectionIconWrap}>
        <Ionicons name={icon} size={20} color={colors.primaryTeal} />
      </View>
      <Text style={styles.sectionLabel}>{label}</Text>
      <Ionicons
        name={expanded ? 'chevron-up' : 'chevron-forward'}
        size={18}
        color={colors.textSecondary}
      />
    </Pressable>
  );
}

/**
 * Visitor profile — identity, visit statistics, account sections (v2.1 / BJMP).
 */
export default function ProfileScreen({ navigation }) {
  const { logout, pendingVerification, registrationSummary } = useAuth();
  const [profile, setProfile] = useState(DEFAULT_LOCAL_PROFILE);
  const [visitStats, setVisitStats] = useState({ total: 0, completed: 0, cancelled: 0 });
  const [expandedSection, setExpandedSection] = useState(null);
  const [isEditing, setIsEditing] = useState(false);
  const [draft, setDraft] = useState({
    fullName: DEFAULT_LOCAL_PROFILE.fullName,
    email: DEFAULT_LOCAL_PROFILE.email,
    phone: DEFAULT_LOCAL_PROFILE.phone,
  });
  const [fieldErrors, setFieldErrors] = useState(/** @type {Record<string, string>} */ ({}));

  const visitorName =
    profile.fullName?.trim() ||
    registrationSummary?.fullName?.trim() ||
    DEFAULT_LOCAL_PROFILE.fullName;
  const isVerified = !pendingVerification && profile.registrationStatus === 'approved';
  const tabBarInset = useTabBarScrollInset();

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

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const data = await fetchVisitationHistory();
        if (!cancelled) setVisitStats(getVisitationHistorySummary(data));
      } catch {
        // Keep default zeros when history is unavailable.
      }
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

  const persistProfilePhoto = useCallback(async (photoUri) => {
    const next = { ...profile, photoUri: photoUri || null };
    try {
      await persistLocalProfile(next);
      setProfile(next);
    } catch {
      Alert.alert('Could not save photo', 'Please try again.');
    }
  }, [profile]);

  const pickProfilePhoto = useCallback(async (useCamera) => {
    const options = { mediaType: 'photo', saveToPhotos: false };
    const result = useCamera
      ? await launchCamera(options)
      : await launchImageLibrary(options);
    const asset = result.assets?.[0];
    if (asset?.uri) {
      await persistProfilePhoto(asset.uri);
    } else if (result.errorMessage) {
      Alert.alert('Could not open image', result.errorMessage);
    }
  }, [persistProfilePhoto]);

  const removeProfilePhoto = useCallback(async () => {
    await persistProfilePhoto(null);
  }, [persistProfilePhoto]);

  const onAvatarPress = useCallback(() => {
    const buttons = [
      { text: 'Take Photo', onPress: () => pickProfilePhoto(true) },
      { text: 'Choose From Gallery', onPress: () => pickProfilePhoto(false) },
    ];
    if (profile.photoUri) {
      buttons.push({
        text: 'Remove Photo',
        style: 'destructive',
        onPress: removeProfilePhoto,
      });
    }
    buttons.push({ text: 'Cancel', style: 'cancel' });
    Alert.alert('Profile Photo', undefined, buttons);
  }, [profile.photoUri, pickProfilePhoto, removeProfilePhoto]);

  const onSectionPress = useCallback(
    (key) => {
      if (key === 'personal') {
        setExpandedSection((current) => (current === 'personal' ? null : 'personal'));
        return;
      }
      if (key === 'documents') {
        navigation.navigate('VisitorVerificationDocuments', {
          relationshipId: profile.relationshipToPdl ?? 'spouse',
        });
        return;
      }
      if (key === 'history') {
        navigation.navigate('VisitHistory');
        return;
      }
      if (key === 'security') {
        Alert.alert(
          'Security Settings',
          'Password change and account security options will be available in a future update.',
        );
      }
    },
    [navigation, profile.relationshipToPdl],
  );

  const onLogout = useCallback(() => {
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
  }, [logout]);

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={[styles.scroll, { paddingBottom: tabBarInset }]}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        <Text style={styles.screenTitle}>Profile</Text>

        <View style={styles.hero}>
          <ProfileAvatar
            photoUri={profile.photoUri}
            initials={getInitials(visitorName)}
            onPress={onAvatarPress}
          />
          <Text style={styles.visitorName}>{visitorName}</Text>
          <View style={styles.badgeRow}>
            {isVerified ? (
              <View style={styles.verifiedBadge}>
                <Ionicons name="checkmark-circle" size={14} color={colors.success} />
                <Text style={styles.verifiedBadgeText}>Verified Visitor</Text>
              </View>
            ) : (
              <StatusChip status="pending_verification" label="Pending Verification" />
            )}
          </View>
        </View>

        <Text style={styles.sectionHeading}>VISITOR STATISTICS</Text>
        <VisitStatisticsCard
          total={visitStats.total}
          completed={visitStats.completed}
          cancelled={visitStats.cancelled}
        />

        <Text style={styles.sectionHeading}>ACCOUNT</Text>
        <Card style={styles.sectionsCard}>
          {SECTIONS.map((section, index) => (
            <View key={section.key}>
              <SectionRow
                icon={section.icon}
                label={section.label}
                expanded={section.expandable ? expandedSection === section.key : undefined}
                isLast={index === SECTIONS.length - 1}
                onPress={() => onSectionPress(section.key)}
              />
              {section.key === 'personal' && expandedSection === 'personal' ? (
                <View style={styles.personalPanel}>
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
                      <View style={styles.editActionsRow}>
                        <View style={styles.editActionGrow}>
                          <Button
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
                            <Text style={styles.cancelButtonText}>Cancel</Text>
                          </Pressable>
                        </View>
                      </View>
                    </>
                  ) : (
                    <>
                      <InfoField label="Name" value={profile.fullName} />
                      <InfoField label="Email" value={profile.email} />
                      <InfoField label="Contact number" value={profile.phone} />
                      <View style={styles.editProfileWrap}>
                        <Button
                          title="Edit Profile"
                          variant="secondary"
                          onPress={startEditing}
                          accessibilityLabel="Edit profile"
                        />
                      </View>
                    </>
                  )}
                </View>
              ) : null}
            </View>
          ))}
        </Card>

        <Pressable
          onPress={onLogout}
          style={({ pressed }) => [styles.logoutBtn, pressed && styles.logoutBtnPressed]}
          accessibilityRole="button"
          accessibilityLabel="Log out of CustodiCore"
        >
          <Ionicons name="log-out-outline" size={20} color={colors.danger} />
          <Text style={styles.logoutBtnText}>Log Out</Text>
        </Pressable>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  scroll: {
    paddingHorizontal: spacing[20],
  },
  screenTitle: {
    ...typography.sectionTitle,
    color: colors.textPrimary,
    marginBottom: spacing[16],
  },
  hero: {
    alignItems: 'center',
    marginBottom: layout.sectionGap,
  },
  avatarPressable: {
    position: 'relative',
    marginBottom: spacing[12],
  },
  avatarPressed: {
    opacity: 0.92,
  },
  avatar: {
    width: 96,
    height: 96,
    borderRadius: 48,
    backgroundColor: colors.primaryNavy,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 3,
    borderColor: colors.card,
    overflow: 'hidden',
    shadowColor: colors.primaryNavy,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.15,
    shadowRadius: 8,
    elevation: 4,
  },
  avatarImage: {
    width: '100%',
    height: '100%',
  },
  avatarBadge: {
    position: 'absolute',
    right: 0,
    bottom: 0,
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: colors.primaryTeal,
    borderWidth: 2,
    borderColor: colors.card,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: {
    ...typography.sectionTitle,
    color: colors.white,
    fontSize: 32,
  },
  visitorName: {
    ...typography.screenTitle,
    fontSize: 24,
    color: colors.textPrimary,
    textAlign: 'center',
    marginBottom: spacing[8],
  },
  badgeRow: {
    alignSelf: 'center',
  },
  verifiedBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[8],
    backgroundColor: 'rgba(22, 163, 74, 0.12)',
    paddingHorizontal: spacing[12],
    paddingVertical: spacing[4],
    borderRadius: layout.chipRadius,
  },
  verifiedBadgeText: {
    ...typography.statusLabel,
    color: colors.success,
    fontWeight: '600',
  },
  sectionHeading: {
    ...typography.caption,
    fontWeight: '600',
    color: colors.textSecondary,
    letterSpacing: 0.6,
    marginBottom: spacing[8],
  },
  statsCard: {
    borderRadius: layout.cardRadius,
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: layout.sectionGap,
    paddingVertical: spacing[16],
  },
  statsItem: {
    flex: 1,
    alignItems: 'center',
  },
  statsValue: {
    ...typography.screenTitle,
    fontSize: 24,
    color: colors.primaryNavy,
    marginBottom: spacing[4],
  },
  statsValueSuccess: {
    color: colors.success,
  },
  statsValueDanger: {
    color: colors.danger,
  },
  statsLabel: {
    ...typography.caption,
    color: colors.textSecondary,
    textAlign: 'center',
  },
  statsDivider: {
    width: StyleSheet.hairlineWidth,
    height: 40,
    backgroundColor: colors.border,
  },
  sectionsCard: {
    borderRadius: layout.cardRadius,
    paddingVertical: 0,
    paddingHorizontal: 0,
    overflow: 'hidden',
    marginBottom: layout.sectionGap,
  },
  sectionRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[16],
    paddingVertical: spacing[16],
    gap: spacing[12],
    backgroundColor: colors.card,
  },
  sectionRowBorder: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  sectionRowPressed: {
    backgroundColor: colors.background,
  },
  sectionIconWrap: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.background,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  sectionLabel: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  personalPanel: {
    paddingHorizontal: spacing[16],
    paddingBottom: spacing[16],
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
    backgroundColor: colors.background,
  },
  infoField: {
    marginBottom: spacing[12],
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
  },
  profileInputSpacing: {
    marginBottom: spacing[8],
  },
  editActionsRow: {
    flexDirection: 'row',
    gap: spacing[12],
    marginTop: spacing[4],
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
  editProfileWrap: {
    marginTop: spacing[4],
  },
  logoutBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing[8],
    height: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.danger,
    backgroundColor: colors.white,
  },
  logoutBtnPressed: {
    opacity: 0.92,
  },
  logoutBtnText: {
    ...typography.body,
    fontWeight: '600',
    color: colors.danger,
  },
});
