/**
 * On-device photo quality check for the visitor's registration photo
 * (Phase 2). Runs Google ML Kit face detection on the photo file and measures
 * light and sharpness on a small grayscale copy; the rules themselves live in
 * utils/photoQualityRules (pure, unit tested).
 *
 * Everything stays on the phone: nothing is uploaded, logged or stored, and
 * the small copy's temporary file is deleted. The photo itself is left alone —
 * it belongs to the caller. Never throws: a detector or image error gives an
 * 'unknown' result (no rejection), never a 'fail'.
 *
 * Web: see photoQuality.web.js (always 'unknown').
 */
import { RNMLKitFaceDetector } from '@infinitered/react-native-mlkit-face-detection';
import { File } from 'expo-file-system';
import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
// The decoder alone (the package index also loads the encoder, which needs Buffer).
import decodeJpeg from 'jpeg-js/lib/decoder';
import {
  assessPhotoQuality,
  base64ToBytes,
  toGray,
  unknownPhotoQuality,
} from '../utils/photoQualityRules';

/** Width of the small copy used for light and sharpness. */
const ANALYSIS_WIDTH = 240;

/** As verified in Stage 1 (src/dev/FaceDetectionProbe.js). */
const DETECTOR_OPTIONS = {
  performanceMode: 'fast',
  landmarkMode: true,
  classificationMode: true, // eye-open probabilities
  contourMode: false,
  isTrackingEnabled: false,
};

/** @type {Promise<RNMLKitFaceDetector> | null} */
let detectorPromise = null;

/**
 * One shared ML Kit detector, created on first use. The library reports a
 * failed initialize through `status`, not by throwing; a failure is retried
 * on the next call.
 */
function getDetector() {
  if (!detectorPromise) {
    detectorPromise = (async () => {
      const detector = new RNMLKitFaceDetector(DETECTOR_OPTIONS, true);
      await detector.initialize();
      if (detector.status !== 'ready') {
        throw new Error(detector.error || 'Face detector is not available.');
      }
      return detector;
    })();
    detectorPromise.catch(() => {
      detectorPromise = null;
    });
  }
  return detectorPromise;
}

/**
 * ML Kit faces in the photo, or null when detection could not run. On
 * Android the result has no `success` flag (errors reject instead), so a
 * result with a `faces` array is the success signal.
 * @param {string} uri
 * @returns {Promise<unknown[] | null>}
 */
async function detectFaces(uri) {
  try {
    const detector = await getDetector();
    const result = await detector.detectFaces(uri);
    return Array.isArray(result?.faces) ? result.faces : null;
  } catch {
    return null;
  }
}

/** @param {{ release?: () => void } | null | undefined} ref */
function releaseQuietly(ref) {
  try {
    ref?.release?.();
  } catch {
    // already released
  }
}

/** @param {string | null} uri */
function deleteQuietly(uri) {
  if (!uri) return;
  try {
    const file = new File(uri);
    if (file.exists) file.delete();
  } catch {
    // The cache directory is cleared by the OS eventually.
  }
}

/**
 * The photo's size (after orientation) and a small grayscale copy of it.
 * Either is null when it could not be read.
 * @param {string} uri
 */
async function readSmallGray(uri) {
  /** @type {any[]} */
  const refs = [];
  /** @type {string | null} */
  let tempUri = null;
  let size = null;
  try {
    const originalContext = ImageManipulator.manipulate(uri);
    refs.push(originalContext);
    const original = await originalContext.renderAsync();
    refs.push(original);
    size = { width: original.width, height: original.height };

    const smallContext = ImageManipulator.manipulate(original).resize({ width: ANALYSIS_WIDTH });
    refs.push(smallContext);
    const small = await smallContext.renderAsync();
    refs.push(small);
    const saved = await small.saveAsync({ format: SaveFormat.JPEG, compress: 0.92, base64: true });
    tempUri = saved.uri;
    if (!saved.base64) return { size, gray: null };

    const decoded = decodeJpeg(base64ToBytes(saved.base64), {
      useTArray: true,
      formatAsRGBA: true,
      maxResolutionInMP: 1,
      maxMemoryUsageInMB: 32,
    });
    return { size, gray: toGray(decoded.data, decoded.width, decoded.height, 4) };
  } catch {
    return { size, gray: null };
  } finally {
    refs.reverse().forEach(releaseQuietly);
    deleteQuietly(tempUri);
  }
}

/**
 * Faster path for large photos: the original is decoded once, ML Kit runs on
 * a `detectionWidth` copy and light/sharpness on a smaller one. Face boxes
 * then refer to the detection copy, so its size is returned with them. Both
 * temporary files are deleted.
 * @param {string} uri
 * @param {number} detectionWidth
 */
async function detectOnCopy(uri, detectionWidth) {
  /** @type {any[]} */
  const refs = [];
  /** @type {string | null} */
  let detectionUri = null;
  try {
    const originalContext = ImageManipulator.manipulate(uri);
    refs.push(originalContext);
    const original = await originalContext.renderAsync();
    refs.push(original);
    const width = Math.min(detectionWidth, original.width);

    const midContext = ImageManipulator.manipulate(original).resize({ width });
    refs.push(midContext);
    const mid = await midContext.renderAsync();
    refs.push(mid);
    const saved = await mid.saveAsync({ format: SaveFormat.JPEG, compress: 0.9 });
    detectionUri = saved.uri;

    const [faces, pixels] = await Promise.all([detectFaces(saved.uri), readSmallGray(saved.uri)]);
    return { faces, size: { width: saved.width, height: saved.height }, gray: pixels.gray };
  } catch {
    // Fall back to the plain path on the original file.
    const [faces, pixels] = await Promise.all([detectFaces(uri), readSmallGray(uri)]);
    return { faces, size: pixels.size, gray: pixels.gray, fromOriginal: true };
  } finally {
    refs.reverse().forEach(releaseQuietly);
    deleteQuietly(detectionUri);
  }
}

/**
 * Deletes a photo file the caller no longer needs (e.g. a preview snapshot
 * or a rejected capture). Never throws.
 * @param {string | null | undefined} uri
 */
export function discardPhoto(uri) {
  deleteQuietly(typeof uri === 'string' ? uri : null);
}

/**
 * Checks a captured photo. Resolves to a PhotoQualityResult
 * (utils/photoQualityRules): `status` 'pass' | 'fail' | 'unknown', the failed
 * `reasons`, one short visitor `message`, and per-check `checks`.
 * @param {string} uri — local file URI of the photo (e.g. from takePictureAsync)
 * @param {object} [options]
 * @param {{ width: number; height: number }} [options.imageSize] — the photo's
 *   size when the caller knows it (otherwise read from the file); ignored
 *   with `detectionWidth`, where boxes refer to the smaller copy
 * @param {number} [options.detectionWidth] — run ML Kit on a copy this wide
 *   (faster for full-size camera photos)
 * @param {Partial<typeof import('../utils/photoQualityRules').PHOTO_QUALITY_THRESHOLDS>} [options.thresholds]
 * @returns {Promise<import('../utils/photoQualityRules').PhotoQualityResult & { durationMs: number }>}
 */
export async function checkPhotoQuality(uri, options = {}) {
  const started = Date.now();
  if (typeof uri !== 'string' || !uri) return { ...unknownPhotoQuality(), durationMs: 0 };
  try {
    if (options.detectionWidth) {
      const copy = await detectOnCopy(uri, options.detectionWidth);
      const result = assessPhotoQuality({
        faces: copy.faces,
        imageSize: copy.fromOriginal ? (options.imageSize ?? copy.size) : copy.size,
        gray: copy.gray,
        thresholds: options.thresholds,
      });
      return { ...result, durationMs: Date.now() - started };
    }
    const [faces, pixels] = await Promise.all([detectFaces(uri), readSmallGray(uri)]);
    const result = assessPhotoQuality({
      faces,
      imageSize: options.imageSize ?? pixels.size,
      gray: pixels.gray,
      thresholds: options.thresholds,
    });
    return { ...result, durationMs: Date.now() - started };
  } catch {
    return { ...unknownPhotoQuality(), durationMs: Date.now() - started };
  }
}

/** Whether this platform can run the quality check (false on web). */
export const PHOTO_QUALITY_SUPPORTED = true;
