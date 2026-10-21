// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  DOCUMENT_IMAGE_ERRORS,
  DOCUMENT_IMAGE_MAX_BYTES,
  REGISTRATION_PHOTO_ERRORS,
  REGISTRATION_PHOTO_MAX_BYTES,
  hasVideoInputDevice,
  toDocumentImageUpload,
  validateDocumentImage,
  validateRegistrationPhoto,
} from './documentImageRules.js';

test('max size is 10 MB', () => {
  assert.equal(DOCUMENT_IMAGE_MAX_BYTES, 10 * 1024 * 1024);
});

test('supported MIME types are accepted', () => {
  for (const mimeType of ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'IMAGE/PNG']) {
    assert.equal(validateDocumentImage({ uri: 'file:///a', mimeType }), null, mimeType);
  }
});

test('supported extensions are accepted without a MIME type', () => {
  for (const fileName of ['id.jpg', 'id.jpeg', 'id.png', 'id.webp']) {
    assert.equal(validateDocumentImage({ uri: 'file:///a', fileName }), null, fileName);
  }
});

test('extensions are case-insensitive', () => {
  for (const fileName of ['ID.JPG', 'id.JpEg', 'scan.PNG', 'photo.WebP']) {
    assert.equal(validateDocumentImage({ fileName }), null, fileName);
  }
  assert.equal(validateDocumentImage({ uri: 'file:///cache/IMG_1.JPEG' }), null);
});

test('PDF, GIF and HEIC are rejected by MIME type', () => {
  for (const mimeType of ['application/pdf', 'image/gif', 'image/heic', 'image/heif']) {
    assert.equal(
      validateDocumentImage({ uri: 'file:///a', mimeType }),
      DOCUMENT_IMAGE_ERRORS.unsupportedType,
      mimeType,
    );
  }
});

test('PDF, GIF and HEIC are rejected by extension', () => {
  for (const fileName of ['id.pdf', 'id.gif', 'IMG_0001.HEIC']) {
    assert.equal(validateDocumentImage({ fileName }), DOCUMENT_IMAGE_ERRORS.unsupportedType);
  }
  assert.equal(
    validateDocumentImage({ uri: 'file:///cache/IMG_0001.heic?x=1' }),
    DOCUMENT_IMAGE_ERRORS.unsupportedType,
  );
});

test('exactly 10 MB is accepted', () => {
  assert.equal(
    validateDocumentImage({ mimeType: 'image/jpeg', fileSize: DOCUMENT_IMAGE_MAX_BYTES }),
    null,
  );
});

test('one byte above 10 MB is rejected', () => {
  assert.equal(
    validateDocumentImage({ mimeType: 'image/jpeg', fileSize: DOCUMENT_IMAGE_MAX_BYTES + 1 }),
    DOCUMENT_IMAGE_ERRORS.tooLarge,
  );
});

test('missing MIME type and size proceed to server-side validation', () => {
  assert.equal(validateDocumentImage({ uri: 'content://media/external/images/media/42' }), null);
  assert.equal(validateDocumentImage({ uri: 'file:///a', mimeType: null, fileSize: null }), null);
  assert.equal(validateDocumentImage({ uri: 'file:///a', mimeType: '', fileSize: undefined }), null);
  assert.equal(validateDocumentImage({ uri: 'file:///a', fileSize: Number.NaN }), null);
  assert.equal(validateDocumentImage(null), null);
  assert.equal(validateDocumentImage(undefined), null);
});

test('MIME type takes precedence over a misleading file name', () => {
  assert.equal(
    validateDocumentImage({ fileName: 'photo.jpg', mimeType: 'image/heic' }),
    DOCUMENT_IMAGE_ERRORS.unsupportedType,
  );
  assert.equal(
    validateDocumentImage({ fileName: 'document.pdf', mimeType: 'image/png' }),
    null,
  );
});

test('upload part aligns the file name with the MIME type', () => {
  assert.deepEqual(toDocumentImageUpload({ fileName: 'photo.jpg', mimeType: 'image/png' }), {
    name: 'photo.png',
    type: 'image/png',
  });
  assert.deepEqual(toDocumentImageUpload({ fileName: 'id.JPEG', mimeType: 'image/jpg' }), {
    name: 'id.JPEG',
    type: 'image/jpeg',
  });
  assert.deepEqual(toDocumentImageUpload({ fileName: '', mimeType: 'image/webp' }), {
    name: 'document.webp',
    type: 'image/webp',
  });
  assert.equal(toDocumentImageUpload({ fileName: 'a.png', mimeType: 'image/heic' }), null);
});

test('upload part falls back to extensions, then JPEG', () => {
  assert.deepEqual(toDocumentImageUpload({ fileName: 'scan.PNG' }), {
    name: 'scan.PNG',
    type: 'image/png',
  });
  assert.deepEqual(toDocumentImageUpload({ fileName: 'id', uri: 'file:///x/abc.webp' }), {
    name: 'id.webp',
    type: 'image/webp',
  });
  assert.deepEqual(toDocumentImageUpload({ uri: 'content://media/42' }), {
    name: 'document.jpg',
    type: 'image/jpeg',
  });
  assert.equal(toDocumentImageUpload({ fileName: 'x.gif' }), null);
});

test('registration photo is required', () => {
  assert.equal(validateRegistrationPhoto(null), REGISTRATION_PHOTO_ERRORS.missing);
  assert.equal(validateRegistrationPhoto({ uri: '' }), REGISTRATION_PHOTO_ERRORS.missing);
});

test('registration photo uses the 5 MB backend limit, not the 10 MB document limit', () => {
  assert.equal(REGISTRATION_PHOTO_MAX_BYTES, 5 * 1024 * 1024);
  const photo = { uri: 'file:///photo.jpg', fileName: 'photo.jpg', mimeType: 'image/jpeg' };
  assert.equal(validateRegistrationPhoto({ ...photo, fileSize: REGISTRATION_PHOTO_MAX_BYTES }), null);
  assert.equal(
    validateRegistrationPhoto({ ...photo, fileSize: REGISTRATION_PHOTO_MAX_BYTES + 1 }),
    REGISTRATION_PHOTO_ERRORS.tooLarge,
  );
  // Native camera captures report no size; the backend checks it.
  assert.equal(validateRegistrationPhoto({ ...photo, fileSize: null }), null);
});

test('registration photo must be a supported picture', () => {
  assert.equal(
    validateRegistrationPhoto({ uri: 'blob:x', fileName: 'photo.gif', mimeType: 'image/gif' }),
    REGISTRATION_PHOTO_ERRORS.unsupportedType,
  );
  assert.equal(validateRegistrationPhoto({ uri: 'data:image/jpeg;base64,AA', mimeType: 'image/jpeg' }), null);
});

test('web camera check needs a listed video input (no camera means unavailable, not denied)', () => {
  assert.equal(hasVideoInputDevice([{ kind: 'videoinput', label: '' }]), true);
  assert.equal(hasVideoInputDevice([{ kind: 'audioinput' }, { kind: 'audiooutput' }]), false);
  assert.equal(hasVideoInputDevice([]), false);
  assert.equal(hasVideoInputDevice(undefined), false);
});
