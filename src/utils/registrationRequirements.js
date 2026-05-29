/** Relationship options — single selection in step 2. */
export const RELATIONSHIPS = [
  { id: 'spouse', label: 'Spouse' },
  { id: 'parent', label: 'Parent' },
  { id: 'child', label: 'Child' },
  { id: 'legal_guardian', label: 'Legal Guardian' },
  { id: 'live_in_partner', label: 'Live-In Partner' },
  { id: 'legal_counsel', label: 'Legal Counsel' },
  { id: 'other', label: 'Other' },
];

/** Accepted government-issued ID types (step 3). */
export const ACCEPTED_ID_TYPES = [
  'National ID',
  "Driver's License",
  'Passport',
  'PhilHealth',
  'UMID',
  "Voter's ID",
];

export const GENDER_OPTIONS = ['Male', 'Female', 'Other', 'Prefer not to say'];

/**
 * Required uploads for the selected relationship.
 * @param {string} relationshipId
 * @returns {{ key: string, label: string, requiresIdType?: boolean, isText?: boolean }[]}
 */
export function getRequiredDocuments(relationshipId) {
  switch (relationshipId) {
    case 'spouse':
      return [
        { key: 'marriage_certificate', label: 'Marriage Certificate' },
        { key: 'government_id', label: 'Government ID', requiresIdType: true },
      ];
    case 'parent':
      return [{ key: 'government_id', label: 'Government ID', requiresIdType: true }];
    case 'child':
      return [
        { key: 'birth_certificate', label: 'Birth Certificate' },
        { key: 'government_id', label: 'Government ID', requiresIdType: true },
      ];
    case 'live_in_partner':
      return [
        { key: 'cenomar', label: 'CENOMAR' },
        { key: 'government_id', label: 'Government ID', requiresIdType: true },
      ];
    case 'legal_guardian':
      return [
        { key: 'birth_certificate', label: 'Birth Certificate' },
        { key: 'guardian_information', label: 'Guardian Information', isText: true },
      ];
    case 'legal_counsel':
    case 'other':
    default:
      return [{ key: 'government_id', label: 'Government ID', requiresIdType: true }];
  }
}

export function getRelationshipLabel(relationshipId) {
  return RELATIONSHIPS.find((r) => r.id === relationshipId)?.label ?? '—';
}
