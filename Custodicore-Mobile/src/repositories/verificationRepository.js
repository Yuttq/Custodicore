import * as api from '../services/api';
import { RELATIONSHIPS, getRelationshipLabel } from '../utils/registrationRequirements';
import {
  GOVERNMENT_ID_KEY,
  getRequiredVerificationDocuments,
} from '../utils/visitorVerificationDocuments';

/**
 * Visitor verification data access (Phase 4): government IDs and supporting
 * documents from GET /api/documents, uploads to POST /api/documents and
 * POST /api/relationships/{id}/supporting-document.
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

const ALLOWED_EXTENSIONS = {
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
  pdf: 'application/pdf',
};

/**
 * Builds the multipart file part. Rejects formats the backend will not accept.
 * @param {{ uri: string; fileName?: string | null }} file
 */
function toFilePart(file) {
  if (!file?.uri) throw new Error('Please add a photo before submitting.');
  let name = String(file.fileName || '').trim();
  let ext = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
  if (!ext) {
    const fromUri = String(file.uri).split('?')[0].split('.').pop()?.toLowerCase() ?? '';
    ext = ALLOWED_EXTENSIONS[fromUri] ? fromUri : 'jpg';
    name = `${name || 'document'}.${ext}`;
  }
  const type = ALLOWED_EXTENSIONS[ext];
  if (!type) {
    throw new Error('Unsupported file type. Upload a JPG, PNG, WEBP, or PDF file.');
  }
  return { uri: file.uri, name, type };
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
 * @property {string | null} relationshipRecordId — target for supporting uploads
 */

/**
 * @param {unknown} data — GET /api/documents payload
 * @param {string} relationshipKey
 */
export function normalizeVerification(data, relationshipKey) {
  const o = data && typeof data === 'object' ? /** @type {Record<string, any>} */ (data) : {};
  const governmentIds = Array.isArray(o.governmentIds) ? o.governmentIds : [];
  const relationships = Array.isArray(o.relationships) ? o.relationships : [];

  // Prefer a verified ID; otherwise the most recent upload (API is newest-first).
  const currentId =
    governmentIds.find((d) => d?.status === 'verified') ?? governmentIds[0] ?? null;
  // Supporting documents attach to the visitor's staff-created relationship record.
  const relationshipRecord =
    relationships.find((r) => r?.status !== 'rejected') ?? relationships[0] ?? null;

  /** @type {VerificationDocument[]} */
  const documents = getRequiredVerificationDocuments(relationshipKey).map((doc) => {
    if (doc.key === GOVERNMENT_ID_KEY) {
      return {
        key: doc.key,
        label: doc.label,
        uploadStatus: toUploadStatus(currentId?.status, Boolean(currentId)),
        uploadedAt: currentId?.uploadedAt ?? null,
        verifiedAt: currentId?.verifiedAt ?? null,
        rejectionReason: null,
        reviewNote: null,
        canUpload: currentId?.status !== 'verified',
        unavailableReason: null,
        detail: getGovernmentIdTypeLabel(currentId?.documentType) ?? null,
        relationshipRecordId: null,
      };
    }

    if (!relationshipRecord) {
      return {
        key: doc.key,
        label: doc.label,
        uploadStatus: 'pending',
        uploadedAt: null,
        verifiedAt: null,
        rejectionReason: null,
        reviewNote: null,
        canUpload: false,
        unavailableReason:
          'You can upload this once facility staff link your account to the PDL you are visiting.',
        detail: null,
        relationshipRecordId: null,
      };
    }

    return {
      key: doc.key,
      label: doc.label,
      uploadStatus: toUploadStatus(
        relationshipRecord.status,
        Boolean(relationshipRecord.hasSupportingDocument),
      ),
      uploadedAt: null, // the backend does not record when a supporting document was uploaded
      verifiedAt: relationshipRecord.verifiedAt ?? null,
      rejectionReason: null,
      reviewNote: null,
      canUpload: relationshipRecord.status !== 'verified',
      unavailableReason: null,
      detail: relationshipRecord.pdlName ? `For ${relationshipRecord.pdlName}` : null,
      relationshipRecordId: String(relationshipRecord.id),
    };
  });

  const anyUploaded =
    governmentIds.length > 0 || relationships.some((r) => r?.hasSupportingDocument);

  return {
    backendStatus: typeof o.verificationStatus === 'string' ? o.verificationStatus : null,
    verificationStatus: toOverallStatus(o.verificationStatus, anyUploaded),
    relationshipId: relationshipKey,
    relationshipLabel: getRelationshipLabel(relationshipKey),
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
 * @param {{ uri: string; fileName?: string | null; documentType: string | null | undefined }} input
 */
export async function uploadGovernmentId({ uri, fileName, documentType }) {
  const typeKey = toGovernmentIdTypeKey(documentType);
  if (!typeKey) throw new Error('Select which government ID you are uploading.');
  const form = new FormData();
  form.append('documentType', typeKey);
  form.append('file', /** @type {any} */ (toFilePart({ uri, fileName })));
  return api.uploadDocument(form);
}

/**
 * Uploads the supporting document for the visitor's own relationship record.
 * @param {{ relationshipRecordId: string; uri: string; fileName?: string | null }} input
 */
export async function uploadSupportingDocument({ relationshipRecordId, uri, fileName }) {
  if (!relationshipRecordId) {
    throw new Error('This document can be uploaded once staff link your account to a PDL.');
  }
  const form = new FormData();
  form.append('file', /** @type {any} */ (toFilePart({ uri, fileName })));
  return api.uploadSupportingDocument(relationshipRecordId, form);
}
