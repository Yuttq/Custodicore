import * as ImagePicker from 'expo-image-picker';
import {
  ensureCameraPermission,
  ensureMediaLibraryPermission,
  ensureUploadCameraPermission,
  ensureUploadMediaLibraryPermission,
} from './mediaPermissions';

const PICKER_OPTIONS = {
  mediaTypes: ['images'],
  allowsEditing: false,
  quality: 0.85,
};

/**
 * Web only: the browser `File` behind each picked photo, by its `blob:` uri.
 * A browser FormData cannot upload a React Native `{ uri, name, type }`
 * part, so uploads look the real file up here (see getPickedWebFile).
 * Native pickers return no `file`, so this stays empty on Android/iOS.
 * @type {Map<string, File>}
 */
const pickedWebFiles = new Map();

/** @param {{ uri?: string | null; file?: File | null }} asset */
function rememberWebFile(asset) {
  if (asset?.file && asset.uri) pickedWebFiles.set(asset.uri, asset.file);
}

/**
 * The browser `File` picked under `uri` on web; null on native or when unknown.
 * @param {string | null | undefined} uri
 * @returns {File | null}
 */
export function getPickedWebFile(uri) {
  return (uri && pickedWebFiles.get(uri)) || null;
}

/**
 * `mimeType` and `fileSize` (bytes) are null when the picker does not report them.
 * @typedef {{ uri: string; fileName: string; mimeType: string | null; fileSize: number | null } | null} PickedPhoto
 */

/**
 * @typedef {{ forUpload?: boolean }} PickPhotoOptions
 */

/**
 * Opens the device camera after the permission workflow completes.
 * @param {PickPhotoOptions} [options]
 * @returns {Promise<PickedPhoto>}
 */
export async function pickPhotoFromCamera(options = {}) {
  const permission = options.forUpload
    ? await ensureUploadCameraPermission()
    : await ensureCameraPermission();
  if (!permission.granted) {
    return null;
  }

  const result = await ImagePicker.launchCameraAsync(PICKER_OPTIONS);
  if (result.canceled || !result.assets?.length) {
    return null;
  }

  const asset = result.assets[0];
  rememberWebFile(asset);
  return {
    uri: asset.uri,
    fileName: asset.fileName ?? 'photo.jpg',
    mimeType: asset.mimeType ?? null,
    fileSize: asset.fileSize ?? null,
  };
}

/**
 * Opens the photo library after the permission workflow completes.
 * @param {PickPhotoOptions} [options]
 * @returns {Promise<PickedPhoto>}
 */
export async function pickPhotoFromGallery(options = {}) {
  const permission = options.forUpload
    ? await ensureUploadMediaLibraryPermission()
    : await ensureMediaLibraryPermission();
  if (!permission.granted) {
    return null;
  }

  const result = await ImagePicker.launchImageLibraryAsync({
    ...PICKER_OPTIONS,
    selectionLimit: 1,
  });
  if (result.canceled || !result.assets?.length) {
    return null;
  }

  const asset = result.assets[0];
  rememberWebFile(asset);
  return {
    uri: asset.uri,
    fileName: asset.fileName ?? 'document.jpg',
    mimeType: asset.mimeType ?? null,
    fileSize: asset.fileSize ?? null,
  };
}
