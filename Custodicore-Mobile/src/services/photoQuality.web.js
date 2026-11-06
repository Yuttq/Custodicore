/**
 * Web stand-in for photoQuality.js: ML Kit face detection is not available
 * in browsers, so the check always reports 'unknown' (never a rejection) and
 * web keeps manual capture. Same exports as the native file.
 */
import { unknownPhotoQuality } from '../utils/photoQualityRules';

/**
 * @param {string} _uri
 * @param {object} [_options]
 */
export async function checkPhotoQuality(_uri, _options) {
  return { ...unknownPhotoQuality(), durationMs: 0 };
}

/** Web captures are `data:` URLs, not files: nothing to delete. */
export function discardPhoto(_uri) {}

export const PHOTO_QUALITY_SUPPORTED = false;
