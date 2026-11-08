/**
 * "Add Another PDL" (Phase 5B): an approved visitor asks facility staff to
 * add another PDL by name, with the documents that relationship needs.
 * POST /api/pdl-requests (multipart), POST /api/pdl-requests/{id}/cancel, and
 * `pdlRequest` in GET /api/documents. Pure — no React Native imports — so it
 * runs under `node --test`.
 *
 * The documents are the registration's relationship documents for the same
 * relationship (getRequiredDocuments), without the Government ID: the
 * visitor's account is already approved. A minor still sends their own
 * birth certificate, exactly as the backend asks (PdlLinkRequestService).
 */

import { getIntendedPdlNameError } from './intendedPdlName.js';
import {
  asksChildrenTogether,
  getRelationshipLabel,
  getRequiredDocuments,
  isRegistrationRelationship,
} from './registrationRequirements.js';

/** The four steps, in the registration's order. */
export const PDL_REQUEST_STEPS = ['PDL Name', 'Relationship Type', 'Relationship Documents', 'Review & Submit'];

/**
 * Documents to upload for the request: the relationship's documents only.
 * @param {string | null | undefined} relationshipId
 * @param {{ isMinor?: boolean, hasChildrenTogether?: boolean | null }} [options]
 * @returns {{ key: string, label: string, requirementKey: string }[]}
 */
export function getPdlRequestDocuments(relationshipId, { isMinor = false, hasChildrenTogether = null } = {}) {
  if (!isRegistrationRelationship(relationshipId)) return [];
  return /** @type {{ key: string, label: string, requirementKey: string }[]} */ (
    getRequiredDocuments(/** @type {string} */ (relationshipId), { isMinor, hasChildrenTogether }).filter(
      (doc) => typeof doc.requirementKey === 'string',
    )
  );
}

/**
 * Errors for one step (1–3), keyed like the screen shows them; {} when valid.
 * @param {number} step
 * @param {{ pdlName?: string, relationship?: string | null, hasChildrenTogether?: boolean | null, isMinor?: boolean, files?: Record<string, { uri?: string } | undefined> }} state
 * @returns {Record<string, string>}
 */
export function getPdlRequestStepErrors(step, state) {
  /** @type {Record<string, string>} */
  const errors = {};
  if (step === 1) {
    const nameError = getIntendedPdlNameError(state.pdlName);
    if (nameError) errors.pdlName = nameError;
  }
  if (step === 2) {
    if (!isRegistrationRelationship(state.relationship)) {
      errors.relationship = 'Select your relationship to the PDL';
    } else if (asksChildrenTogether(state.relationship) && typeof state.hasChildrenTogether !== 'boolean') {
      errors.children_together = 'Tell us whether you and the PDL have children together';
    }
  }
  if (step === 3) {
    const docs = getPdlRequestDocuments(state.relationship, {
      isMinor: Boolean(state.isMinor),
      hasChildrenTogether: state.hasChildrenTogether ?? null,
    });
    docs.forEach((doc) => {
      if (!state.files?.[doc.key]?.uri) errors[doc.key] = `Upload ${doc.label}`;
    });
  }
  return errors;
}

/**
 * The first step (1–3) with an error, or null when every step is valid.
 * @param {Parameters<typeof getPdlRequestStepErrors>[1]} state
 */
export function firstInvalidPdlRequestStep(state) {
  for (const step of [1, 2, 3]) {
    if (Object.keys(getPdlRequestStepErrors(step, state)).length > 0) return step;
  }
  return null;
}

/**
 * 422 `errors` from POST /api/pdl-requests → { step, errors } for the screen.
 * pdlName → step 1; relationshipType / hasChildrenTogether → step 2;
 * documents / documents.<key> → step 3 (keyed by requirement key).
 * @param {Record<string, string[] | string> | null | undefined} serverErrors
 * @returns {{ step: number, errors: Record<string, string> } | null}
 */
export function mapPdlRequestServerErrors(serverErrors) {
  if (!serverErrors || typeof serverErrors !== 'object') return null;
  /** @type {Record<string, string>} */
  const errors = {};
  let step = 4;
  const first = (v) => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));

  Object.entries(serverErrors).forEach(([field, messages]) => {
    const message = first(messages);
    if (!message) return;
    if (field === 'pdlName') {
      errors.pdlName = message;
      step = Math.min(step, 1);
    } else if (field === 'relationshipType') {
      errors.relationship = message;
      step = Math.min(step, 2);
    } else if (field === 'hasChildrenTogether') {
      errors.children_together = message;
      step = Math.min(step, 2);
    } else if (field.startsWith('documents.')) {
      errors[field.slice('documents.'.length)] = message;
      step = Math.min(step, 3);
    } else if (field === 'documents') {
      errors.documents = message;
      step = Math.min(step, 3);
    } else {
      errors.form = message;
    }
  });

  return Object.keys(errors).length > 0 ? { step, errors } : null;
}

/**
 * Multipart fields for POST /api/pdl-requests (files are added by the caller).
 * Booleans go as "1" / "0", which the backend reads as true / false.
 * @param {{ pdlName: string, relationship: string, hasChildrenTogether: boolean | null }} input
 * @returns {[string, string][]}
 */
export function toPdlRequestFields({ pdlName, relationship, hasChildrenTogether }) {
  /** @type {[string, string][]} */
  const fields = [
    ['pdlName', String(pdlName ?? '').trim().replace(/\s+/g, ' ')],
    ['relationshipType', String(relationship)],
  ];
  if (asksChildrenTogether(relationship) && typeof hasChildrenTogether === 'boolean') {
    fields.push(['hasChildrenTogether', hasChildrenTogether ? '1' : '0']);
  }
  return fields;
}

/**
 * @typedef {object} PdlRequestStatus
 * @property {string} id
 * @property {'open' | 'rejected'} status
 * @property {string} statusLabel — "Pending Review" / "Not Accepted"
 * @property {string} pdlName
 * @property {string | null} relationshipType — registration relationship id, when valid
 * @property {string} relationshipLabel
 * @property {boolean | null} hasChildrenTogether
 * @property {string | null} rejectionReason
 * @property {string | null} createdAt
 * @property {{ key: string, label: string }[]} documents
 */

/**
 * GET /api/documents `pdlRequest` → what My PDLs shows, or null. Only an open
 * or a (recently) rejected request is shown: a cancelled one is no longer
 * active, and a linked one appears as a relationship under My PDLs instead.
 * @param {unknown} raw
 * @returns {PdlRequestStatus | null}
 */
export function normalizePdlRequest(raw) {
  if (!raw || typeof raw !== 'object') return null;
  const r = /** @type {Record<string, any>} */ (raw);
  if (r.id == null || (r.status !== 'open' && r.status !== 'rejected')) return null;

  const relationshipLabel =
    typeof r.relationshipLabel === 'string' && r.relationshipLabel.trim()
      ? r.relationshipLabel.trim()
      : getRelationshipLabel(r.relationshipType);

  return {
    id: String(r.id),
    status: r.status,
    statusLabel: r.status === 'open' ? 'Pending Review' : 'Not Accepted',
    pdlName: typeof r.pdlName === 'string' && r.pdlName.trim() ? r.pdlName.trim() : 'PDL',
    relationshipType: isRegistrationRelationship(r.relationshipType) ? r.relationshipType : null,
    relationshipLabel,
    hasChildrenTogether: typeof r.hasChildrenTogether === 'boolean' ? r.hasChildrenTogether : null,
    rejectionReason:
      r.status === 'rejected' && typeof r.rejectionReason === 'string' && r.rejectionReason.trim()
        ? r.rejectionReason.trim()
        : null,
    createdAt: typeof r.createdAt === 'string' ? r.createdAt : null,
    documents: (Array.isArray(r.documents) ? r.documents : [])
      .filter((d) => d && typeof d.key === 'string')
      .map((d) => ({ key: d.key, label: String(d.label ?? d.key) })),
  };
}

/**
 * "Add Another PDL" is offered to an approved account without an open
 * request (a rejected or cancelled one does not block a new request). The
 * backend enforces both rules again.
 * @param {string | null | undefined} backendStatus — visitor verification_status
 * @param {PdlRequestStatus | null | undefined} pdlRequest — normalizePdlRequest()
 */
export function canAddAnotherPdl(backendStatus, pdlRequest) {
  return backendStatus === 'verified' && pdlRequest?.status !== 'open';
}

/**
 * @typedef {object} PdlRequestPrefill
 * @property {string} pdlName
 * @property {string | null} relationship — registration relationship id
 * @property {boolean | null} hasChildrenTogether
 * @property {string | null} rejectionReason — shown while correcting
 */

/**
 * Phase 4 "Request Again": the rejected request's details to start a new
 * request from, or null for anything but a rejected request. Only the typed
 * details are reused — documents must be attached again (the backend takes
 * files with each new request), and the new request goes to staff review
 * like any other.
 * @param {PdlRequestStatus | null | undefined} pdlRequest — normalizePdlRequest()
 * @returns {PdlRequestPrefill | null}
 */
export function pdlRequestPrefill(pdlRequest) {
  if (!pdlRequest || pdlRequest.status !== 'rejected') return null;
  const relationship = isRegistrationRelationship(pdlRequest.relationshipType)
    ? /** @type {string} */ (pdlRequest.relationshipType)
    : null;
  return {
    pdlName: pdlRequest.pdlName === 'PDL' ? '' : pdlRequest.pdlName,
    relationship,
    hasChildrenTogether:
      relationship && asksChildrenTogether(relationship) && typeof pdlRequest.hasChildrenTogether === 'boolean'
        ? pdlRequest.hasChildrenTogether
        : null,
    rejectionReason: pdlRequest.rejectionReason ?? null,
  };
}

/**
 * "Request Again" for a PDL relationship staff rejected: a rejected
 * relationship cannot take new documents, so the visitor sends a new Add
 * Another PDL request (staff then reopen that relationship). Prefills the
 * PDL name and relationship from it; null for anything but a rejected one.
 * @param {{ status?: string; pdlName?: string; relationshipType?: string | null; hasChildrenTogether?: boolean | null } | null | undefined} pdl — buildPdlRelationshipList() entry
 * @param {string | null} [rejectionReason] — the staff reason, when known
 * @returns {PdlRequestPrefill | null}
 */
export function relationshipRequestPrefill(pdl, rejectionReason = null) {
  if (!pdl || pdl.status !== 'rejected') return null;
  const relationship = isRegistrationRelationship(pdl.relationshipType)
    ? /** @type {string} */ (pdl.relationshipType)
    : null;
  return {
    pdlName: !pdl.pdlName || pdl.pdlName === 'PDL' ? '' : pdl.pdlName,
    relationship,
    hasChildrenTogether:
      relationship && asksChildrenTogether(relationship) && typeof pdl.hasChildrenTogether === 'boolean'
        ? pdl.hasChildrenTogether
        : null,
    rejectionReason: rejectionReason || null,
  };
}
