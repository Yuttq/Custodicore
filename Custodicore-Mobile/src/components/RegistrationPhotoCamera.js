import Ionicons from '@expo/vector-icons/Ionicons';
import { CameraView } from 'expo-camera';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Image,
  Modal,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, layout, spacing, typography } from '../designSystem';
import { ensureRegistrationPhotoPermission } from '../services/mediaPermissions';
import { hasVideoInputDevice, validateRegistrationPhoto } from '../utils/documentImageRules';

/** JPEG at this quality keeps a front-camera photo well under the 5 MB limit. */
const PICTURE_OPTIONS = { quality: 0.6, imageType: 'jpg' };

const UNAVAILABLE_MESSAGE =
  "This browser can't open your camera. Use the CustodiCore app, or a browser with camera access.";

/**
 * @typedef {{ uri: string; fileName: string; mimeType: string; fileSize: number | null; webFile?: File }} RegistrationPhoto
 */

/**
 * The camera result as an upload-ready photo. On web the capture is a
 * `data:` URL, which a browser FormData cannot send, so it becomes a `File`
 * here (and its size is known). Native captures are JPEG files with no size.
 * @param {{ uri: string }} picture
 * @returns {Promise<RegistrationPhoto>}
 */
async function toRegistrationPhoto(picture) {
  if (Platform.OS === 'web') {
    const blob = await (await fetch(picture.uri)).blob();
    const webFile = new File([blob], 'photo.jpg', { type: blob.type || 'image/jpeg' });
    return { uri: picture.uri, fileName: 'photo.jpg', mimeType: webFile.type, fileSize: webFile.size, webFile };
  }
  return { uri: picture.uri, fileName: 'photo.jpg', mimeType: 'image/jpeg', fileSize: null };
}

/**
 * Web only: true when the browser has the camera API and lists a camera.
 * expo-camera's own check only looks for the API, so a computer without a
 * camera would otherwise end up on the "permission denied" screen.
 */
async function isWebCameraAvailable() {
  if (!(await CameraView.isAvailableAsync().catch(() => false))) return false;
  try {
    return hasVideoInputDevice(await navigator.mediaDevices.enumerateDevices());
  } catch {
    // Device list unavailable: let the permission request decide.
    return true;
  }
}

/**
 * Full-screen front camera for the required registration photo. Camera
 * capture only: there is deliberately no gallery or file option, and on web
 * a browser without camera access gets a message instead of a file picker.
 * `onConfirm` runs only after "Use Photo"; closing at any point (including
 * during a retake) leaves the previously confirmed photo untouched.
 * @param {object} props
 * @param {boolean} props.visible
 * @param {() => void} props.onClose
 * @param {(photo: RegistrationPhoto) => void} props.onConfirm
 */
export default function RegistrationPhotoCamera({ visible, onClose, onConfirm }) {
  const insets = useSafeAreaInsets();
  /** @type {['idle' | 'loading' | 'denied' | 'unavailable' | 'ready' | 'capturing' | 'review', Function]} */
  const [phase, setPhase] = useState('idle');
  const [draft, setDraft] = useState(/** @type {RegistrationPhoto | null} */ (null));
  const [error, setError] = useState(/** @type {string | null} */ (null));
  const cameraRef = useRef(/** @type {CameraView | null} */ (null));

  const requestAccess = useCallback(async () => {
    setPhase('loading');
    setError(null);
    if (Platform.OS === 'web' && !(await isWebCameraAvailable())) {
      setPhase('unavailable');
      return;
    }
    const result = await ensureRegistrationPhotoPermission();
    setPhase(result.granted ? 'ready' : 'denied');
  }, []);

  // The permission request starts from the Modal's onShow, not here: on
  // Android an Alert opened while the Modal is still appearing ends up
  // behind it, leaving the visitor stuck on "Preparing camera…".
  useEffect(() => {
    if (!visible) {
      setPhase('idle');
      setDraft(null);
      setError(null);
    }
  }, [visible]);

  const capture = useCallback(async () => {
    if (!cameraRef.current || phase !== 'ready') return;
    setPhase('capturing');
    setError(null);
    try {
      const picture = await cameraRef.current.takePictureAsync(PICTURE_OPTIONS);
      if (!picture?.uri) throw new Error('No picture');
      const photo = await toRegistrationPhoto(picture);
      const invalid = validateRegistrationPhoto(photo);
      if (invalid) {
        setError(invalid);
        setPhase('ready');
        return;
      }
      setDraft(photo);
      setPhase('review');
    } catch {
      setError('Could not take the photo. Please try again.');
      setPhase('ready');
    }
  }, [phase]);

  const retake = useCallback(() => {
    setDraft(null);
    setError(null);
    setPhase('ready');
  }, []);

  const confirmDraft = useCallback(() => {
    if (!draft) return;
    onConfirm(draft);
    onClose();
  }, [draft, onConfirm, onClose]);

  const showCamera = phase === 'ready' || phase === 'capturing';

  return (
    <Modal
      visible={visible}
      animationType="slide"
      onShow={requestAccess}
      onRequestClose={onClose}
      presentationStyle="fullScreen"
    >
      <View style={[styles.root, { paddingTop: insets.top, paddingBottom: insets.bottom }]}>
        <View style={styles.header}>
          <Text style={styles.title}>Take Your Photo</Text>
          <Pressable
            onPress={onClose}
            style={styles.closeBtn}
            accessibilityRole="button"
            accessibilityLabel="Close camera"
          >
            <Ionicons name="close" size={24} color={colors.textPrimary} />
          </Pressable>
        </View>

        {phase === 'loading' || phase === 'idle' ? (
          <View style={styles.centered}>
            <ActivityIndicator size="large" color={colors.primaryTeal} />
            <Text style={styles.hint}>Preparing camera…</Text>
          </View>
        ) : null}

        {phase === 'denied' ? (
          <View style={styles.centered}>
            <Ionicons name="camera-outline" size={48} color={colors.textSecondary} />
            <Text style={styles.messageTitle}>Camera access required</Text>
            <Text style={styles.message}>
              Allow camera access to take your photo, or open Settings to enable permission.
            </Text>
            <Pressable
              style={styles.primaryBtn}
              onPress={requestAccess}
              accessibilityRole="button"
              accessibilityLabel="Try again"
            >
              <Text style={styles.primaryBtnText}>Try Again</Text>
            </Pressable>
          </View>
        ) : null}

        {phase === 'unavailable' ? (
          <View style={styles.centered}>
            <Ionicons name="videocam-off-outline" size={48} color={colors.textSecondary} />
            <Text style={styles.messageTitle}>Camera not available</Text>
            <Text style={styles.message}>{UNAVAILABLE_MESSAGE}</Text>
          </View>
        ) : null}

        {showCamera ? (
          <View style={styles.body}>
            <View style={styles.frameWrap}>
              <CameraView
                ref={cameraRef}
                style={styles.fill}
                facing="front"
                onMountError={() => setPhase('unavailable')}
              />
            </View>
            <Text style={styles.hint}>Face the camera in good light, without a hat or sunglasses.</Text>
            {error ? <Text style={styles.error}>{error}</Text> : null}
            <Pressable
              style={[styles.primaryBtn, phase === 'capturing' && styles.btnDisabled]}
              onPress={capture}
              disabled={phase === 'capturing'}
              accessibilityRole="button"
              accessibilityLabel="Take photo"
              accessibilityState={{ disabled: phase === 'capturing', busy: phase === 'capturing' }}
            >
              {phase === 'capturing' ? (
                <ActivityIndicator color={colors.white} />
              ) : (
                <Text style={styles.primaryBtnText}>Take Photo</Text>
              )}
            </Pressable>
          </View>
        ) : null}

        {phase === 'review' && draft ? (
          <View style={styles.body}>
            <View style={styles.frameWrap}>
              <Image
                source={{ uri: draft.uri }}
                style={styles.fill}
                resizeMode="cover"
                accessibilityLabel="Preview of your photo"
              />
            </View>
            <Text style={styles.hint}>Check that your face is clear before using this photo.</Text>
            <View style={styles.row}>
              <Pressable
                style={[styles.secondaryBtn, styles.rowBtn]}
                onPress={retake}
                accessibilityRole="button"
                accessibilityLabel="Retake photo"
              >
                <Text style={styles.secondaryBtnText}>Retake</Text>
              </Pressable>
              <Pressable
                style={[styles.primaryBtn, styles.rowBtn]}
                onPress={confirmDraft}
                accessibilityRole="button"
                accessibilityLabel="Use this photo"
              >
                <Text style={styles.primaryBtnText}>Use Photo</Text>
              </Pressable>
            </View>
          </View>
        ) : null}
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: colors.background,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: layout.screenPadding,
    paddingVertical: spacing.sm,
  },
  title: {
    ...typography.cardTitle,
    color: colors.textPrimary,
  },
  closeBtn: {
    width: 44,
    height: 44,
    alignItems: 'center',
    justifyContent: 'center',
  },
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: layout.screenPadding,
    gap: spacing.md,
  },
  body: {
    flex: 1,
    paddingHorizontal: layout.screenPadding,
    paddingBottom: spacing.lg,
    gap: spacing.md,
  },
  frameWrap: {
    flex: 1,
    borderRadius: layout.cardRadius,
    overflow: 'hidden',
    backgroundColor: colors.primaryNavy,
  },
  fill: {
    flex: 1,
  },
  hint: {
    ...typography.metadata,
    color: colors.textSecondary,
    textAlign: 'center',
  },
  error: {
    ...typography.metadata,
    color: colors.danger,
    textAlign: 'center',
  },
  messageTitle: {
    ...typography.cardTitle,
    color: colors.textPrimary,
    textAlign: 'center',
  },
  message: {
    ...typography.body,
    color: colors.textSecondary,
    textAlign: 'center',
    lineHeight: 22,
  },
  row: {
    flexDirection: 'row',
    gap: spacing.md,
  },
  rowBtn: {
    flex: 1,
  },
  primaryBtn: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
    borderRadius: layout.buttonRadius,
    backgroundColor: colors.primaryTeal,
  },
  primaryBtnText: {
    ...typography.body,
    fontWeight: '600',
    color: colors.white,
  },
  secondaryBtn: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.primaryTeal,
  },
  secondaryBtnText: {
    ...typography.body,
    fontWeight: '600',
    color: colors.primaryTeal,
  },
  btnDisabled: {
    opacity: 0.6,
  },
});
