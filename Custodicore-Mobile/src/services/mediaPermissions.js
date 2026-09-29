import * as ImagePicker from 'expo-image-picker';
import { Camera } from 'expo-camera';
import { Alert, Linking } from 'react-native';

/**
 * @typedef {'camera' | 'gallery' | 'scanner' | 'upload_camera' | 'upload_gallery'} PermissionPurpose
 */

/** @type {Record<PermissionPurpose, { rationaleTitle: string; rationaleMessage: string; deniedTitle: string; deniedMessage: string }>} */
const PERMISSION_COPY = {
  camera: {
    rationaleTitle: 'Use your camera',
    rationaleMessage:
      'CustodiCore needs camera access so you can take photos for your profile and verification documents. Photos are only captured when you choose Take Photo.',
    deniedTitle: 'Camera access needed',
    deniedMessage:
      'Camera permission was denied. You can enable it in Settings, or tap Try Again if you are ready to allow access.',
  },
  gallery: {
    rationaleTitle: 'Access your photos',
    rationaleMessage:
      'CustodiCore needs access to your photo library so you can choose existing images for verification documents and your profile picture. We only open the gallery when you tap Choose From Gallery.',
    deniedTitle: 'Photo library access needed',
    deniedMessage:
      'Photo library permission was denied. Enable Photos access in Settings, or tap Try Again to request permission again.',
  },
  scanner: {
    rationaleTitle: 'Use camera to scan QR codes',
    rationaleMessage:
      'CustodiCore needs camera access to scan QR codes. The scanner opens only when you start a scan.',
    deniedTitle: 'Camera access needed for scanning',
    deniedMessage:
      'Camera permission is required to scan QR codes. Enable it in Settings, or tap Try Again to request access again.',
  },
  upload_camera: {
    rationaleTitle: 'Camera access required',
    rationaleMessage: 'Camera access is required to upload your government ID.',
    deniedTitle: 'Camera access needed',
    deniedMessage:
      'Camera access is required to upload your government ID. Enable it in Settings, or tap Try Again.',
  },
  upload_gallery: {
    rationaleTitle: 'Photo access required',
    rationaleMessage: 'Photo library access is required to upload your government ID.',
    deniedTitle: 'Photo access needed',
    deniedMessage:
      'Photo library access is required to upload your government ID. Enable Photos access in Settings, or tap Try Again.',
  },
};

/**
 * @param {string} title
 * @param {string} message
 * @returns {Promise<boolean>}
 */
function promptBeforeRequest(title, message) {
  return new Promise((resolve) => {
    Alert.alert(
      title,
      message,
      [
        { text: 'Not Now', style: 'cancel', onPress: () => resolve(false) },
        { text: 'Continue', onPress: () => resolve(true) },
      ],
      { cancelable: true, onDismiss: () => resolve(false) },
    );
  });
}

/**
 * @param {object} options
 * @param {string} options.title
 * @param {string} options.message
 * @param {boolean} options.canRetry
 * @returns {Promise<'cancel' | 'retry' | 'settings'>}
 */
function promptDenied({ title, message, canRetry }) {
  return new Promise((resolve) => {
    const buttons = [
      { text: 'Cancel', style: 'cancel', onPress: () => resolve('cancel') },
    ];
    if (canRetry) {
      buttons.unshift({ text: 'Try Again', onPress: () => resolve('retry') });
    }
    buttons.push({
      text: 'Open Settings',
      onPress: () => {
        Linking.openSettings().catch(() => {});
        resolve('settings');
      },
    });
    Alert.alert(title, message, buttons, { cancelable: true });
  });
}

/**
 * @param {object} options
 * @param {() => Promise<{ granted: boolean; status: string; canAskAgain?: boolean }>} options.getPermission
 * @param {() => Promise<{ granted: boolean; status: string; canAskAgain?: boolean }>} options.requestPermission
 * @param {PermissionPurpose} options.purpose
 * @returns {Promise<{ granted: boolean; reason?: string }>}
 */
async function ensurePermission({ getPermission, requestPermission, purpose }) {
  const copy = PERMISSION_COPY[purpose];
  let current = await getPermission();

  if (current.granted) {
    return { granted: true };
  }

  for (;;) {
    const canAskAgain = current.canAskAgain !== false;
    const needsRationale =
      current.status === 'undetermined' ||
      (current.status === 'denied' && canAskAgain);

    if (needsRationale) {
      const proceed = await promptBeforeRequest(copy.rationaleTitle, copy.rationaleMessage);
      if (!proceed) {
        return { granted: false, reason: 'cancelled' };
      }
      current = await requestPermission();
      if (current.granted) {
        return { granted: true };
      }
    }

    const action = await promptDenied({
      title: copy.deniedTitle,
      message: copy.deniedMessage,
      canRetry: canAskAgain,
    });

    if (action === 'retry') {
      current = await getPermission();
      if (current.granted) {
        return { granted: true };
      }
      if (current.status === 'denied' && canAskAgain) {
        current = await requestPermission();
        if (current.granted) {
          return { granted: true };
        }
      }
      continue;
    }

    return { granted: false, reason: action };
  }
}

/** Camera for taking photos (profile, document upload). */
export function ensureCameraPermission() {
  return ensurePermission({
    purpose: 'camera',
    getPermission: ImagePicker.getCameraPermissionsAsync,
    requestPermission: ImagePicker.requestCameraPermissionsAsync,
  });
}

/** Camera for government ID upload — short rationale, no startup request. */
export function ensureUploadCameraPermission() {
  return ensurePermission({
    purpose: 'upload_camera',
    getPermission: ImagePicker.getCameraPermissionsAsync,
    requestPermission: ImagePicker.requestCameraPermissionsAsync,
  });
}

/** Photo library / gallery for choosing images. */
export function ensureMediaLibraryPermission() {
  return ensurePermission({
    purpose: 'gallery',
    getPermission: ImagePicker.getMediaLibraryPermissionsAsync,
    requestPermission: ImagePicker.requestMediaLibraryPermissionsAsync,
  });
}

/** Gallery for government ID upload — short rationale, no startup request. */
export function ensureUploadMediaLibraryPermission() {
  return ensurePermission({
    purpose: 'upload_gallery',
    getPermission: ImagePicker.getMediaLibraryPermissionsAsync,
    requestPermission: ImagePicker.requestMediaLibraryPermissionsAsync,
  });
}

/** Camera for QR barcode scanner (expo-camera). */
export function ensureScannerCameraPermission() {
  return ensurePermission({
    purpose: 'scanner',
    getPermission: Camera.getCameraPermissionsAsync,
    requestPermission: Camera.requestCameraPermissionsAsync,
  });
}
