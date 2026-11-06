// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import encodeJpeg from 'jpeg-js/lib/encoder.js';
import decodeJpeg from 'jpeg-js/lib/decoder.js';

import {
  PHOTO_QUALITY_MESSAGES,
  PHOTO_QUALITY_THRESHOLDS,
  analysisRegion,
  assessPhotoQuality,
  base64ToBytes,
  checkEyes,
  checkFaceCount,
  checkFacePosition,
  checkFaceSize,
  checkHeadPose,
  checkLighting,
  checkSharpness,
  laplacianVariance,
  lumaStats,
  normalizeFace,
  normalizeFaces,
  toGray,
  unknownPhotoQuality,
} from './photoQualityRules.js';

/** A portrait photo, as the front camera saves it. */
const IMAGE = { width: 1000, height: 1333 };

/**
 * An ML Kit face (frame = origin + size), centred on the oval guide by default.
 * @param {object} [o]
 */
function mlkitFace({
  cx = 500,
  cy = 600,
  width = 450,
  height = 600,
  yaw = 2,
  pitch = -3,
  roll = 1,
  leftEye = 0.95,
  rightEye = 0.93,
} = {}) {
  return {
    frame: { origin: { x: cx - width / 2, y: cy - height / 2 }, size: { x: width, y: height } },
    landmarks: [],
    contours: [],
    headEulerAngleX: pitch,
    headEulerAngleY: yaw,
    headEulerAngleZ: roll,
    leftEyeOpenProbability: leftEye,
    rightEyeOpenProbability: rightEye,
  };
}

/** Small grayscale copy (240 × 320) filled by `fn(x, y)`. */
function grayImage(fn, width = 240, height = 320) {
  const data = new Uint8Array(width * height);
  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) data[y * width + x] = fn(x, y);
  }
  return { data, width, height };
}

/** Well lit and detailed: 2-pixel checks around mid-gray. */
const SHARP = grayImage((x, y) => ((Math.floor(x / 2) + Math.floor(y / 2)) % 2 ? 150 : 110));
/** Well lit but with no detail at all (what heavy blur leaves). */
const BLURRY = grayImage((x, y) => 110 + Math.round((x + y) / 14));
const DARK = grayImage((x, y) => ((x + y) % 2 ? 22 : 12));
const BRIGHT = grayImage((x, y) => ((x + y) % 2 ? 252 : 240));

function assess(faces, gray = SHARP, extra = {}) {
  return assessPhotoQuality({ faces, imageSize: IMAGE, gray, ...extra });
}

test('a well-placed, well-lit, sharp single face passes', () => {
  const result = assess([mlkitFace()]);
  assert.equal(result.status, 'pass');
  assert.deepEqual(result.reasons, []);
  assert.equal(result.message, null);
  for (const [name, check] of Object.entries(result.checks)) {
    assert.equal(check.status, 'pass', `${name} should pass`);
  }
});

test('more than one face fails with one short message', () => {
  const result = assess([mlkitFace(), mlkitFace({ cx: 200, width: 250, height: 330 })]);
  assert.equal(result.status, 'fail');
  assert.deepEqual(result.reasons, ['multiple_faces']);
  assert.equal(result.message, 'Only one face, please.');
  assert.equal(result.checks.position.status, 'skipped');
  assert.equal(result.checks.lighting.status, 'pass'); // whole photo
});

test('no face fails; light is still judged on the whole photo, sharpness skipped', () => {
  const result = assess([]);
  assert.equal(result.status, 'fail');
  assert.deepEqual(result.reasons, ['no_face']);
  assert.equal(result.message, 'Position your face in the frame.');
  assert.equal(result.checks.lighting.status, 'pass');
  assert.equal(result.checks.sharpness.status, 'skipped');
});

test('no face in a dark photo asks for better light first', () => {
  const result = assess([], DARK);
  assert.deepEqual(result.reasons, ['too_dark', 'no_face']);
  assert.equal(result.message, 'Improve the lighting.');
});

test('poor positioning: off centre, cut off, too far, too close', () => {
  // Fully inside the photo but well right of / below the guide.
  assert.deepEqual(assess([mlkitFace({ cx: 740 })]).reasons, ['face_off_center']);
  assert.deepEqual(assess([mlkitFace({ cy: 950 })]).reasons, ['face_off_center']);
  // Partly outside the photo: cut off (reported instead of off-centre).
  assert.deepEqual(assess([mlkitFace({ cx: 100 })]).reasons, ['face_cut_off']);
  assert.deepEqual(assess([mlkitFace({ cx: 820 })]).reasons, ['face_cut_off']);
  assert.deepEqual(assess([mlkitFace({ width: 150, height: 200 })]).reasons, ['face_too_small']);
  assert.deepEqual(assess([mlkitFace({ width: 900, height: 1150, cy: 620 })]).reasons, ['face_too_close']);
  assert.equal(assess([mlkitFace({ cx: 740 })]).message, 'Center your face in the frame.');
  assert.equal(assess([mlkitFace({ width: 150, height: 200 })]).message, 'Move closer.');
});

test('positioning is lenient: a slightly off-centre face still passes', () => {
  assert.equal(assess([mlkitFace({ cx: 600, cy: 700 })]).status, 'pass');
  assert.equal(assess([mlkitFace({ width: 260, height: 350 })]).status, 'pass');
});

test('poor lighting: too dark and too bright', () => {
  const dark = assess([mlkitFace()], DARK);
  assert.deepEqual(dark.reasons, ['too_dark']);
  assert.equal(dark.message, 'Improve the lighting.');
  const bright = assess([mlkitFace()], BRIGHT);
  assert.deepEqual(bright.reasons, ['too_bright']);
  assert.equal(bright.message, 'Too bright. Avoid direct light.');
});

test('blur: a face region with no detail fails as blurry', () => {
  const result = assess([mlkitFace()], BLURRY);
  assert.deepEqual(result.reasons, ['blurry']);
  assert.equal(result.message, 'Hold still.');
  assert.ok(laplacianVariance(SHARP, { x: 0, y: 0, width: 240, height: 320 }) > 100);
  assert.ok(laplacianVariance(BLURRY, { x: 0, y: 0, width: 240, height: 320 }) < 1);
});

test('head angles: lenient limits for yaw, roll and pitch', () => {
  assert.deepEqual(assess([mlkitFace({ yaw: 30 })]).reasons, ['head_turned']);
  assert.deepEqual(assess([mlkitFace({ roll: -25 })]).reasons, ['head_tilted']);
  assert.deepEqual(assess([mlkitFace({ pitch: 26 })]).reasons, ['head_pitched']);
  assert.equal(assess([mlkitFace({ yaw: -18, roll: 15, pitch: 15 })]).status, 'pass');
  assert.equal(assess([mlkitFace({ yaw: 30 })]).message, 'Face the camera directly.');
});

test('eyes: closed only when both eyes are reported low', () => {
  assert.deepEqual(assess([mlkitFace({ leftEye: 0.05, rightEye: 0.1 })]).reasons, ['eyes_closed']);
  // One low eye (glasses, glare, a wink) is never enough.
  assert.equal(assess([mlkitFace({ leftEye: 0.05, rightEye: 0.9 })]).status, 'pass');
  // Not reported: not checked, no rejection.
  const missing = assess([mlkitFace({ leftEye: null, rightEye: undefined })]);
  assert.equal(missing.status, 'pass');
  assert.equal(missing.checks.eyes.status, 'unknown');
});

test('missing detector data never rejects a photo', () => {
  // Detection failed (no result at all).
  const failed = assess(null);
  assert.equal(failed.status, 'unknown');
  assert.deepEqual(failed.reasons, []);
  assert.equal(failed.message, null);
  assert.equal(failed.checks.faceCount.status, 'unknown');
  assert.equal(failed.checks.position.status, 'unknown');
  assert.equal(assess(undefined).status, 'unknown');

  // Faces without a usable box: detector data problem, not "no face".
  assert.equal(assess([{ frame: null }, { headEulerAngleY: 3 }]).status, 'unknown');

  // A face without angles or eye scores: those checks are unknown, the rest pass.
  const sparse = assess([{ frame: mlkitFace().frame }]);
  assert.equal(sparse.status, 'pass');
  assert.equal(sparse.checks.headPose.status, 'unknown');
  assert.equal(sparse.checks.eyes.status, 'unknown');

  // No photo size: position and size cannot be judged, nothing fails.
  const noSize = assessPhotoQuality({ faces: [mlkitFace()], imageSize: null, gray: SHARP });
  assert.equal(noSize.status, 'pass');
  assert.equal(noSize.checks.position.status, 'unknown');
  assert.equal(noSize.checks.size.status, 'unknown');

  // No pixels: light and sharpness unknown.
  const noPixels = assess([mlkitFace()], null);
  assert.equal(noPixels.status, 'pass');
  assert.equal(noPixels.checks.lighting.status, 'unknown');
  assert.equal(noPixels.checks.sharpness.status, 'unknown');

  // Detection failed, but the photo is clearly too dark: that alone fails.
  assert.deepEqual(assess(null, DARK).reasons, ['too_dark']);

  // Nothing at all.
  assert.equal(assessPhotoQuality().status, 'unknown');
  assert.deepEqual(unknownPhotoQuality(), { status: 'unknown', reasons: [], message: null, checks: {} });
});

test('thresholds are configurable per call', () => {
  const offCentre = [mlkitFace({ cx: 760 })];
  assert.deepEqual(assess(offCentre).reasons, ['face_off_center']);
  assert.equal(assess(offCentre, SHARP, { thresholds: { maxCenterOffsetX: 0.3 } }).status, 'pass');
  assert.equal(PHOTO_QUALITY_THRESHOLDS.maxCenterOffsetX, 0.2); // defaults untouched
  assert.throws(() => {
    PHOTO_QUALITY_THRESHOLDS.maxYaw = 90;
  });
});

test('every reason has a short message', () => {
  for (const message of Object.values(PHOTO_QUALITY_MESSAGES)) {
    assert.ok(message.length > 0 && message.length <= 40, message);
  }
});

test('individual checks return clear statuses', () => {
  assert.deepEqual(checkFaceCount(null), { status: 'unknown' });
  assert.equal(checkFaceCount([]).reason, 'no_face');
  const face = normalizeFace(mlkitFace());
  assert.equal(checkFacePosition(face, IMAGE).status, 'pass');
  assert.equal(checkFacePosition(face, { width: 0, height: 10 }).status, 'unknown');
  assert.equal(checkFaceSize(face, IMAGE).status, 'pass');
  assert.equal(checkHeadPose(null).status, 'unknown');
  assert.equal(checkEyes(null).status, 'unknown');
  assert.equal(checkLighting(null).status, 'unknown');
  assert.equal(checkSharpness(null).status, 'unknown');
  assert.equal(checkSharpness(Number.NaN).status, 'unknown');
});

test('face normalization reads the ML Kit frame and rejects junk values', () => {
  assert.deepEqual(normalizeFace(mlkitFace({ cx: 500, cy: 600, width: 400, height: 500 })), {
    x: 300,
    y: 350,
    width: 400,
    height: 500,
    yaw: 2,
    pitch: -3,
    roll: 1,
    leftEyeOpen: 0.95,
    rightEyeOpen: 0.93,
  });
  assert.equal(normalizeFace({ frame: { origin: { x: 1, y: 1 }, size: { x: 0, y: 10 } } }), null);
  assert.equal(normalizeFace(null), null);
  const odd = normalizeFace({ ...mlkitFace(), headEulerAngleY: 'x', leftEyeOpenProbability: 3 });
  assert.equal(odd.yaw, null);
  assert.equal(odd.leftEyeOpen, null);
  assert.deepEqual(normalizeFaces([]), []);
  assert.equal(normalizeFaces('faces'), null);
});

test('analysis region follows the face on the small copy and stays inside it', () => {
  const small = { width: 240, height: 320 };
  const region = analysisRegion(normalizeFace(mlkitFace()), IMAGE, small, 0.7);
  assert.ok(region.x > 0 && region.y > 0);
  assert.ok(region.x + region.width <= 240 && region.y + region.height <= 320);
  assert.deepEqual(analysisRegion(null, IMAGE, small), { x: 0, y: 0, width: 240, height: 320 });
  const edge = analysisRegion(normalizeFace(mlkitFace({ cx: -100 })), IMAGE, small, 0.7);
  assert.ok(edge === null || edge.x >= 0);
});

test('luma stats and gray conversion', () => {
  const gray = toGray(new Uint8Array([255, 255, 255, 255, 0, 0, 0, 255]), 2, 1, 4);
  assert.deepEqual([...gray.data], [255, 0]);
  const stats = lumaStats(gray, { x: 0, y: 0, width: 2, height: 1 });
  assert.equal(stats.mean, 127.5);
  assert.equal(stats.darkRatio, 0.5);
  assert.equal(stats.brightRatio, 0.5);
  assert.equal(laplacianVariance(gray, { x: 0, y: 0, width: 2, height: 1 }), null);
});

test('base64 decoding matches Node, including data: URLs and whitespace', () => {
  const bytes = Buffer.from(Array.from({ length: 300 }, (_, i) => (i * 37) % 256));
  const b64 = bytes.toString('base64');
  assert.deepEqual(Buffer.from(base64ToBytes(b64)), bytes);
  assert.deepEqual(Buffer.from(base64ToBytes(`data:image/jpeg;base64,${b64}`)), bytes);
  assert.deepEqual(Buffer.from(base64ToBytes(b64.replace(/(.{60})/g, '$1\n'))), bytes);
  assert.equal(base64ToBytes('').length, 0);
});

test('end to end on a real JPEG: base64 → jpeg-js decoder (no Buffer) → assessment', () => {
  const width = 240;
  const height = 320;
  const rgba = new Uint8Array(width * height * 4);
  for (let i = 0; i < width * height; i += 1) {
    const v = SHARP.data[i];
    rgba.set([v, v, v, 255], i * 4);
  }
  const jpeg = encodeJpeg({ data: rgba, width, height }, 92).data;
  const decoded = decodeJpeg(base64ToBytes(Buffer.from(jpeg).toString('base64')), {
    useTArray: true,
    formatAsRGBA: true,
  });
  assert.ok(decoded.data instanceof Uint8Array && !Buffer.isBuffer(decoded.data));
  const gray = toGray(decoded.data, decoded.width, decoded.height, 4);
  assert.equal(assessPhotoQuality({ faces: [mlkitFace()], imageSize: IMAGE, gray }).status, 'pass');
});
