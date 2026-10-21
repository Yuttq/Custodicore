import * as api from '../services/api';
import { getPickedWebFile } from '../services/imagePickerService';
import {
  DOCUMENT_IMAGE_ERRORS,
  toDocumentImageUpload,
  validateDocumentImage,
  validateRegistrationPhoto,
} from '../utils/documentImageRules';
import { RELATIONSHIPS, getRelationshipLabel } from '../utils/registrationRequirements';
import {
  buildRegistrationDocuments,
  buildRelationshipRequirementDocuments,
  hasAnyRelationshipUpload,
  isAwaitingRelationshipLink,
} from '../utils/relationshipRequirementDocuments';
import { visibleRejectionReason } from '../utils/verificationStatusUi';
import { GOVERNMENT_ID_KEY } from '../utils/visitorVerificationDocuments';

/**
 * Visitor verification data access (Phase 4): government IDs and supporting
 * documents from GET /api/documents, uploads to POST /api/documents and
 * POST /api/relationships/{id}/requirements/{key} (one file per checklist
 * requirement, which is what the Record Officer reviews).
 *
 * The backend is the source of truth for every status (pending / verified /
 * rejected). This module only maps those values onto the existing UI shapes —
 * it never sets or invents a verification result.
 */

/** Government ID labels used in the app → backend `visitor_ids.id_type` keys. */
export const GOVERNMENT_ID_TYPES = [
  { key: 'national_id', label: 'National ID' },
  { key: 'drivers_license', label: "Driver's License" },
  { key: 'passport', label: 'Passport' },
  { key: 'voters_id', label: "Voter's ID" },
  { key: 'philhealth_id', label: 'PhilHealth ID' },
  { key: 'umid', label: 'UMID' },
];

/**
 * @param {string | null | undefined} value — backend key or display label
 * @returns {string | null}
 */
export function toGovernmentIdTypeKey(value) {
  const v = String(value ?? '').trim().toLowerCase();
  if (!v) return null;
  const match = GOVERNMENT_ID_TYPES.find(
    (t) => t.key === v || t.label.toLowerCase() === v,
  );
  if (match) return match.key;
  if (v === 'philhealth') return 'philhealth_id';
  return null;
}

/** @param {string | null | undefined} key */
export function getGovernmentIdTypeLabel(key) {
  return GOVERNMENT_ID_TYPES.find((t) => t.key === key)?.label ?? null;
}

/**
 * Maps the free-text relationship hint (stored at registration, e.g. "Spouse")
 * onto a known relationship id for the required-documents list. Unknown hints
 * fall back to `other` (government ID only) rather than guessing.
 * @param {string | null | undefined} hint
 * @param {string | null | undefined} fallback — e.g. registrationSummary.relationship
 */
export function resolveRelationshipKey(hint, fallback) {
  for (const candidate of [hint, fallback]) {
    const v = String(candidate ?? '').trim().toLowerCase();
    if (!v) continue;
    const match = RELATIONSHIPS.find((r) => r.id === v || r.label.toLowerCase() === v);
    if (match) return match.id;
  }
  return 'other';
}

/**
 * @typedef {{ uri: string; fileName?: string | null; mimeType?: string | null; fileSize?: number | null }} DocumentFile
 */

/**
 * Builds the multipart file part. The mobile flows only upload photos, so
 * only JPG, PNG and WEBP images up to 10 MB are accepted here (the backend
 * validates every upload again, and also accepts PDF from other clients).
 * On web the part is the picked browser `File` (a browser FormData sends a
 * `{ uri, name, type }` object as the text "[object Object]"); native
 * platforms keep the React Native `{ uri, name, type }` part.
 * @param {DocumentFile} file
 */
function toFilePart(file) {
  if (!file?.uri) throw new Error('Please add a photo before submitting.');
  const invalid = validateDocumentImage(file);
  if (invalid) throw new Error(invalid);
  const part = toDocumentImageUpload(file);
  if (!part) throw new Error(DOCUMENT_IMAGE_ERRORS.unsupportedType);
  const webFile = getPickedWebFile(file.uri);
  if (webFile) return new File([webFile], part.name, { type: part.type });
  return { uri: file.uri, name: part.name, type: part.type };
}

/**
 * Backend document status → existing UI upload status.
 * (`pending` on an uploaded file means "Under Review" in the app.)
 * @param {string | null | undefined} backendStatus
 * @param {boolean} hasUpload
 */
function toUploadStatus(backendStatus, hasUpload) {
  if (backendStatus === 'verified') return 'verified';
  if (backendStatus === 'rejected') return 'rejected';
  return hasUpload ? 'under_review' : 'pending';
}

/**
 * @param {string | null | undefined} profileStatus — visitor_profiles.verification_status
 * @param {boolean} anyUploaded
 */
function toOverallStatus(profileStatus, anyUploaded) {
  if (profileStatus === 'verified') return 'verification_verified';
  if (profileStatus === 'rejected') return 'verification_rejected';
  return anyUploaded ? 'verification_under_review' : 'verification_pending';
}

/**
 * @typedef {object} VerificationDocument
 * @property {string} key
 * @property {string} label
 * @property {'pending' | 'under_review' | 'verified' | 'rejected'} uploadStatus
 * @property {string | null} uploadedAt
 * @property {string | null} verifiedAt
 * @property {string | null} rejectionReason
 * @property {string | null} reviewNote
 * @property {boolean} canUpload
 * @property {string | null} unavailableReason
 * @property {string | null} detail — e.g. ID type or PDL name
 * @property {string | null} relationshipRecordId — target for relationship uploads
 * @property {string | null} requirementKey — backend checklist key (relationship documents only)
 */

/**
 * @param {unknown} data — GET /api/documents payload
 * @param {string} relationshipKey
 */
export function normalizeVerification(data, relationshipKey) {
  const o = data && typeof data === 'object' ? /** @type {Record<string, any>} */ (data) : {};
  const governmentIds = Array.isArray(o.governmentIds) ? o.governmentIds : [];
  const relationships = Array.isArray(o.relationships) ? o.relationships : [];
  const registrationDocuments = buildRegistrationDocuments(o.registrationDocuments);

  // Prefer a verified ID; otherwise the most recent upload (API is newest-first).
  const currentId =
    governmentIds.find((d) => d?.status === 'verified') ?? governmentIds[0] ?? null;

  /** @type {VerificationDocument[]} */
  const documents = [
    // Relationship documents come from each linked relationship's backend
    // checklist; files sent with the registration are listed read-only
    // until staff link the PDL and they join that checklist.
    ...buildRelationshipRequirementDocuments(relationships),
    ...registrationDocuments,
    {
      key: GOVERNMENT_ID_KEY,
      label: 'Government ID',
      uploadStatus: toUploadStatus(currentId?.status, Boolean(currentId)),
      uploadedAt: currentId?.uploadedAt ?? null,
      verifiedAt: currentId?.verifiedAt ?? null,
      rejectionReason: visibleRejectionReason(currentId?.status, currentId?.rejectionReason),
      reviewNote: null,
      canUpload: currentId?.status !== 'verified',
      unavailableReason: null,
      detail: getGovernmentIdTypeLabel(currentId?.documentType) ?? null,
      relationshipRecordId: null,
      requirementKey: null,
    },
  ];

  const anyUploaded =
    governmentIds.length > 0 ||
    registrationDocuments.length > 0 ||
    hasAnyRelationshipUpload(relationships);

  return {
    backendStatus: typeof o.verificationStatus === 'string' ? o.verificationStatus : null,
    verificationStatus: toOverallStatus(o.verificationStatus, anyUploaded),
    // Account-level reason staff gave when returning the account.
    rejectionReason: visibleRejectionReason(o.verificationStatus, o.rejectionReason),
    relationshipId: relationshipKey,
    relationshipLabel: getRelationshipLabel(relationshipKey),
    awaitingRelationshipLink: isAwaitingRelationshipLink(relationships),
    documents,
  };
}

/**
 * @param {{ relationshipHint?: string | null; fallbackRelationship?: string | null }} [options]
 */
export async function fetchVerification(options = {}) {
  const data = await api.getDocuments();
  const hint =
    (data && typeof data === 'object' && data.relationshipHint) || options.relationshipHint;
  return normalizeVerification(
    data,
    resolveRelationshipKey(hint, options.fallbackRelationship),
  );
}

/**
 * Uploads a government ID (always reviewed by staff; starts as pending).
 * @param {DocumentFile & { documentType: string | null | undefined }} input
 */
export async function uploadGovernmentId({ uri, fileName, mimeType, fileSize, documentType }) {
  const typeKey = toGovernmentIdTypeKey(documentType);
  if (!typeKey) throw new Error('Select which government ID you are uploading.');
  const filePart = toFilePart({ uri, fileName, mimeType, fileSize });
  const form = new FormData();
  form.append('documentType', typeKey);
  form.append('file', /** @type {any} */ (filePart));
  return api.uploadDocument(form);
}

/**
 * Adds the government ID picked during registration to the POST
 * /auth/register multipart body (a new visitor has no token to upload it with
 * afterwards — they must verify their email before logging in).
 * @param {FormData} form
 * @param {DocumentFile & { idType?: string | null }} governmentId
 */
export function appendRegistrationGovernmentId(form, governmentId) {
  const typeKey = toGovernmentIdTypeKey(governmentId?.idType);
  if (!typeKey) throw new Error('Select which government ID you are uploading.');
  const filePart = toFilePart(governmentId);
  form.append('governmentIdType', typeKey);
  form.append('governmentId', /** @type {any} */ (filePart));
}

/**
 * Adds the relationship documents picked during registration to a
 * registration multipart body as relationshipDocuments[requirementKey]. The
 * backend keeps them on the visitor and attaches them to the relationship's
 * checklist when staff link the PDL.
 * @param {FormData} form
 * @param {[string, DocumentFile][]} files — getRegistrationRelationshipFiles()
 */
export function appendRegistrationRelationshipDocuments(form, files) {
  files.forEach(([requirementKey, file]) => {
    form.append(`relationshipDocuments[${requirementKey}]`, /** @type {any} */ (toFilePart(file)));
  });
}

/**
 * Adds the visitor's own photo (taken with RegistrationPhotoCamera) to a
 * registration multipart body as `photo`. Required by both /auth/register
 * and /auth/google/register. On web the capture was already turned into a
 * `File`; native sends the camera's JPEG file.
 * @param {FormData} form
 * @param {DocumentFile & { webFile?: File } | null | undefined} photo
 */
export function appendRegistrationPhoto(form, photo) {
  const invalid = validateRegistrationPhoto(photo);
  if (invalid || !photo) throw new Error(invalid ?? 'Please take a photo of yourself.');
  const part = photo.webFile ?? { uri: photo.uri, name: 'photo.jpg', type: photo.mimeType || 'image/jpeg' };
  form.append('photo', /** @type {any} */ (part));
}

/**
 * Uploads the file for one checklist requirement of the visitor's own
 * relationship. The backend rejects keys the relationship's checklist does
 * not ask for, and keys that are already verified.
 * @param {DocumentFile & { relationshipRecordId: string | null; requirementKey: string | null }} input
 */
export async function uploadRelationshipRequirementDocument({
  relationshipRecordId,
  requirementKey,
  uri,
  fileName,
  mimeType,
  fileSize,
}) {
  if (!relationshipRecordId || !requirementKey) {
    throw new Error('This document can be uploaded once staff link your account to a PDL.');
  }
  const filePart = toFilePart({ uri, fileName, mimeType, fileSize });
  const form = new FormData();
  form.append('file', /** @type {any} */ (filePart));
  return api.uploadRelationshipRequirementDocument(relationshipRecordId, requirementKey, form);
}
