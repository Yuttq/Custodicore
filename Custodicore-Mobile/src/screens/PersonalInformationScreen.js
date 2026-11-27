import Ionicons from '@expo/vector-icons/Ionicons';
import React, { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { CustomInput, LoadingSpinner } from '../components';
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
import { formatDate } from '../utils';
import { validateProfileFields } from '../utils/profileValidation';
import SuffixSelect from '../components/SuffixSelect';
import { inputFilters } from '../utils/registrationValidation';
import {
  composeVisitorFullName,
  emptyVisitorNameParts,
  getVisitorNameErrors,
  toVisitorNameFields,
  visitorNameChanged,
  visitorNamePartsFromUser,
} from '../utils/visitorName';

const inputLabelStyle = { color: colors.textSecondary };

const GENDER_LABELS = { male: 'Male', female: 'Female', other: 'Other' };

/** Backend (camelCase) field → form field, for 422 validation messages. */
const SERVER_FIELD_TO_FORM = {
  fullName: 'lastName',
  lastName: 'lastName',
  firstName: 'firstName',
  middleName: 'middleName',
  suffix: 'suffix',
  contactNumber: 'phone',
  address: 'address',
};

/**
 * Label left, value right, one compact row.
 * @param {{ label: string; value: string; last?: boolean }} props
 */
function InfoRow({ label, value, last = false }) {
  return (
    <View style={[styles.row, last && styles.rowLast]}>
      <Text style={styles.rowLabel}>{label}</Text>
      <Text style={styles.rowValue} accessibilityLabel={`${label}: ${value}`}>
        {value}
      </Text>
    </View>
  );
}

/**
 * Card title with an optional right-hand element (lock icon or Edit link).
 * @param {{ title: string; right?: React.ReactNode }} props
 */
function SectionHeader({ title, right }) {
  return (
    <View style={styles.sectionHeader}>
      <Text style={styles.sectionTitle} accessibilityRole="header">
        {title}
      </Text>
      {right}
    </View>
  );
}

/**
 * Visitor personal information — GET /api/me, edits via PATCH /api/me.
 *
 * Two cards: Personal Details (name, email, date of birth, gender — a lock
 * icon once staff have verified the visitor, when the backend refuses name
 * and date-of-birth changes) and Contact (phone and address, the fields the
 * visitor can edit). Before verification the name is editable too. Email is
 * never editable here. The PDLs and their relationships are on My PDLs.
 */
export default function PersonalInformationScreen({ navigation }) {
  const { user, refreshUser, updateProfile } = useAuth();
  const [loading, setLoading] = useState(!user);
  const [loadError, setLoadError] = useState(/** @type {string | null} */ (null));
  const [isEditing, setIsEditing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState(/** @type {string | null} */ (null));
  const [draft, setDraft] = useState({ name: emptyVisitorNameParts(), phone: '', address: '' });
  const [fieldErrors, setFieldErrors] = useState(/** @type {Record<string, string>} */ ({}));

  const isVerified = user?.verificationStatus === 'verified';

  const load = useCallback(async () => {
    setLoadError(null);
    try {
      await refreshUser();
    } catch (e) {
      if (e?.status !== 401) {
        setLoadError(e?.message || 'Could not load your profile.');
      }
    } finally {
      setLoading(false);
    }
  }, [refreshUser]);

  useEffect(() => {
    load();
    // Load once on open; refreshUser identity changes with the session only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const startEditing = useCallback(() => {
    setDraft({
      // Last / First / Middle / Suffix; an older profile is split as a guess.
      name: visitorNamePartsFromUser(user),
      phone: user?.contactNumber ?? '',
      address: user?.address ?? '',
    });
    setFieldErrors({});
    setSaveError(null);
    setIsEditing(true);
  }, [user]);

  const cancelEditing = useCallback(() => {
    setFieldErrors({});
    setSaveError(null);
    setIsEditing(false);
  }, []);

  const saveProfile = useCallback(async () => {
    if (saving) return;
    const { errors: contactErrors } = validateProfileFields({
      fullName: composeVisitorFullName(draft.name),
      phone: draft.phone,
      address: draft.address,
    });
    // The name is checked part by part (and only while it can be edited).
    const { fullName: _unused, ...otherErrors } = contactErrors;
    const allErrors = { ...otherErrors, ...(isVerified ? {} : getVisitorNameErrors(draft.name)) };
    if (Object.keys(allErrors).length > 0) {
      setFieldErrors(allErrors);
      return;
    }
    setFieldErrors({});
    setSaveError(null);

    /** @type {Record<string, string>} */
    const fields = {
      contactNumber: draft.phone.trim(),
      address: draft.address.trim(),
    };
    // Name is locked after verification — only send it when it actually
    // changed: the parts plus fullName (natural order, for older servers).
    if (!isVerified && visitorNameChanged(draft.name, user)) {
      Object.assign(fields, toVisitorNameFields(draft.name));
    }

    setSaving(true);
    try {
      await updateProfile(fields);
      setIsEditing(false);
    } catch (e) {
      if (e?.status === 422 && e.errors && typeof e.errors === 'object') {
        /** @type {Record<string, string>} */
        const mapped = {};
        Object.entries(e.errors).forEach(([key, messages]) => {
          const formKey = SERVER_FIELD_TO_FORM[key];
          if (formKey && Array.isArray(messages) && messages[0]) mapped[formKey] = messages[0];
        });
        setFieldErrors(mapped);
        if (Object.keys(mapped).length === 0) setSaveError(e.message);
      } else if (e?.status !== 401) {
        // 409 (name locked after verification), network and server errors.
        setSaveError(e?.message || 'Could not save your profile. Please try again.');
      }
    } finally {
      setSaving(false);
    }
  }, [saving, draft, user, isVerified, updateProfile]);

  const genderLabel = user?.gender ? GENDER_LABELS[user.gender] ?? user.gender : 'Not specified';
  const dobLabel = user?.dateOfBirth ? formatDate(user.dateOfBirth) || user.dateOfBirth : '—';
  const editingName = isEditing && !isVerified;

  return (
    <SafeAreaView style={commonStyles.safeScreen} edges={['top', 'left', 'right', 'bottom']}>
      <StackScreenHeader title="Personal Information" navigation={navigation} />

      <ScrollView
        contentContainerStyle={commonStyles.scrollContent}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        {loading && !user ? (
          <LoadingSpinner message="Loading profile…" compact />
        ) : !user ? (
          <Card style={styles.card}>
            <Text style={styles.errorText}>{loadError || 'Could not load your profile.'}</Text>
            <Button title="Retry" onPress={load} accessibilityLabel="Retry loading profile" />
          </Card>
        ) : (
          <>
            {loadError ? <Text style={styles.errorText}>{loadError}</Text> : null}

            <Card style={styles.card}>
              <SectionHeader
                title="Personal Details"
                right={
                  isVerified ? (
                    <Ionicons
                      name="lock-closed"
                      size={16}
                      color={colors.textSecondary}
                      accessibilityLabel="Locked after verification"
                    />
                  ) : null
                }
              />
              {editingName ? (
                <>
                  {[
                    ['last', 'lastName', 'Last Name'],
                    ['first', 'firstName', 'First Name'],
                    ['middle', 'middleName', 'Middle Name (optional)'],
                  ].map(([part, errorKey, label]) => (
                    <CustomInput
                      key={part}
                      label={label}
                      value={draft.name[part]}
                      onChangeText={(t) => {
                        setDraft((d) => ({ ...d, name: { ...d.name, [part]: inputFilters.name(t) } }));
                        setFieldErrors((e) => ({ ...e, [errorKey]: '' }));
                      }}
                      placeholder={label.replace(' (optional)', '')}
                      autoCapitalize="words"
                      autoCorrect={false}
                      error={fieldErrors[errorKey]}
                      labelStyle={inputLabelStyle}
                      style={styles.inputSpacing}
                    />
                  ))}
                  <SuffixSelect
                    value={draft.name.suffix}
                    onChange={(s) => {
                      setDraft((d) => ({ ...d, name: { ...d.name, suffix: s } }));
                      setFieldErrors((e) => ({ ...e, suffix: '' }));
                    }}
                    error={fieldErrors.suffix}
                    labelStyle={inputLabelStyle}
                  />
                </>
              ) : (
                <InfoRow label="Full Name" value={user.fullName || '—'} />
              )}
              <InfoRow label="Email" value={user.email || '—'} />
              <InfoRow label="Date of Birth" value={dobLabel} />
              <InfoRow label="Gender" value={genderLabel} last />
            </Card>

            <Card style={[styles.card, styles.cardGap]}>
              <SectionHeader
                title="Contact"
                right={
                  isEditing ? null : (
                    <Pressable
                      onPress={startEditing}
                      hitSlop={10}
                      style={styles.editLink}
                      accessibilityRole="button"
                      accessibilityLabel="Edit contact details"
                    >
                      <Ionicons name="create-outline" size={16} color={colors.primaryTealDark} />
                      <Text style={styles.editLinkText}>Edit</Text>
                    </Pressable>
                  )
                }
              />
              {isEditing ? (
                <>
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
                  {saveError ? (
                    <Text style={styles.errorText} accessibilityRole="alert">
                      {saveError}
                    </Text>
                  ) : null}
                  <View style={styles.editActionsRow}>
                    <View style={styles.editActionGrow}>
                      <Button
                        title={saving ? 'Saving…' : 'Save'}
                        onPress={saveProfile}
                        loading={saving}
                        disabled={saving}
                        accessibilityLabel="Save profile"
                      />
                    </View>
                    <View style={styles.editActionGrow}>
                      <Pressable
                        onPress={cancelEditing}
                        disabled={saving}
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
                  <InfoRow label="Phone" value={user.contactNumber || '—'} />
                  <InfoRow label="Address" value={user.address || '—'} last />
                </>
              )}
            </Card>
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  card: {
    borderRadius: layout.cardRadius,
    paddingHorizontal: spacing.md,
    paddingTop: spacing.sm,
    paddingBottom: spacing.xs,
  },
  cardGap: {
    marginTop: spacing.sm,
  },
  sectionHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: spacing.xs,
    marginBottom: spacing.xs,
  },
  sectionTitle: {
    ...typography.statusLabel,
    textTransform: 'uppercase',
    letterSpacing: 0.6,
    color: colors.textSecondary,
  },
  editLink: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
  },
  editLinkText: {
    ...typography.metadata,
    fontWeight: '600',
    color: colors.primaryTealDark,
  },
  errorText: {
    ...typography.metadata,
    color: colors.dangerStrong,
    marginBottom: spacing.sm,
  },
  // Label left, value right, one line each.
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    paddingVertical: spacing.sm,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  rowLast: {
    borderBottomWidth: 0,
  },
  rowLabel: {
    ...typography.metadata,
    color: colors.textSecondary,
    width: '38%',
  },
  rowValue: {
    ...typography.metadata,
    color: colors.textPrimary,
    fontWeight: '600',
    flex: 1,
    textAlign: 'right',
  },
  inputSpacing: {
    marginBottom: spacing.sm,
  },
  editActionsRow: {
    flexDirection: 'row',
    gap: spacing.sm,
    marginTop: spacing.xs,
    marginBottom: spacing.sm,
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
    paddingHorizontal: spacing.md,
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
