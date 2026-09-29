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
 * @typedef {{ uri: string; fileName: string } | null} PickedPhoto
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
  return {
    uri: asset.uri,
    fileName: asset.fileName ?? 'photo.jpg',
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
  return {
    uri: asset.uri,
    fileName: asset.fileName ?? 'document.jpg',
  };
}
