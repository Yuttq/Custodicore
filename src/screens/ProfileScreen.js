import Ionicons from '@expo/vector-icons/Ionicons';
import { Image as ExpoImage } from 'expo-image';
import { useFocusEffect } from '@react-navigation/native';
import React, { useCallback, useState } from 'react';
import {
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { pickPhotoFromCamera, pickPhotoFromGallery } from '../services/imagePickerService';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Card,
  StatusChip,
  colors,
  layout,
  spacing,
  typography,
} from '../designSystem';
import { useAuth } from '../hooks/useAuth';
import { getMockFacilityContact } from '../mock/assignedVisits.mock';
import { DEFAULT_LOCAL_PROFILE } from '../mock/profile.mock';
import { loadLocalProfile, persistLocalProfile } from '../services/localProfileStorage';
import useTabBarScrollInset from '../hooks/useTabBarScrollInset';

const MENU_ITEMS = [
  {
    key: 'personal',
    label: 'Personal Information',
    icon: 'person-outline',
  },
  {
    key: 'documents',
    label: 'Verification Documents',
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
  {
    key: 'help',
    label: 'Help Center',
    icon: 'help-circle-outline',
  },
  {
    key: 'logout',
    label: 'Logout',
    icon: 'log-out-outline',
    destructive: true,
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

/**
 * @param {object} props
 * @param {string} props.icon
 * @param {string} props.label
 * @param {boolean} [props.destructive]
 * @param {boolean} [props.isLast]
 * @param {() => void} props.onPress
 */
function MenuRow({ icon, label, destructive, isLast, onPress }) {
  const iconColor = destructive ? colors.danger : colors.primaryTeal;

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [
        styles.menuRow,
        !isLast && styles.menuRowBorder,
        pressed && styles.menuRowPressed,
      ]}
      accessibilityRole="button"
      accessibilityLabel={label}
    >
      <View style={[styles.menuIconWrap, destructive && styles.menuIconWrapDanger]}>
        <Ionicons name={icon} size={20} color={iconColor} />
      </View>
      <Text style={[styles.menuLabel, destructive && styles.menuLabelDanger]}>{label}</Text>
      {!destructive ? (
        <Ionicons name="chevron-forward" size={18} color={colors.textSecondary} />
      ) : null}
    </Pressable>
  );
}

/**
 * Visitor profile — account management (v2.1 / BJMP).
 */
export default function ProfileScreen({ navigation }) {
  const { logout, pendingVerification, registrationSummary } = useAuth();
  const [profile, setProfile] = useState(DEFAULT_LOCAL_PROFILE);

  const visitorName =
    profile.fullName?.trim() ||
    registrationSummary?.fullName?.trim() ||
    DEFAULT_LOCAL_PROFILE.fullName;
  const isVerified = !pendingVerification && profile.registrationStatus === 'approved';
  const tabBarInset = useTabBarScrollInset();

  useFocusEffect(
    useCallback(() => {
      let cancelled = false;
      loadLocalProfile(DEFAULT_LOCAL_PROFILE).then((loaded) => {
        if (!cancelled) setProfile(loaded);
      });
      return () => {
        cancelled = true;
      };
    }, []),
  );

  const persistProfilePhoto = useCallback(async (photoUri) => {
    const next = { ...profile, photoUri: photoUri || null };
    try {
      await persistLocalProfile(next);
      setProfile(next);
    } catch {
      Alert.alert('Could not save photo', 'Please try again.');
    }
  }, [profile]);

  const pickProfilePhoto = useCallback(
    async (useCamera) => {
      const picked = useCamera ? await pickPhotoFromCamera() : await pickPhotoFromGallery();
      if (picked?.uri) {
        await persistProfilePhoto(picked.uri);
      }
    },
    [persistProfilePhoto],
  );

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

  const onMenuPress = useCallback(
    (key) => {
      if (key === 'personal') {
        navigation.navigate('PersonalInformation');
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
        return;
      }
      if (key === 'help') {
        const contact = getMockFacilityContact();
        Alert.alert(
          'Help Center',
          [
            'For visit scheduling, verification, or facility questions, contact:',
            '',
            contact.facilityName,
            contact.contactNumber,
            contact.officeAvailability,
            '',
            'Bring your valid ID. Present both QR code and ID during check-in.',
          ].join('\n'),
        );
        return;
      }
      if (key === 'logout') {
        onLogout();
      }
    },
    [navigation, profile.relationshipToPdl, onLogout],
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={[styles.scroll, { paddingBottom: tabBarInset }]}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        <Text style={styles.screenTitle} accessibilityRole="header">
          Profile
        </Text>

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

        <Text style={styles.sectionHeading}>ACCOUNT</Text>
        <Card style={styles.menuCard}>
          {MENU_ITEMS.map((item, index) => (
            <MenuRow
              key={item.key}
              icon={item.icon}
              label={item.label}
              destructive={item.destructive}
              isLast={index === MENU_ITEMS.length - 1}
              onPress={() => onMenuPress(item.key)}
            />
          ))}
        </Card>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  scroll: {
    paddingHorizontal: layout.screenPadding,
    paddingTop: spacing.sm,
  },
  screenTitle: {
    ...typography.pageTitle,
    color: colors.textPrimary,
    marginBottom: layout.pageTitleGap,
  },
  hero: {
    alignItems: 'center',
    marginBottom: layout.sectionGap,
  },
  avatarPressable: {
    position: 'relative',
    marginBottom: spacing.sm,
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
    ...typography.cardTitle,
    color: colors.textPrimary,
    textAlign: 'center',
    marginBottom: spacing.sm,
  },
  badgeRow: {
    alignSelf: 'center',
  },
  verifiedBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: 'rgba(22, 163, 74, 0.12)',
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
    borderRadius: layout.chipRadius,
  },
  verifiedBadgeText: {
    ...typography.statusLabel,
    color: colors.success,
    fontWeight: '600',
  },
  sectionHeading: {
    ...typography.sectionLabel,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  menuCard: {
    borderRadius: layout.cardRadius,
    paddingVertical: 0,
    paddingHorizontal: 0,
    overflow: 'hidden',
  },
  menuRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    gap: spacing.sm,
    backgroundColor: colors.card,
  },
  menuRowBorder: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  menuRowPressed: {
    backgroundColor: colors.background,
  },
  menuIconWrap: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.background,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  menuIconWrapDanger: {
    backgroundColor: 'rgba(239, 68, 68, 0.08)',
    borderColor: 'rgba(239, 68, 68, 0.25)',
  },
  menuLabel: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    flex: 1,
  },
  menuLabelDanger: {
    color: colors.danger,
  },
});
