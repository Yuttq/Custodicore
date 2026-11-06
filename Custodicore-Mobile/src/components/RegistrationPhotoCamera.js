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
// Native: on-device ML Kit quality check. Web resolves photoQuality.web.js
// (no ML Kit; always 'unknown'), so web keeps manual capture only.
import {
  checkPhotoQuality,
  discardPhoto,
  PHOTO_QUALITY_SUPPORTED,
} from '../services/photoQuality';
import {
  AUTO_CAPTURE_CONFIG,
  AUTO_CAPTURE_MESSAGES,
  createAutoCaptureLoop,
  finalPhotoDecision,
} from '../utils/autoCapture';
import { hasVideoInputDevice, validateRegistrationPhoto } from '../utils/documentImageRules';

/** JPEG at this quality keeps a front-camera photo well under the 5 MB limit. */
const PICTURE_OPTIONS = { quality: 0.6, imageType: 'jpg' };

/** Preview snapshots for the automatic check only: small, silent, always deleted. */
const SNAPSHOT_OPTIONS = { quality: 0.3, imageType: 'jpg', shutterSound: false };

/** ML Kit runs on a copy this wide (faster than the full-size photo). */
const DETECTION_WIDTH = 480;

/** Automatic capture: phones only, when the quality check is available. */
const AUTO_CAPTURE_ENABLED = PHOTO_QUALITY_SUPPORTED && Platform.OS !== 'web';

/**
 * Portrait 3:4 (width:height), the shape of a front-camera photo held
 * upright, so the live preview and the review show the same framing.
 */
const FRAME_ASPECT = 3 / 4;

/** The face guide's width as a share of the frame width. */
const FACE_GUIDE_WIDTH = 0.62;

/**
 * The largest 3:4 frame that fits the space measured by onLayout.
 * @param {{ width: number, height: number } | null} area
 */
function frameSizeFor(area) {
  if (!area || area.width <= 0 || area.height <= 0) return null;
  const width = Math.min(area.width, area.height * FRAME_ASPECT);
  return { width, height: width / FRAME_ASPECT };
}

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
 *
 * On phones the photo is taken automatically (Phase 2): silent preview
 * snapshots are checked on-device one at a time (utils/autoCapture +
 * services/photoQuality) and, once the face has passed several checks in a
 * row and stayed put, one full-quality photo is taken and checked again
 * before review. "Take Photo" appears as a fallback after a short wait or
 * when the check cannot run. This judges photo quality only — staff still
 * review every photo. Web keeps manual capture.
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
  // Space left for the photo frame; the frame is the largest 3:4 box in it.
  const [frameArea, setFrameArea] = useState(/** @type {{ width: number, height: number } | null} */ (null));
  const cameraRef = useRef(/** @type {CameraView | null} */ (null));
  const frame = frameSizeFor(frameArea);

  // Automatic capture (phones). One short status line, the guide turns teal
  // while the checks pass, and "Take Photo" once the fallback is available.
  const [cameraReady, setCameraReady] = useState(false);
  const [status, setStatus] = useState(AUTO_CAPTURE_MESSAGES.start);
  const [passing, setPassing] = useState(false);
  const [manualAvailable, setManualAvailable] = useState(!AUTO_CAPTURE_ENABLED);
  // A failed-check hint carried onto the review of a manual photo.
  const [reviewNote, setReviewNote] = useState(/** @type {string | null} */ (null));
  const mountedRef = useRef(true);
  const loopRef = useRef(/** @type {ReturnType<typeof createAutoCaptureLoop> | null} */ (null));
  const captureBusyRef = useRef(false);
  const manualFailuresRef = useRef(0);
  // Manual fallback deadline for this camera session (kept across retries).
  const manualDeadlineRef = useRef(/** @type {number | null} */ (null));
  const nextStartDelayRef = useRef(AUTO_CAPTURE_CONFIG.firstSnapshotDelayMs);
  const captureRef = useRef(/** @type {((options: { manual: boolean }) => void) | null} */ (null));

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      loopRef.current?.stop();
    };
  }, []);
  const onFrameAreaLayout = useCallback((event) => {
    const { width, height } = event.nativeEvent.layout;
    setFrameArea((prev) => (prev && prev.width === width && prev.height === height ? prev : { width, height }));
  }, []);

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
      setStatus(AUTO_CAPTURE_MESSAGES.start);
      setPassing(false);
      setManualAvailable(!AUTO_CAPTURE_ENABLED);
      setReviewNote(null);
      manualFailuresRef.current = 0;
      manualDeadlineRef.current = null;
      nextStartDelayRef.current = AUTO_CAPTURE_CONFIG.firstSnapshotDelayMs;
    }
  }, [visible]);

  const showCamera = phase === 'ready' || phase === 'capturing';

  // The preview remounts after review; wait for it to be ready again.
  useEffect(() => {
    if (!showCamera) setCameraReady(false);
  }, [showCamera]);

  /**
   * Takes the full-quality photo (automatic or "Take Photo"), checks it, and
   * opens review or returns to the camera with a short hint. Never runs
   * twice at once, and waits for any preview snapshot in progress.
   */
  const capture = useCallback(async ({ manual }) => {
    if (captureBusyRef.current || !cameraRef.current) return;
    captureBusyRef.current = true;
    setPhase('capturing');
    setError(null);
    setStatus(AUTO_CAPTURE_MESSAGES.capturing);
    let photo = null;
    try {
      // The camera takes one picture at a time: let a snapshot finish first.
      await loopRef.current?.stop();
      if (!mountedRef.current) return;
      const camera = cameraRef.current;
      if (!camera) throw new Error('Camera closed');
      const picture = await camera.takePictureAsync(PICTURE_OPTIONS);
      if (!picture?.uri) throw new Error('No picture');
      photo = await toRegistrationPhoto(picture);
      const invalid = validateRegistrationPhoto(photo);
      if (invalid) {
        discardPhoto(photo.uri);
        if (!mountedRef.current) return;
        setError(invalid);
        setStatus(AUTO_CAPTURE_MESSAGES.start);
        setPhase('ready');
        return;
      }

      // Final check of the photo itself (phones only). An error is 'unknown'
      // and never blocks review.
      const quality = AUTO_CAPTURE_ENABLED
        ? await checkPhotoQuality(photo.uri, { detectionWidth: DETECTION_WIDTH })
        : null;
      if (!mountedRef.current) {
        discardPhoto(photo.uri);
        return;
      }
      const decision = finalPhotoDecision(quality, {
        manual,
        manualFailures: manualFailuresRef.current,
      });
      manualFailuresRef.current = decision.manualFailures;
      if (decision.action === 'retry') {
        discardPhoto(photo.uri);
        setStatus(decision.message ?? AUTO_CAPTURE_MESSAGES.start);
        setPassing(false);
        nextStartDelayRef.current = AUTO_CAPTURE_CONFIG.retryDelayMs;
        setPhase('ready');
        return;
      }
      setReviewNote(decision.message);
      setDraft(photo);
      setPhase('review');
    } catch {
      if (photo) discardPhoto(photo.uri);
      if (!mountedRef.current) return;
      setError('Could not take the photo. Please try again.');
      setStatus(AUTO_CAPTURE_MESSAGES.start);
      // Something went wrong: never leave the visitor without a button.
      setManualAvailable(true);
      setPhase('ready');
    } finally {
      captureBusyRef.current = false;
    }
  }, []);
  // The loop calls the latest capture through this ref (set outside render).
  useEffect(() => {
    captureRef.current = capture;
  }, [capture]);

  // The snapshot loop runs only while the live camera is showing and ready;
  // leaving 'ready' (capturing, review, closing) or unmounting stops it.
  useEffect(() => {
    if (!AUTO_CAPTURE_ENABLED || !visible || phase !== 'ready' || !cameraReady) return undefined;
    if (manualDeadlineRef.current == null) {
      manualDeadlineRef.current = Date.now() + AUTO_CAPTURE_CONFIG.manualFallbackMs;
    }
    const loop = createAutoCaptureLoop({
      snapshot: async () => {
        const camera = cameraRef.current;
        if (!camera) return null;
        const shot = await camera.takePictureAsync(SNAPSHOT_OPTIONS);
        return shot?.uri ? shot : null;
      },
      assess: (shot) => checkPhotoQuality(shot.uri, { detectionWidth: DETECTION_WIDTH }),
      discard: (shot) => discardPhoto(shot.uri),
      onUpdate: ({ message, passing: isPassing }) => {
        if (!mountedRef.current) return;
        setStatus(message);
        setPassing(isPassing);
      },
      onCapture: () => {
        captureRef.current?.({ manual: false });
      },
      onManualAvailable: () => {
        if (mountedRef.current) setManualAvailable(true);
      },
      config: {
        ...AUTO_CAPTURE_CONFIG,
        manualFallbackMs: Math.max(0, manualDeadlineRef.current - Date.now()),
      },
    });
    loopRef.current = loop;
    loop.start(nextStartDelayRef.current);
    nextStartDelayRef.current = AUTO_CAPTURE_CONFIG.firstSnapshotDelayMs;
    return () => {
      loop.stop();
    };
  }, [visible, phase, cameraReady]);

  const retake = useCallback(() => {
    // The rejected draft was never handed over: its file can go.
    if (draft) discardPhoto(draft.uri);
    setDraft(null);
    setError(null);
    setReviewNote(null);
    setStatus(AUTO_CAPTURE_MESSAGES.start);
    setPassing(false);
    setPhase('ready');
  }, [draft]);

  const takePhotoManually = useCallback(() => {
    if (phase !== 'ready') return;
    capture({ manual: true });
  }, [phase, capture]);

  const confirmDraft = useCallback(() => {
    if (!draft) return;
    onConfirm(draft);
    onClose();
  }, [draft, onConfirm, onClose]);

  // "Take Photo": always on web; on phones once the fallback is available.
  const showManualButton = manualAvailable || !AUTO_CAPTURE_ENABLED;

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
            <View style={styles.frameArea} onLayout={onFrameAreaLayout}>
              {frame ? (
                <View style={[styles.frameWrap, frame]}>
                  {/* Front camera: the live view is mirrored like a selfie; the
                      saved photo is not (`mirror` stays off). */}
                  <CameraView
                    ref={cameraRef}
                    style={styles.fill}
                    facing="front"
                    onCameraReady={() => setCameraReady(true)}
                    // No flash on the preview for each silent check snapshot.
                    animateShutter={!AUTO_CAPTURE_ENABLED}
                    onMountError={() => setPhase('unavailable')}
                  />
                  {/* Oval face guide; never blocks touches or the controls below.
                      Teal while the automatic checks pass. */}
                  <View style={styles.guideLayer}>
                    <View
                      style={[
                        styles.faceGuide,
                        passing && styles.faceGuidePassing,
                        {
                          width: frame.width * FACE_GUIDE_WIDTH,
                          height: frame.width * FACE_GUIDE_WIDTH,
                          borderRadius: (frame.width * FACE_GUIDE_WIDTH) / 2,
                        },
                      ]}
                    />
                  </View>
                </View>
              ) : null}
            </View>
            {AUTO_CAPTURE_ENABLED ? (
              // One short status line, announced by screen readers as it changes.
              <Text
                style={[styles.status, passing && styles.statusPassing]}
                accessibilityLiveRegion="polite"
                accessibilityRole="text"
              >
                {status}
              </Text>
            ) : (
              <Text style={styles.hint}>
                Center your face in the oval. Use good light, without a hat or sunglasses.
              </Text>
            )}
            {error ? <Text style={styles.error}>{error}</Text> : null}
            {showManualButton ? (
              <Pressable
                style={[styles.primaryBtn, phase === 'capturing' && styles.btnDisabled]}
                onPress={takePhotoManually}
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
            ) : (
              // Keeps the layout steady until the fallback button appears.
              <View style={styles.buttonPlaceholder} />
            )}
          </View>
        ) : null}

        {phase === 'review' && draft ? (
          <View style={styles.body}>
            <View style={styles.frameArea} onLayout={onFrameAreaLayout}>
              {frame ? (
                <View style={[styles.frameWrap, frame]}>
                  {/* Shown as saved: unmirrored and upright, exactly the file
                      that is uploaded. Only the live view above is mirrored. */}
                  <Image
                    source={{ uri: draft.uri }}
                    style={styles.fill}
                    resizeMode="cover"
                    accessibilityLabel="Preview of your photo"
                  />
                </View>
              ) : null}
            </View>
            {reviewNote ? (
              // A manual photo that did not pass the check: say why, still allow it.
              <Text style={styles.status} accessibilityLiveRegion="polite">
                {reviewNote}
              </Text>
            ) : (
              <Text style={styles.hint}>Check that your face is clear before using this photo.</Text>
            )}
            <Text style={styles.hint}>This is how your saved photo will look.</Text>
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
  // Fills the space above the hint and buttons; the 3:4 frame is centred in it.
  frameArea: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
  },
  // Width and height come from frameSizeFor() (3:4 portrait).
  frameWrap: {
    borderRadius: layout.cardRadius,
    overflow: 'hidden',
    backgroundColor: colors.primaryNavy,
  },
  fill: {
    flex: 1,
  },
  guideLayer: {
    ...StyleSheet.absoluteFillObject,
    pointerEvents: 'none',
    alignItems: 'center',
    justifyContent: 'center',
  },
  // A circle stretched vertically into an oval, slightly above centre.
  faceGuide: {
    borderWidth: 2,
    borderColor: 'rgba(255, 255, 255, 0.75)',
    transform: [{ translateY: -12 }, { scaleY: 1.3 }],
  },
  faceGuidePassing: {
    borderWidth: 3,
    borderColor: colors.primaryTeal,
  },
  // The one status line under the camera (automatic capture).
  status: {
    ...typography.body,
    fontWeight: '600',
    color: colors.textPrimary,
    textAlign: 'center',
  },
  statusPassing: {
    color: colors.primaryTeal,
  },
  // Same height as the Take Photo button, so nothing jumps when it appears.
  buttonPlaceholder: {
    minHeight: 48,
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
