/**
 * Client-side checks for verification document images (government ID and
 * supporting documents). Pure — no React Native imports — so it runs under
 * `node --test`.
 *
 * The mobile upload flows only offer photos, so only JPG, PNG and WEBP are
 * accepted here (the backend also accepts PDF, which these flows never
 * produce). Unknown metadata is let through: the backend validates every
 * upload again.
 */

/** Matches the backend `max:10240` (KB) document rule. */
export const DOCUMENT_IMAGE_MAX_BYTES = 10 * 1024 * 1024;

export const DOCUMENT_IMAGE_ERRORS = {
  unsupportedType: 'This file type is not supported. Please choose a JPG, PNG, or WEBP image.',
  tooLarge: 'This image is larger than 10 MB. Please choose a smaller image or retake the photo.',
};

/** Accepted MIME types → canonical MIME type (`image/jpg` is a common alias). */
const SUPPORTED_MIME_TYPES = {
  'image/jpeg': 'image/jpeg',
  'image/jpg': 'image/jpeg',
  'image/png': 'image/png',
  'image/webp': 'image/webp',
};

/** Accepted file extensions → MIME type. */
const SUPPORTED_EXTENSIONS = {
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
};

/** Canonical MIME type → extension used when a file name needs one. */
const EXTENSION_FOR_TYPE = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
};

/** @param {unknown} value — e.g. `"Image/JPEG; charset=binary"` → `"image/jpeg"` */
function normalizeMimeType(value) {
  return String(value ?? '').split(';')[0].trim().toLowerCase();
}

/**
 * Lower-case extension of the last path segment, or '' when there is none
 * (e.g. Android `content://…/1234` URIs).
 * @param {unknown} value — a file name or URI
 */
function getExtension(value) {
  const segment = String(value ?? '').split(/[?#]/)[0].split('/').pop() ?? '';
  const dot = segment.lastIndexOf('.');
  if (dot < 0) return '';
  const ext = segment.slice(dot + 1).toLowerCase();
  return /^[a-z0-9]{1,5}$/.test(ext) ? ext : '';
}

/**
 * @typedef {object} DocumentImageFile
 * @property {string | null} [uri]
 * @property {string | null} [fileName]
 * @property {string | null} [mimeType]
 * @property {number | null} [fileSize] — bytes
 */

/**
 * Validates a picked document image. A known MIME type decides the format on
 * its own (a misleading file name cannot override it); otherwise the file
 * name, then the URI, extension is checked when present.
 * @param {DocumentImageFile | null | undefined} file
 * @returns {string | null} a user-facing error, or null when acceptable
 */
export function validateDocumentImage(file) {
  if (!file) return null;

  const mime = normalizeMimeType(file.mimeType);
  if (mime) {
    if (!SUPPORTED_MIME_TYPES[mime]) return DOCUMENT_IMAGE_ERRORS.unsupportedType;
  } else {
    const ext = getExtension(file.fileName) || getExtension(file.uri);
    if (ext && !SUPPORTED_EXTENSIONS[ext]) return DOCUMENT_IMAGE_ERRORS.unsupportedType;
  }

  const size = Number(file.fileSize);
  if (file.fileSize != null && Number.isFinite(size) && size > DOCUMENT_IMAGE_MAX_BYTES) {
    return DOCUMENT_IMAGE_ERRORS.tooLarge;
  }
  return null;
}

/**
 * Multipart file name + type for a document image that passed
 * validateDocumentImage(). The name's extension is aligned with the known
 * MIME type; with no usable metadata it defaults to JPEG, leaving the final
 * check to the backend.
 * @param {DocumentImageFile} file
 * @returns {{ name: string; type: string } | null} null when unsupported
 */
export function toDocumentImageUpload(file) {
  const name = String(file?.fileName ?? '').trim();
  const nameExt = getExtension(name);
  const base = (nameExt ? name.slice(0, -(nameExt.length + 1)) : name) || 'document';

  const mime = normalizeMimeType(file?.mimeType);
  if (mime) {
    const type = SUPPORTED_MIME_TYPES[mime];
    if (!type) return null;
    if (SUPPORTED_EXTENSIONS[nameExt] === type) return { name, type };
    return { name: `${base}.${EXTENSION_FOR_TYPE[type]}`, type };
  }

  if (nameExt) {
    const type = SUPPORTED_EXTENSIONS[nameExt];
    return type ? { name, type } : null;
  }

  const uriExt = getExtension(file?.uri);
  if (uriExt && !SUPPORTED_EXTENSIONS[uriExt]) return null;
  const ext = uriExt || 'jpg';
  return { name: `${base}.${ext}`, type: SUPPORTED_EXTENSIONS[ext] };
}
