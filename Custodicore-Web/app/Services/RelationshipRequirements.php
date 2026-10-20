<?php

namespace App\Services;

use App\Models\VisitorPdlRelationship;

/**
 * BJMP visitor requirements for one visitor↔PDL relationship, from the
 * facility interview:
 *
 *   Married            → marriage certificate + valid ID
 *   Child of the PDL   → PSA birth certificate + valid ID
 *   Live-in partner    → CENOMAR of both; with children: the child's PSA
 *                        birth certificate; without: a witness statement
 *   Minor visitor      → at least 3 years old, birth certificate,
 *                        accompanied by a parent/guardian
 *   Legal guardian     → proper authorization, only if no immediate family
 *   Extended relative  → only when officially authorized
 *   Friends/others     → not allowed (not an available relationship type)
 *
 * Parent, sibling and legal counsel aren't spelled out in the interview
 * notes; their proofs below (birth certificates / IBP ID) are the usual
 * equivalents and can be adjusted here. They are marked
 * POLICY_PENDING in RELATIONSHIP_RULES — still enforced exactly as before,
 * but not facility-confirmed. The same goes for the valid ID of every type
 * except spouse and child (the interview only lists it for those two).
 * The legal guardian's proof of authorization is pending too: the interview
 * requires "proper authorization" but does not name the document. Its
 * "no immediate family" confirmation stays confirmed (confirmation_policy).
 *
 * Every relationship's checklist must be fully met before the Record
 * Officer can verify the relationship or approve the visitor in
 * Eligibility Review.
 *
 * RELATIONSHIP_RULES is the one source for which documents a relationship
 * needs: for() builds the staff/visitor checklist from it, and
 * registrationGuide() publishes it for GET /api/registration/requirements.
 */
class RelationshipRequirements
{
    public const MINIMUM_AGE = 3;
    public const ADULT_AGE = 18;

    /** From the facility interview (see the class comment). */
    public const POLICY_CONFIRMED = 'confirmed';
    /** Enforced as before, but the facility has not confirmed it yet. */
    public const POLICY_PENDING = 'pending_facility_confirmation';

    /** Who supplies an item: the visitor uploads it, or facility staff issue/confirm it. */
    public const PROVIDED_BY_VISITOR = 'visitor';
    public const PROVIDED_BY_FACILITY = 'facility';

    /**
     * Visitor-provided relationship documents are sent with the registration
     * (POST /auth/register, relationshipDocuments[key]) and attached to the
     * relationship when staff link the PDL. A missing or rejected one is
     * uploaded again afterwards (POST /api/relationships/{id}/requirements/{key}).
     */
    public const SUBMISSION_AT_REGISTRATION = 'at_registration';

    /** Facility-issued documents: only after staff link the visitor to the PDL. */
    public const SUBMISSION_AFTER_STAFF_LINK = 'after_staff_link';

    /**
     * Per relationship type:
     *   description        — what the relationship means
     *   documents          — document keys always required
     *   children_together  — live-in partners only: extra keys by the
     *                        has_children_together answer
     *   confirmations      — officer confirmations required
     *   document_policy    — POLICY_* for the documents/confirmations above
     *   confirmation_policy — optional POLICY_* for the confirmations when
     *                        it differs from document_policy
     *   valid_id_policy    — POLICY_* for the adult valid-ID requirement
     *   registration       — offered: whether the app's registration lists
     *                        the type; policy: POLICY_* for that choice
     *   registration_government_id — whether registration asks for the
     *                        government ID upload today, and its POLICY_*
     */
    public const RELATIONSHIP_RULES = [
        'spouse' => [
            'description' => 'The visitor is married to the PDL.',
            'documents' => ['marriage_certificate'],
            'document_policy' => self::POLICY_CONFIRMED,
            'valid_id_policy' => self::POLICY_CONFIRMED,
            'registration' => ['offered' => true, 'policy' => self::POLICY_CONFIRMED],
            'registration_government_id' => ['requested' => true, 'policy' => self::POLICY_CONFIRMED],
        ],
        'live_in_partner' => [
            'description' => 'The visitor lives with the PDL as a partner without being married.',
            'documents' => ['cenomar_visitor', 'cenomar_pdl'],
            'children_together' => [
                'with_children' => ['child_birth_certificate'],
                'without_children' => ['witness_statement'],
            ],
            'document_policy' => self::POLICY_CONFIRMED,
            'valid_id_policy' => self::POLICY_PENDING,
            'registration' => ['offered' => true, 'policy' => self::POLICY_CONFIRMED],
            'registration_government_id' => ['requested' => true, 'policy' => self::POLICY_PENDING],
        ],
        'parent' => [
            'description' => 'The visitor is the PDL\'s parent.',
            'documents' => ['pdl_birth_certificate'],
            'document_policy' => self::POLICY_PENDING,
            'valid_id_policy' => self::POLICY_PENDING,
            'registration' => ['offered' => true, 'policy' => self::POLICY_CONFIRMED],
            'registration_government_id' => ['requested' => true, 'policy' => self::POLICY_PENDING],
        ],
        'child' => [
            'description' => 'The visitor is the PDL\'s child.',
            'documents' => ['visitor_birth_certificate'],
            'document_policy' => self::POLICY_CONFIRMED,
            'valid_id_policy' => self::POLICY_CONFIRMED,
            'registration' => ['offered' => true, 'policy' => self::POLICY_CONFIRMED],
            'registration_government_id' => ['requested' => true, 'policy' => self::POLICY_CONFIRMED],
        ],
        'sibling' => [
            'description' => 'The visitor is the PDL\'s brother or sister.',
            'documents' => ['visitor_birth_certificate', 'pdl_birth_certificate'],
            'document_policy' => self::POLICY_PENDING,
            'valid_id_policy' => self::POLICY_PENDING,
            'registration' => ['offered' => false, 'policy' => self::POLICY_PENDING],
            'registration_government_id' => ['requested' => false, 'policy' => self::POLICY_PENDING],
        ],
        'legal_guardian' => [
            // The visitor is the guardian — not the PDL being the visitor's guardian.
            'description' => 'The visitor is the PDL\'s legal guardian. Allowed only when the PDL has no immediate family available.',
            'documents' => ['guardianship_authorization'],
            'confirmations' => ['no_immediate_family'],
            // The interview asks for "proper authorization" without naming the document.
            'document_policy' => self::POLICY_PENDING,
            'confirmation_policy' => self::POLICY_CONFIRMED,
            'valid_id_policy' => self::POLICY_PENDING,
            'registration' => ['offered' => true, 'policy' => self::POLICY_CONFIRMED],
            'registration_government_id' => ['requested' => true, 'policy' => self::POLICY_PENDING],
        ],
        'extended_relative' => [
            // Interview: "only when officially authorized". The form of that
            // authorization and whether registration offers the type are open.
            'description' => 'The visitor is a relative outside the immediate family. Allowed only when officially authorized by the facility.',
            'documents' => ['official_authorization'],
            'document_policy' => self::POLICY_PENDING,
            'valid_id_policy' => self::POLICY_PENDING,
            'registration' => ['offered' => false, 'policy' => self::POLICY_PENDING],
            'registration_government_id' => ['requested' => false, 'policy' => self::POLICY_PENDING],
        ],
        'legal_counsel' => [
            'description' => 'The visitor is the PDL\'s lawyer.',
            'documents' => ['proof_of_counsel'],
            'document_policy' => self::POLICY_PENDING,
            'valid_id_policy' => self::POLICY_PENDING,
            'registration' => ['offered' => true, 'policy' => self::POLICY_CONFIRMED],
            'registration_government_id' => ['requested' => true, 'policy' => self::POLICY_PENDING],
        ],
    ];

    /**
     * Documents facility staff issue or confirm. They are still uploaded
     * through the checklist today (that is the existing workflow), but the
     * visitor does not produce them on their own.
     */
    public const FACILITY_ISSUED_DOCUMENTS = ['official_authorization'];

    // Requirements the visitor uploads a file for.
    public const DOCUMENTS = [
        'marriage_certificate' => ['Marriage Certificate', 'PSA-issued marriage certificate of the visitor and the PDL.'],
        'visitor_birth_certificate' => ["Visitor's PSA Birth Certificate", "The visitor's own PSA-issued birth certificate."],
        'pdl_birth_certificate' => ["PDL's PSA Birth Certificate", "The PDL's PSA-issued birth certificate, showing the shared parent(s)."],
        'cenomar_visitor' => ["Visitor's CENOMAR", 'Certificate of No Marriage (PSA) of the visitor.'],
        'cenomar_pdl' => ["PDL's CENOMAR", 'Certificate of No Marriage (PSA) of the PDL.'],
        'child_birth_certificate' => ["Child's PSA Birth Certificate", 'PSA birth certificate of the child the visitor and the PDL have together.'],
        'witness_statement' => ['Witness Statement', 'Signed statement from a witness (e.g. a parent) confirming the relationship, with the witness\'s valid ID.'],
        'guardianship_authorization' => ['Proof of Guardianship / Authorization', 'Proof that the visitor is the PDL\'s legal guardian (e.g. court order or notarized authorization). The facility has not yet confirmed which document it accepts.'],
        'official_authorization' => ['Official Authorization', 'Written authorization from the facility allowing this extended relative to visit.'],
        'proof_of_counsel' => ['Proof of Legal Representation', 'IBP ID and notice of appearance or authorization as the PDL\'s counsel.'],
    ];

    // Requirements the Record Officer confirms instead of a file.
    public const CONFIRMATIONS = [
        'no_immediate_family' => ['No immediate family available', 'Officer confirmed the PDL has no immediate family available to visit, so a legal guardian may be allowed.'],
        'accompanying_guardian' => ['Accompanied by parent/guardian', 'Minor visitors must come with a parent or guardian. Record the name of the accompanying adult.'],
    ];

    /**
     * The checklist for a relationship. Each item:
     *   key, kind (valid_id|document|confirmation|detail|blocker), label,
     *   description, status (met|pending_review|rejected|missing|blocked),
     *   document (RelationshipDocument|null), note, canUpload,
     *   policy (POLICY_*; null for the system-only "reclassify" blocker),
     *   providedBy (PROVIDED_BY_*; null for blockers)
     */
    public static function for(VisitorPdlRelationship $relationship): array
    {
        $relationship->loadMissing(['visitor.idDocuments', 'documents']);
        $visitor = $relationship->visitor;
        $type = $relationship->relationship_type;
        $rule = self::RELATIONSHIP_RULES[$type] ?? null;
        $age = $visitor?->date_of_birth?->age;
        $isMinor = $age !== null && $age < self::ADULT_AGE;

        $items = [];

        if ($relationship->isLegacyType()) {
            $items[] = self::blocker('reclassify', 'Choose the specific relationship',
                'This relationship uses an old generic type. Change it to the specific relationship (spouse, parent, child, ...) to see the required documents.',
                null);
        }

        if ($isMinor && $age < self::MINIMUM_AGE) {
            $items[] = self::blocker('minimum_age', 'Visitor is under ' . self::MINIMUM_AGE . ' years old',
                'Minor visitors must be at least ' . self::MINIMUM_AGE . " years old. This visitor is {$age}.",
                self::POLICY_CONFIRMED);
        }

        // Valid ID — adults only; a minor's birth certificate stands in for it.
        if (! $isMinor) {
            $items[] = self::validId($visitor, $rule['valid_id_policy'] ?? self::POLICY_PENDING);
        }

        // key => policy, in checklist order.
        $documents = array_fill_keys(
            self::documentKeysFor($type, $relationship->has_children_together),
            $rule['document_policy'] ?? self::POLICY_PENDING
        );
        if ($isMinor) {
            // Minor visitor rule (interview); keeps its place if the type already asks for it.
            $documents[self::MINOR_DOCUMENT] = self::POLICY_CONFIRMED;
        }

        if (isset($rule['children_together']) && $relationship->has_children_together === null) {
            $items[] = [
                'key' => 'children_together',
                'kind' => 'detail',
                'label' => 'Children together?',
                'description' => self::CHILDREN_TOGETHER_DESCRIPTION,
                'status' => 'missing',
                'document' => null,
                'note' => null,
                'canUpload' => false,
                'policy' => $rule['document_policy'],
                'providedBy' => self::PROVIDED_BY_FACILITY,
            ];
        }

        foreach ($documents as $key => $policy) {
            $items[] = self::document($relationship, $key, $policy);
        }

        foreach ($rule['confirmations'] ?? [] as $key) {
            $items[] = self::confirmation($relationship, $key, $rule['confirmation_policy'] ?? $rule['document_policy']);
        }
        if ($isMinor) {
            $items[] = self::confirmation($relationship, 'accompanying_guardian', self::POLICY_CONFIRMED);
        }

        return $items;
    }

    /**
     * Document keys a relationship type needs. For live-in partners the
     * child/witness document is added once has_children_together is known.
     */
    public static function documentKeysFor(string $type, ?bool $hasChildrenTogether = null): array
    {
        $rule = self::RELATIONSHIP_RULES[$type] ?? null;
        if (! $rule) {
            return [];
        }

        $keys = $rule['documents'];
        if (isset($rule['children_together']) && $hasChildrenTogether !== null) {
            $keys = array_merge($keys, $rule['children_together'][$hasChildrenTogether ? 'with_children' : 'without_children']);
        }

        return $keys;
    }

    /** A minor visitor's own birth certificate stands in for the valid ID. */
    public const MINOR_DOCUMENT = 'visitor_birth_certificate';

    /** Every document key the relationship's checklist asks for (minor rule included). */
    public static function documentKeysForRelationship(VisitorPdlRelationship $relationship): array
    {
        $keys = self::documentKeysFor($relationship->relationship_type, $relationship->has_children_together);

        $age = $relationship->visitor?->date_of_birth?->age;
        if ($age !== null && $age < self::ADULT_AGE) {
            $keys[] = self::MINOR_DOCUMENT;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Document keys a visitor may send with the registration for the
     * relationship they claim: the type's documents, both live-in-partner
     * options (staff record which one applies), and the minor's birth
     * certificate. Facility-issued documents are never sent by the visitor.
     */
    public static function registrationDocumentKeys(string $type, bool $isMinor): array
    {
        $rule = self::RELATIONSHIP_RULES[$type] ?? null;
        if (! $rule || ! $rule['registration']['offered']) {
            return [];
        }

        $keys = $rule['documents'];
        foreach ($rule['children_together'] ?? [] as $caseKeys) {
            $keys = array_merge($keys, $caseKeys);
        }
        if ($isMinor) {
            $keys[] = self::MINOR_DOCUMENT;
        }

        return array_values(array_filter(
            array_unique($keys),
            fn (string $key) => self::providedBy($key) === self::PROVIDED_BY_VISITOR
        ));
    }

    /** Relationship types the app's registration offers. */
    public static function registrationTypes(): array
    {
        return array_keys(array_filter(self::RELATIONSHIP_RULES, fn ($rule) => $rule['registration']['offered']));
    }

    public static function providedBy(string $documentKey): string
    {
        return in_array($documentKey, self::FACILITY_ISSUED_DOCUMENTS, true)
            ? self::PROVIDED_BY_FACILITY
            : self::PROVIDED_BY_VISITOR;
    }

    // -----------------------------------------------------------------
    // Public registration guidance (GET /api/registration/requirements).
    // Rules only — no visitor, PDL or relationship data.
    // -----------------------------------------------------------------

    public static function registrationGuide(): array
    {
        return [
            'documentSubmission' => self::SUBMISSION_AT_REGISTRATION,
            'documentSubmissionNote' => 'Upload the documents for your relationship with your registration. Facility staff review them after they link your account to the PDL. You only upload a document again if staff reject it or ask for one that was not needed before.',
            'policyStatuses' => [
                self::POLICY_CONFIRMED => 'Based on the facility\'s stated visitor requirements.',
                self::POLICY_PENDING => 'Currently applied, but still awaiting confirmation from the facility. It may change.',
            ],
            'relationshipTypes' => array_map(
                fn (string $type) => self::relationshipTypeGuide($type),
                array_keys(self::RELATIONSHIP_RULES)
            ),
            'minorVisitors' => [
                'adultAge' => self::ADULT_AGE,
                'minimumAge' => self::MINIMUM_AGE,
                // A minor's birth certificate stands in for the valid ID.
                'governmentIdRequired' => false,
                'policy' => self::POLICY_CONFIRMED,
                'documents' => [self::guideDocument('visitor_birth_certificate', self::POLICY_CONFIRMED)],
                'staffConfirmations' => [self::guideConfirmation('accompanying_guardian', self::POLICY_CONFIRMED)],
            ],
        ];
    }

    public static function relationshipTypeGuide(string $type): array
    {
        $rule = self::RELATIONSHIP_RULES[$type];
        $policy = $rule['document_policy'];

        $documents = array_map(fn (string $key) => self::guideDocument($key, $policy), $rule['documents']);
        foreach ($rule['children_together'] ?? [] as $case => $keys) {
            foreach ($keys as $key) {
                $documents[] = self::guideDocument($key, $policy, [
                    'detail' => 'has_children_together',
                    'equals' => $case === 'with_children',
                ]);
            }
        }

        return [
            'key' => $type,
            'label' => VisitorPdlRelationship::TYPE_LABELS[$type],
            'description' => $rule['description'],
            'offeredAtRegistration' => $rule['registration']['offered'],
            'registrationOptionPolicy' => $rule['registration']['policy'],
            'documentPolicy' => $policy,
            'governmentId' => [
                'requiredForAdults' => true,
                'policy' => $rule['valid_id_policy'],
                'requestedAtRegistration' => $rule['registration_government_id']['requested'],
                'registrationPolicy' => $rule['registration_government_id']['policy'],
            ],
            'details' => isset($rule['children_together']) ? [[
                'key' => 'has_children_together',
                'label' => 'Children together?',
                'description' => self::CHILDREN_TOGETHER_DESCRIPTION,
                'providedBy' => self::PROVIDED_BY_FACILITY,
                'policy' => $policy,
            ]] : [],
            'documents' => $documents,
            'staffConfirmations' => array_map(
                fn (string $key) => self::guideConfirmation($key, $rule['confirmation_policy'] ?? $policy),
                $rule['confirmations'] ?? []
            ),
        ];
    }

    private const CHILDREN_TOGETHER_DESCRIPTION = 'Record whether the visitor and the PDL have children together. With children, a child\'s PSA birth certificate is required; without, a witness statement.';

    private static function guideDocument(string $key, string $policy, ?array $condition = null): array
    {
        [$label, $description] = self::DOCUMENTS[$key];

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'required' => true,
            'condition' => $condition,
            'providedBy' => self::providedBy($key),
            'policy' => $policy,
            'submission' => self::providedBy($key) === self::PROVIDED_BY_VISITOR
                ? self::SUBMISSION_AT_REGISTRATION
                : self::SUBMISSION_AFTER_STAFF_LINK,
        ];
    }

    private static function guideConfirmation(string $key, string $policy): array
    {
        [$label, $description] = self::CONFIRMATIONS[$key];

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'providedBy' => self::PROVIDED_BY_FACILITY,
            'policy' => $policy,
        ];
    }

    public static function allMet(array $items): bool
    {
        return collect($items)->every(fn ($item) => $item['status'] === 'met');
    }

    /** Labels of everything still outstanding, for error messages. */
    public static function unmetLabels(array $items): array
    {
        return collect($items)->reject(fn ($item) => $item['status'] === 'met')->pluck('label')->values()->all();
    }

    public static function isDocumentKey(string $key): bool
    {
        return array_key_exists($key, self::DOCUMENTS);
    }

    public static function isConfirmationKey(string $key): bool
    {
        return array_key_exists($key, self::CONFIRMATIONS);
    }

    // -----------------------------------------------------------------

    private static function blocker(string $key, string $label, string $description, ?string $policy): array
    {
        return [
            'key' => $key, 'kind' => 'blocker', 'label' => $label, 'description' => $description,
            'status' => 'blocked', 'document' => null, 'note' => null, 'canUpload' => false,
            'policy' => $policy, 'providedBy' => null,
        ];
    }

    private static function validId($visitor, string $policy): array
    {
        $ids = $visitor?->idDocuments ?? collect();
        $status = match (true) {
            $ids->contains('verification_status', 'verified') => 'met',
            $ids->contains('verification_status', 'pending') => 'pending_review',
            $ids->contains('verification_status', 'rejected') => 'rejected',
            default => 'missing',
        };

        return [
            'key' => 'valid_id',
            'kind' => 'valid_id',
            'label' => 'Valid Government ID',
            'description' => 'A verified government-issued ID (reviewed under Identity Documents).',
            'status' => $status,
            'document' => null,
            'note' => null,
            // Uploaded through the existing government ID flow, not here.
            'canUpload' => false,
            'policy' => $policy,
            'providedBy' => self::PROVIDED_BY_VISITOR,
        ];
    }

    private static function document(VisitorPdlRelationship $relationship, string $key, string $policy): array
    {
        [$label, $description] = self::DOCUMENTS[$key];
        $document = $relationship->latestDocument($key);

        $status = match ($document?->status) {
            'verified' => 'met',
            'pending' => 'pending_review',
            'rejected' => 'rejected',
            default => 'missing',
        };

        return [
            'key' => $key,
            'kind' => 'document',
            'label' => $label,
            'description' => $description,
            'status' => $status,
            'document' => $document,
            'note' => $document?->rejection_reason,
            'canUpload' => $status !== 'met',
            'policy' => $policy,
            'providedBy' => self::providedBy($key),
        ];
    }

    private static function confirmation(VisitorPdlRelationship $relationship, string $key, string $policy): array
    {
        [$label, $description] = self::CONFIRMATIONS[$key];
        $confirmed = $relationship->confirmation($key);

        return [
            'key' => $key,
            'kind' => 'confirmation',
            'label' => $label,
            'description' => $description,
            'status' => $confirmed ? 'met' : 'missing',
            'document' => null,
            'note' => $confirmed['note'] ?? null,
            'canUpload' => false,
            'policy' => $policy,
            'providedBy' => self::PROVIDED_BY_FACILITY,
        ];
    }
}
