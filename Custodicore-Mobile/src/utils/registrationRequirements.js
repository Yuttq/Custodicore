/**
 * Every relationship id the app recognises. `other` is kept so labels and
 * lookups still work for registrations saved before it was removed from
 * step 2; new registrations use REGISTRATION_RELATIONSHIPS.
 */
export const RELATIONSHIPS = [
  { id: 'spouse', label: 'Spouse' },
  { id: 'parent', label: 'Parent' },
  { id: 'child', label: 'Child' },
  { id: 'legal_guardian', label: 'Legal Guardian' },
  { id: 'live_in_partner', label: 'Live-In Partner' },
  { id: 'legal_counsel', label: 'Legal Counsel' },
  { id: 'other', label: 'Other' },
];

/** Relationship options offered in registration step 2 (single selection). */
export const REGISTRATION_RELATIONSHIPS = RELATIONSHIPS.filter((r) => r.id !== 'other');

/** @param {string | null | undefined} relationshipId */
export function isRegistrationRelationship(relationshipId) {
  return REGISTRATION_RELATIONSHIPS.some((r) => r.id === relationshipId);
}

/** Accepted government-issued ID types (step 3). */
export const ACCEPTED_ID_TYPES = [
  'National ID',
  "Driver's License",
  'Passport',
  'PhilHealth',
  'UMID',
  "Voter's ID",
];

export const GENDER_OPTIONS = ['Male', 'Female'];

/**
 * Shown wherever registration documents are listed. The documents for the
 * selected relationship are sent with the registration; staff review them
 * after linking the PDL, and only a rejected or newly requested document is
 * uploaded again later.
 */
export const RELATIONSHIP_DOCUMENTS_NOTICE =
  'Upload the documents listed for your relationship now. They are sent with your registration, so you do not need to upload them again. Facility staff review each document after they link your account to the PDL you are visiting. If staff reject a document or ask for another one, you can upload it through Verification Documents.';

/** The same workflow, after the registration was sent (verification screens). */
export const RELATIONSHIP_DOCUMENTS_SUBMITTED_NOTICE =
  'The documents for your relationship were sent with your registration. Facility staff review each one after they link your account to the PDL you are visiting. You only need to upload a document again if staff reject it or ask for another one.';

/** Visitors under this age are minors (backend RelationshipRequirements::ADULT_AGE). */
export const ADULT_AGE = 18;

/**
 * Relationship documents the visitor uploads with the registration, by
 * relationship. Keys and labels mirror the backend's
 * RelationshipRequirements::RELATIONSHIP_RULES / DOCUMENTS, which also
 * rejects any key the claimed relationship does not ask for.
 */
const RELATIONSHIP_DOCUMENT_LABELS = {
  marriage_certificate: 'Marriage Certificate',
  visitor_birth_certificate: "Visitor's PSA Birth Certificate",
  pdl_birth_certificate: "PDL's PSA Birth Certificate",
  cenomar_visitor: "Visitor's CENOMAR",
  cenomar_pdl: "PDL's CENOMAR",
  child_birth_certificate: "Child's PSA Birth Certificate",
  witness_statement: 'Witness Statement',
  guardianship_authorization: 'Proof of Guardianship / Authorization',
  proof_of_counsel: 'Proof of Legal Representation',
};

const RELATIONSHIP_DOCUMENT_KEYS = {
  spouse: ['marriage_certificate'],
  live_in_partner: ['cenomar_visitor', 'cenomar_pdl'],
  parent: ['pdl_birth_certificate'],
  child: ['visitor_birth_certificate'],
  legal_guardian: ['guardianship_authorization'],
  legal_counsel: ['proof_of_counsel'],
};

/** Live-in partners: the extra document depends on whether they have children together. */
const CHILDREN_TOGETHER_DOCUMENT = { true: 'child_birth_certificate', false: 'witness_statement' };

/** A minor's own birth certificate stands in for the Government ID. */
const MINOR_DOCUMENT = 'visitor_birth_certificate';

/** @param {string | null | undefined} relationshipId */
export function asksChildrenTogether(relationshipId) {
  return relationshipId === 'live_in_partner';
}

/**
 * Shown with Guardian Information in registration summaries saved by app
 * versions that sent it (guardianFullName / guardianRelationship /
 * guardianContactNumber). Registration no longer asks for it.
 */
export const GUARDIAN_INFO_NOTICE =
  'These details are sent to facility staff with your registration.';

/**
 * Registration summaries saved by older app versions, whose Guardian
 * Information really was kept on the device only.
 */
export const LOCAL_INFO_NOTICE =
  'Saved on this device only. It was not sent to facility staff with your registration.';

/**
 * Notice for the "Information Provided" items of a saved registration
 * summary: only items marked `submitted` were sent with the registration.
 * @param {{ submitted?: boolean }[]} items
 */
export function getInfoNotice(items) {
  return items.length > 0 && items.every((item) => item?.submitted === true)
    ? GUARDIAN_INFO_NOTICE
    : LOCAL_INFO_NOTICE;
}

/**
 * Registration step-3 items for the selected relationship, all sent with the
 * registration: the Government ID (adults) and the relationship documents
 * (`requirementKey` = backend checklist key). Only the documents for this
 * relationship are asked for.
 * @param {string} relationshipId
 * @param {{ isMinor?: boolean, hasChildrenTogether?: boolean | null }} [options]
 * @returns {{ key: string, label: string, requiresIdType?: boolean, requirementKey?: string }[]}
 */
export function getRequiredDocuments(relationshipId, { isMinor = false, hasChildrenTogether = null } = {}) {
  const items = [];
  if (!isMinor) {
    items.push({ key: 'government_id', label: 'Government ID', requiresIdType: true });
  }

  const keys = [...(RELATIONSHIP_DOCUMENT_KEYS[relationshipId] ?? [])];
  if (asksChildrenTogether(relationshipId) && typeof hasChildrenTogether === 'boolean') {
    keys.push(CHILDREN_TOGETHER_DOCUMENT[String(hasChildrenTogether)]);
  }
  if (isMinor && relationshipId in RELATIONSHIP_DOCUMENT_KEYS && !keys.includes(MINOR_DOCUMENT)) {
    keys.push(MINOR_DOCUMENT);
  }

  keys.forEach((key) => {
    items.push({ key, label: RELATIONSHIP_DOCUMENT_LABELS[key], requirementKey: key });
  });
  return items;
}

export function getRelationshipLabel(relationshipId) {
  return RELATIONSHIPS.find((r) => r.id === relationshipId)?.label ?? '—';
}
