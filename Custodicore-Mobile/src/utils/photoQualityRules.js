/**
 * Visitor photo quality rules (Phase 2): is there exactly one face, is it
 * placed and sized for the oval guide, is the head roughly straight, are the
 * eyes open, is the lower face clearly uncovered (no hand over part of it),
 * is the light usable and is the photo sharp?
 *
 * Pure functions only (no React / React Native imports) so they can be unit
 * tested with `node --test`. src/services/photoQuality.js feeds them ML Kit
 * face detection results and the pixels of a small copy of the photo.
 *
 * This is photo quality checking only: it does not recognise or verify who
 * the person is, and it is no proof of liveness. Staff still review every
 * photo. Nothing here is uploaded or stored.
 *
 * Every check returns `{ status, reason?, value? }` where status is
 *   'pass'    — checked and acceptable
 *   'fail'    — checked and not acceptable; `reason` says why
 *   'unknown' — could not be checked (missing data, detector error): no
 *               reason is shown, but an unchecked essential check keeps the
 *               overall result from passing
 *   'skipped' — not applicable (e.g. no face to measure)
 */

/**
 * Lenient defaults: only clearly bad photos are rejected. Ratios are shares
 * of the photo's width/height; angles are degrees; luma is 0–255.
 */
export const PHOTO_QUALITY_THRESHOLDS = Object.freeze({
  // Face size (face box width ÷ photo width). The oval is 0.62 wide, so a
  // face wider than 0.6 cannot fit inside it.
  minFaceWidthRatio: 0.28,
  maxFaceWidthRatio: 0.6,
  // The oval face guide, as shares of the photo's width (X) and height (Y).
  // The camera preview frame and the saved photo are both 3:4 portrait, so
  // the same shares describe the drawn guide (see faceGuideLayout) and the
  // photo. The guide is symmetric left/right, so the mirrored preview and
  // the unmirrored photo agree.
  guideCenterX: 0.5,
  guideCenterY: 0.47,
  guideRadiusX: 0.31,
  guideRadiusY: 0.3,
  // The face's centre must lie within this share of the guide's radii.
  maxCenterOffset: 0.35,
  // How much of the face box may fall outside the photo before it counts as cut off.
  maxOutsideRatio: 0.08,
  // Head angles (ML Kit Euler angles, degrees).
  maxYaw: 20,
  maxPitch: 20,
  maxRoll: 20,
  // Eyes: reject when EITHER eye is reported below this.
  minEyeOpenProbability: 0.2,
  // Lighting over the face (or whole photo when there is no face).
  minMeanLuma: 50,
  maxMeanLuma: 215,
  darkPixelLuma: 20,
  brightPixelLuma: 245,
  maxDarkPixelRatio: 0.6,
  maxBrightPixelRatio: 0.45,
  // Sharpness: variance of the Laplacian over the face region of the small copy.
  minSharpness: 12,
  // The face region used for lighting/sharpness is the face box shrunk to
  // this share, to leave out hair and background.
  faceRegionScale: 0.7,
  // Hand or object over the face: the lower face (nose, mouth, cheeks) is
  // compared with its own mirror image. The area runs from this share of the
  // face box's height down to the second, and this share of its width to
  // each side of the nose.
  coverRegionTop: 0.4,
  coverRegionBottom: 0.9,
  coverHalfWidth: 0.4,
  // Average brightness difference (0–255) between mirrored left/right areas,
  // after removing light that falls evenly from one side. A face is roughly
  // symmetric; a hand over part of it is not. A hand across the nose and
  // cheek measured about 24. Kept lenient: tune on real phones.
  maxCoverAsymmetry: 18,
});

/** Reasons in the order their message is shown (first wins). */
export const PHOTO_QUALITY_REASONS = [
  'too_dark',
  'too_bright',
  'no_face',
  'multiple_faces',
  'face_cut_off',
  'face_too_small',
  'face_too_close',
  'face_off_center',
  'head_turned',
  'head_tilted',
  'head_pitched',
  'face_covered',
  'eyes_closed',
  'blurry',
];

/** One short visitor-facing line per reason. */
export const PHOTO_QUALITY_MESSAGES = Object.freeze({
  too_dark: 'Improve the lighting.',
  too_bright: 'Too bright. Avoid direct light.',
  no_face: 'Position your face in the frame.',
  multiple_faces: 'Only one face, please.',
  face_cut_off: 'Center your face in the frame.',
  face_too_small: 'Move closer.',
  face_too_close: 'Move back a little.',
  face_off_center: 'Center your face in the frame.',
  head_turned: 'Face the camera directly.',
  head_tilted: 'Face the camera directly.',
  head_pitched: 'Face the camera directly.',
  face_covered: 'Keep your hands away from your face.',
  eyes_closed: 'Please open your eyes and look at the camera.',
  blurry: 'Hold still.',
});

/** @param {unknown} n */
function finite(n) {
  return typeof n === 'number' && Number.isFinite(n) ? n : null;
}

/**
 * @typedef {object} NormalizedFace
 * @property {number} x
 * @property {number} y
 * @property {number} width
 * @property {number} height
 * @property {number | null} yaw — headEulerAngleY (left/right turn)
 * @property {number | null} pitch — headEulerAngleX (up/down)
 * @property {number | null} roll — headEulerAngleZ (tilt)
 * @property {number | null} leftEyeOpen
 * @property {number | null} rightEyeOpen
 * @property {number | null} noseX — x of the nose base landmark, when reported
 */

/**
 * The nose base landmark's x, or null. Android sends ML Kit's landmark type
 * number as text (NOSE_BASE is 6); iOS sends the name.
 * @param {unknown} landmarks
 */
function noseBaseX(landmarks) {
  if (!Array.isArray(landmarks)) return null;
  const nose = landmarks.find((l) => l?.type === 'noseBase' || String(l?.type) === '6');
  return finite(nose?.position?.x);
}

/**
 * One ML Kit face (`@infinitered/react-native-mlkit-face-detection`, frame
 * `{ origin: {x, y}, size: {x: width, y: height} }`) in a plain shape, or null
 * when it has no usable box. Missing optional values become null.
 * @param {any} raw
 * @returns {NormalizedFace | null}
 */
export function normalizeFace(raw) {
  const frame = raw?.frame;
  const x = finite(frame?.origin?.x ?? frame?.x);
  const y = finite(frame?.origin?.y ?? frame?.y);
  const width = finite(frame?.size?.x ?? frame?.width);
  const height = finite(frame?.size?.y ?? frame?.height);
  if (x == null || y == null || width == null || height == null || width <= 0 || height <= 0) {
    return null;
  }
  const probability = (v) => {
    const p = finite(v);
    return p != null && p >= 0 && p <= 1 ? p : null;
  };
  return {
    x,
    y,
    width,
    height,
    yaw: finite(raw.headEulerAngleY),
    pitch: finite(raw.headEulerAngleX),
    roll: finite(raw.headEulerAngleZ),
    leftEyeOpen: probability(raw.leftEyeOpenProbability),
    rightEyeOpen: probability(raw.rightEyeOpenProbability),
    noseX: noseBaseX(raw.landmarks),
  };
}

/**
 * @param {unknown} faces — ML Kit faces, or null/undefined when detection failed
 * @returns {NormalizedFace[] | null} null when there is no usable detection
 *   result — including faces reported without a usable box, which is a
 *   detector data problem, not proof that no face is there
 */
export function normalizeFaces(faces) {
  if (!Array.isArray(faces)) return null;
  const normalized = /** @type {NormalizedFace[]} */ (faces.map(normalizeFace).filter(Boolean));
  if (faces.length > 0 && normalized.length === 0) return null;
  return normalized;
}

/** @param {{ width?: unknown; height?: unknown } | null | undefined} size */
function validSize(size) {
  const width = finite(size?.width);
  const height = finite(size?.height);
  return width && height && width > 0 && height > 0 ? { width, height } : null;
}

/** @param {NormalizedFace[] | null} faces */
export function checkFaceCount(faces) {
  if (!faces) return { status: 'unknown' };
  if (faces.length === 0) return { status: 'fail', reason: 'no_face', value: 0 };
  if (faces.length > 1) return { status: 'fail', reason: 'multiple_faces', value: faces.length };
  return { status: 'pass', value: 1 };
}

/**
 * Where to draw the oval guide in a preview frame of `frame` size, from the
 * same geometry the position check uses: a circle of `size` at (left, top),
 * stretched by `scaleY` around its own centre into the oval.
 * @param {{ width: number; height: number }} frame
 * @param {typeof PHOTO_QUALITY_THRESHOLDS} [t]
 */
export function faceGuideLayout(frame, t = PHOTO_QUALITY_THRESHOLDS) {
  const size = 2 * t.guideRadiusX * frame.width;
  const ovalHeight = 2 * t.guideRadiusY * frame.height;
  return {
    size,
    scaleY: ovalHeight / size,
    left: t.guideCenterX * frame.width - size / 2,
    top: t.guideCenterY * frame.height - size / 2,
  };
}

/**
 * Face inside the photo and inside the oval guide: its centre near the
 * guide's centre, and the midpoints of all four sides of the face box within
 * the oval (a face is itself roughly oval, so its box's corners may poke out).
 * @param {NormalizedFace} face
 * @param {{ width: number; height: number } | null | undefined} imageSize
 * @param {typeof PHOTO_QUALITY_THRESHOLDS} [t]
 */
export function checkFacePosition(face, imageSize, t = PHOTO_QUALITY_THRESHOLDS) {
  const size = validSize(imageSize);
  if (!face || !size) return { status: 'unknown' };

  const insideW = Math.min(face.x + face.width, size.width) - Math.max(face.x, 0);
  const insideH = Math.min(face.y + face.height, size.height) - Math.max(face.y, 0);
  const inside = Math.max(insideW, 0) * Math.max(insideH, 0);
  const outsideRatio = 1 - inside / (face.width * face.height);
  if (outsideRatio > t.maxOutsideRatio) {
    return { status: 'fail', reason: 'face_cut_off', value: { outsideRatio } };
  }

  // Everything below in shares of the photo, like the guide.
  const halfW = face.width / size.width / 2;
  const halfH = face.height / size.height / 2;
  const cx = (face.x + face.width / 2) / size.width;
  const cy = (face.y + face.height / 2) / size.height;
  const offsetX = cx - t.guideCenterX;
  const offsetY = cy - t.guideCenterY;
  const value = { offsetX, offsetY, outsideRatio };

  // Bigger than the oval: no placement can fit it.
  if (halfW > t.guideRadiusX || halfH > t.guideRadiusY) {
    return { status: 'fail', reason: 'face_too_close', value };
  }

  // (dx/rx)² + (dy/ry)²: at most 1 inside the oval.
  const ovalDistance = (dx, dy) => (dx / t.guideRadiusX) ** 2 + (dy / t.guideRadiusY) ** 2;
  const centred = ovalDistance(offsetX, offsetY) <= t.maxCenterOffset ** 2;
  const sidesInside = [
    [offsetX - halfW, offsetY],
    [offsetX + halfW, offsetY],
    [offsetX, offsetY - halfH],
    [offsetX, offsetY + halfH],
  ].every(([dx, dy]) => ovalDistance(dx, dy) <= 1);
  if (!centred || !sidesInside) {
    return { status: 'fail', reason: 'face_off_center', value };
  }
  return { status: 'pass', value };
}

/**
 * @param {NormalizedFace} face
 * @param {{ width: number; height: number } | null | undefined} imageSize
 * @param {typeof PHOTO_QUALITY_THRESHOLDS} [t]
 */
export function checkFaceSize(face, imageSize, t = PHOTO_QUALITY_THRESHOLDS) {
  const size = validSize(imageSize);
  if (!face || !size) return { status: 'unknown' };
  const ratio = face.width / size.width;
  if (ratio < t.minFaceWidthRatio) return { status: 'fail', reason: 'face_too_small', value: ratio };
  if (ratio > t.maxFaceWidthRatio) return { status: 'fail', reason: 'face_too_close', value: ratio };
  return { status: 'pass', value: ratio };
}

/**
 * Yaw, roll and pitch, each checked only when the detector reported it.
 * @param {NormalizedFace} face
 * @param {typeof PHOTO_QUALITY_THRESHOLDS} [t]
 */
export function checkHeadPose(face, t = PHOTO_QUALITY_THRESHOLDS) {
  if (!face) return { status: 'unknown' };
  const value = { yaw: face.yaw, pitch: face.pitch, roll: face.roll };
  if (face.yaw == null && face.pitch == null && face.roll == null) return { status: 'unknown', value };
  if (face.yaw != null && Math.abs(face.yaw) > t.maxYaw) return { status: 'fail', reason: 'head_turned', value };
  if (face.roll != null && Math.abs(face.roll) > t.maxRoll) return { status: 'fail', reason: 'head_tilted', value };
  if (face.pitch != null && Math.abs(face.pitch) > t.maxPitch) {
    return { status: 'fail', reason: 'head_pitched', value };
  }
  return { status: 'pass', value };
}

/**
 * Both eyes must be reported open: either probability below the threshold
 * fails. A missing probability is 'unknown' (an essential check, so the
 * photo cannot pass). The low threshold leaves room for glasses and glare.
 * @param {NormalizedFace} face
 * @param {typeof PHOTO_QUALITY_THRESHOLDS} [t]
 */
export function checkEyes(face, t = PHOTO_QUALITY_THRESHOLDS) {
  if (!face) return { status: 'unknown' };
  const value = { left: face.leftEyeOpen, right: face.rightEyeOpen };
  if (face.leftEyeOpen == null || face.rightEyeOpen == null) return { status: 'unknown', value };
  if (face.leftEyeOpen < t.minEyeOpenProbability || face.rightEyeOpen < t.minEyeOpenProbability) {
    return { status: 'fail', reason: 'eyes_closed', value };
  }
  return { status: 'pass', value };
}

// ---------------------------------------------------------------------------
// Pixels: lighting and sharpness on a small grayscale copy of the photo.
// ---------------------------------------------------------------------------

/**
 * @typedef {{ data: Uint8Array; width: number; height: number }} GrayImage
 * @typedef {{ x: number; y: number; width: number; height: number }} Region integer pixels
 */

/**
 * RGBA (or RGB) bytes → 8-bit luma (BT.601), one byte per pixel.
 * @param {ArrayLike<number>} data
 * @param {number} width
 * @param {number} height
 * @param {3 | 4} [channels=4]
 * @returns {GrayImage}
 */
export function toGray(data, width, height, channels = 4) {
  const out = new Uint8Array(width * height);
  for (let i = 0, p = 0; i < out.length; i += 1, p += channels) {
    out[i] = (data[p] * 299 + data[p + 1] * 587 + data[p + 2] * 114) / 1000;
  }
  return { data: out, width, height };
}

/**
 * The face box mapped onto the small copy, shrunk to `scale` around its
 * centre and clamped to the image; the whole image when there is no face.
 * @param {NormalizedFace | null} face
 * @param {{ width: number; height: number } | null | undefined} imageSize — the photo the face box refers to
 * @param {{ width: number; height: number }} analysisSize — the small copy
 * @param {number} [scale]
 * @returns {Region | null} null when the region is empty
 */
export function analysisRegion(face, imageSize, analysisSize, scale = PHOTO_QUALITY_THRESHOLDS.faceRegionScale) {
  const full = { x: 0, y: 0, width: analysisSize.width, height: analysisSize.height };
  const size = validSize(imageSize);
  if (!face || !size) return full;
  const sx = analysisSize.width / size.width;
  const sy = analysisSize.height / size.height;
  const cx = (face.x + face.width / 2) * sx;
  const cy = (face.y + face.height / 2) * sy;
  const w = face.width * sx * scale;
  const h = face.height * sy * scale;
  const x0 = Math.max(0, Math.round(cx - w / 2));
  const y0 = Math.max(0, Math.round(cy - h / 2));
  const x1 = Math.min(analysisSize.width, Math.round(cx + w / 2));
  const y1 = Math.min(analysisSize.height, Math.round(cy + h / 2));
  if (x1 - x0 < 3 || y1 - y0 < 3) return null;
  return { x: x0, y: y0, width: x1 - x0, height: y1 - y0 };
}

/**
 * Mean luma and the shares of very dark / very bright pixels in a region.
 * @param {GrayImage} gray
 * @param {Region} region
 * @param {typeof PHOTO_QUALITY_THRESHOLDS} [t]
 */
export function lumaStats(gray, region, t = PHOTO_QUALITY_THRESHOLDS) {
  let sum = 0;
  let dark = 0;
  let bright = 0;
  const n = region.width * region.height;
  for (let y = region.y; y < region.y + region.height; y += 1) {
    const row = y * gray.width;
    for (let x = region.x; x < region.x + region.width; x += 1) {
      const v = gray.data[row + x];
      sum += v;
      if (v <= t.darkPixelLuma) dark += 1;
      if (v >= t.brightPixelLuma) bright += 1;
    }
  }
  return { mean: sum / n, darkRatio: dark / n, brightRatio: bright / n };
}

/**
 * @param {{ mean: number; darkRatio: number; brightRatio: number } | null} stats
 * @param {typeof PHOTO_QUALITY_THRESHOLDS} [t]
 */
export function checkLighting(stats, t = PHOTO_QUALITY_THRESHOLDS) {
  if (!stats || !Number.isFinite(stats.mean)) return { status: 'unknown' };
  if (stats.mean < t.minMeanLuma || stats.darkRatio > t.maxDarkPixelRatio) {
    return { status: 'fail', reason: 'too_dark', value: stats };
  }
  if (stats.mean > t.maxMeanLuma || stats.brightRatio > t.maxBrightPixelRatio) {
    return { status: 'fail', reason: 'too_bright', value: stats };
  }
  return { status: 'pass', value: stats };
}

/**
 * Sharpness as the variance of the 4-neighbour Laplacian over a region
 * (border pixels excluded). Higher is sharper; blur lowers it.
 * @param {GrayImage} gray
 * @param {Region} region
 * @returns {number | null} null when the region is too small
 */
export function laplacianVariance(gray, region) {
  const { data, width } = gray;
  const x0 = Math.max(region.x, 1);
  const y0 = Math.max(region.y, 1);
  const x1 = Math.min(region.x + region.width, gray.width - 1);
  const y1 = Math.min(region.y + region.height, gray.height - 1);
  let n = 0;
  let sum = 0;
  let sumSq = 0;
  for (let y = y0; y < y1; y += 1) {
    const row = y * width;
    for (let x = x0; x < x1; x += 1) {
      const i = row + x;
      const lap = data[i - 1] + data[i + 1] + data[i - width] + data[i + width] - 4 * data[i];
      sum += lap;
      sumSq += lap * lap;
      n += 1;
    }
  }
  if (n < 9) return null;
  const mean = sum / n;
  return sumSq / n - mean * mean;
}

/**
 * @param {number | null} sharpness
 * @param {typeof PHOTO_QUALITY_THRESHOLDS} [t]
 */
export function checkSharpness(sharpness, t = PHOTO_QUALITY_THRESHOLDS) {
  if (sharpness == null || !Number.isFinite(sharpness)) return { status: 'unknown' };
  if (sharpness < t.minSharpness) return { status: 'fail', reason: 'blurry', value: sharpness };
  return { status: 'pass', value: sharpness };
}

/** Mirrored cells per side (columns) and down the area (rows). */
const COVER_COLUMNS = 4;
const COVER_ROWS = 6;

/** @param {GrayImage} gray @param {number} x0 @param {number} y0 @param {number} x1 @param {number} y1 */
function cellMean(gray, x0, y0, x1, y1) {
  let sum = 0;
  for (let y = y0; y < y1; y += 1) {
    const row = y * gray.width;
    for (let x = x0; x < x1; x += 1) sum += gray.data[row + x];
  }
  return sum / ((x1 - x0) * (y1 - y0));
}

/**
 * How different the two sides of the lower face look: the area below the
 * eyes is cut into cells, each left cell is compared with its mirror cell on
 * the right, and the average difference is returned (0–255). The overall
 * left/right brightness offset (light from one side) is removed first.
 * Cells are averaged, so skin texture and a slightly misplaced midline do
 * not count. The midline is the nose when ML Kit reports it near the box
 * centre, otherwise the box centre.
 *
 * This is a heuristic for obvious cases (a hand or object over part of the
 * face). A hand covering the face evenly on both sides can still pass.
 * @param {NormalizedFace | null} face
 * @param {{ width: number; height: number } | null | undefined} imageSize — the photo the face box refers to
 * @param {GrayImage | null} gray — small copy of the same photo
 * @param {typeof PHOTO_QUALITY_THRESHOLDS} [t]
 * @returns {number | null} null when it cannot be measured
 */
export function faceCoverAsymmetry(face, imageSize, gray, t = PHOTO_QUALITY_THRESHOLDS) {
  const size = validSize(imageSize);
  if (!face || !size || !gray) return null;
  const sx = gray.width / size.width;
  const sy = gray.height / size.height;

  const centreX = face.x + face.width / 2;
  const nearCentre = face.noseX != null && Math.abs(face.noseX - centreX) <= face.width / 4;
  const mid = (nearCentre ? /** @type {number} */ (face.noseX) : centreX) * sx;
  // Stay inside the face box and the image on both sides.
  const half = Math.min(
    t.coverHalfWidth * face.width * sx,
    mid - face.x * sx,
    (face.x + face.width) * sx - mid,
    mid,
    gray.width - mid,
  );
  const top = Math.max(0, (face.y + t.coverRegionTop * face.height) * sy);
  const bottom = Math.min(gray.height, (face.y + t.coverRegionBottom * face.height) * sy);
  const cellW = half / COVER_COLUMNS;
  const cellH = (bottom - top) / COVER_ROWS;
  if (!(cellW >= 2 && cellH >= 2)) return null;

  const diffs = [];
  for (let r = 0; r < COVER_ROWS; r += 1) {
    const y0 = Math.round(top + r * cellH);
    const y1 = Math.round(top + (r + 1) * cellH);
    for (let c = 0; c < COVER_COLUMNS; c += 1) {
      const inner = Math.round(c * cellW);
      const outer = Math.round((c + 1) * cellW);
      const m = Math.round(mid);
      diffs.push(cellMean(gray, m - outer, y0, m - inner, y1) - cellMean(gray, m + inner, y0, m + outer, y1));
    }
  }
  const offset = diffs.reduce((a, b) => a + b, 0) / diffs.length;
  return diffs.reduce((a, d) => a + Math.abs(d - offset), 0) / diffs.length;
}

/**
 * @param {number | null} asymmetry — from faceCoverAsymmetry
 * @param {typeof PHOTO_QUALITY_THRESHOLDS} [t]
 */
export function checkFaceCovered(asymmetry, t = PHOTO_QUALITY_THRESHOLDS) {
  if (asymmetry == null || !Number.isFinite(asymmetry)) return { status: 'unknown' };
  if (asymmetry > t.maxCoverAsymmetry) return { status: 'fail', reason: 'face_covered', value: asymmetry };
  return { status: 'pass', value: asymmetry };
}

// ---------------------------------------------------------------------------
// Combined assessment.
// ---------------------------------------------------------------------------

/**
 * Checks that must each return 'pass' for the photo to pass. Head pose
 * blocks only on 'fail': ML Kit does not always report those angles.
 */
const ESSENTIAL_CHECKS = ['faceCount', 'position', 'size', 'eyes', 'covered', 'lighting', 'sharpness'];

const SKIPPED = Object.freeze({ status: 'skipped' });
const UNKNOWN = Object.freeze({ status: 'unknown' });

/**
 * @typedef {object} PhotoQualityResult
 * @property {'pass' | 'fail' | 'unknown'} status — 'unknown' when nothing failed
 *   but an essential check could not run (detector or image error): not
 *   blamed on the visitor, but not verified either
 * @property {string[]} reasons — failed checks, in PHOTO_QUALITY_REASONS order
 * @property {string | null} message — the visitor-facing line for the first reason
 * @property {Record<string, { status: string; reason?: string; value?: unknown }>} checks
 */

/**
 * Combines every check into one result.
 * @param {object} input
 * @param {unknown} input.faces — ML Kit faces; null/undefined when detection failed
 * @param {{ width: number; height: number } | null} [input.imageSize] — the photo the face boxes refer to
 * @param {GrayImage | null} [input.gray] — small grayscale copy; null when unavailable
 * @param {Partial<typeof PHOTO_QUALITY_THRESHOLDS>} [input.thresholds]
 * @returns {PhotoQualityResult}
 */
export function assessPhotoQuality({ faces, imageSize = null, gray = null, thresholds } = {}) {
  const t = { ...PHOTO_QUALITY_THRESHOLDS, ...(thresholds ?? {}) };
  const normalized = normalizeFaces(faces);
  const faceCount = checkFaceCount(normalized);
  const face = faceCount.status === 'pass' ? /** @type {NormalizedFace[]} */ (normalized)[0] : null;

  const checks = {
    faceCount,
    position: face ? checkFacePosition(face, imageSize, t) : faceCount.status === 'unknown' ? UNKNOWN : SKIPPED,
    size: face ? checkFaceSize(face, imageSize, t) : faceCount.status === 'unknown' ? UNKNOWN : SKIPPED,
    headPose: face ? checkHeadPose(face, t) : faceCount.status === 'unknown' ? UNKNOWN : SKIPPED,
    eyes: face ? checkEyes(face, t) : faceCount.status === 'unknown' ? UNKNOWN : SKIPPED,
    covered: face ? UNKNOWN : faceCount.status === 'unknown' ? UNKNOWN : SKIPPED,
    lighting: UNKNOWN,
    sharpness: UNKNOWN,
  };

  if (gray && gray.width > 2 && gray.height > 2) {
    // With one face, measure the face; otherwise light is judged on the whole
    // photo (too dark is a common reason for "no face") and sharpness skipped.
    const region = analysisRegion(face, imageSize, gray, t.faceRegionScale);
    if (region) {
      checks.lighting = checkLighting(lumaStats(gray, region, t), t);
      checks.sharpness = face ? checkSharpness(laplacianVariance(gray, region), t) : SKIPPED;
    }
    if (face) checks.covered = checkFaceCovered(faceCoverAsymmetry(face, imageSize, gray, t), t);
  }

  const failed = new Set(
    Object.values(checks)
      .filter((c) => c.status === 'fail' && c.reason)
      .map((c) => /** @type {string} */ (c.reason)),
  );
  const reasons = PHOTO_QUALITY_REASONS.filter((r) => failed.has(r));
  // 'pass' only when every essential check actually ran and passed; anything
  // unchecked is 'unknown' (the camera retries, it never goes to review).
  const verified = ESSENTIAL_CHECKS.every((name) => checks[name].status === 'pass');
  const status = reasons.length > 0 ? 'fail' : verified ? 'pass' : 'unknown';
  return {
    status,
    reasons,
    message: reasons.length > 0 ? PHOTO_QUALITY_MESSAGES[reasons[0]] : null,
    checks,
  };
}

/** Result when quality could not be checked at all (e.g. web, or an error). */
export function unknownPhotoQuality() {
  return { status: 'unknown', reasons: [], message: null, checks: {} };
}

// ---------------------------------------------------------------------------
// Base64 → bytes (no atob / Buffer dependency).
// ---------------------------------------------------------------------------

const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
const B64_LOOKUP = (() => {
  const table = new Int16Array(128).fill(-1);
  for (let i = 0; i < B64.length; i += 1) table[B64.charCodeAt(i)] = i;
  table['-'.charCodeAt(0)] = 62; // URL-safe variants
  table['_'.charCodeAt(0)] = 63;
  return table;
})();

/**
 * Decodes base64 (optionally a `data:` URL; whitespace ignored) to bytes.
 * @param {string} input
 * @returns {Uint8Array}
 */
export function base64ToBytes(input) {
  const s = String(input ?? '').replace(/^data:[^,]*,/, '');
  const out = new Uint8Array(Math.floor((s.length * 3) / 4));
  let buffer = 0;
  let bits = 0;
  let n = 0;
  for (let i = 0; i < s.length; i += 1) {
    const code = s.charCodeAt(i);
    if (code === 61) break; // '='
    const v = code < 128 ? B64_LOOKUP[code] : -1;
    if (v < 0) continue; // whitespace / newlines
    buffer = ((buffer << 6) | v) & 0xffffff; // never more than 24 bits pending
    bits += 6;
    if (bits >= 8) {
      bits -= 8;
      out[n] = (buffer >> bits) & 0xff;
      n += 1;
    }
  }
  return out.subarray(0, n);
}
