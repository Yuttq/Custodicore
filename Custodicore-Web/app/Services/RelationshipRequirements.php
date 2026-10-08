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
 * equivalents and can be adjusted here.
 *
 * Every relationship's checklist must be fully met before the Record
 * Officer can verify the relationship or approve the visitor in
 * Eligibility Review.
 */
class RelationshipRequirements
{
    public const MINIMUM_AGE = 3;
    public const ADULT_AGE = 18;

    // Requirements the visitor uploads a file for.
    public const DOCUMENTS = [
        'marriage_certificate' => ['Marriage Certificate', 'PSA-issued marriage certificate of the visitor and the PDL.'],
        'visitor_birth_certificate' => ["Visitor's PSA Birth Certificate", "The visitor's own PSA-issued birth certificate."],
        'pdl_birth_certificate' => ["PDL's PSA Birth Certificate", "The PDL's PSA-issued birth certificate, showing the shared parent(s)."],
        'cenomar_visitor' => ["Visitor's CENOMAR", 'Certificate of No Marriage (PSA) of the visitor.'],
        'cenomar_pdl' => ["PDL's CENOMAR", 'Certificate of No Marriage (PSA) of the PDL.'],
        'child_birth_certificate' => ["Child's PSA Birth Certificate", 'PSA birth certificate of the child the visitor and the PDL have together.'],
        'witness_statement' => ['Witness Statement', 'Signed statement from a witness (e.g. a parent) confirming the relationship, with the witness\'s valid ID.'],
        'guardianship_authorization' => ['Proof of Guardianship / Authorization', 'Court order, notarized authorization or other proof that the visitor is the PDL\'s legal guardian.'],
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
     *   document (RelationshipDocument|null), note, canUpload
     */
    public static function for(VisitorPdlRelationship $relationship): array
    {
        $relationship->loadMissing(['visitor.idDocuments', 'documents']);
        $visitor = $relationship->visitor;
        $type = $relationship->relationship_type;
        $age = $visitor?->date_of_birth?->age;
        $isMinor = $age !== null && $age < self::ADULT_AGE;

        $items = [];

        if ($relationship->isLegacyType()) {
            $items[] = self::blocker('reclassify', 'Choose the specific relationship',
                'This relationship uses an old generic type. Change it to the specific relationship (spouse, parent, child, ...) to see the required documents.');
        }

        if ($isMinor && $age < self::MINIMUM_AGE) {
            $items[] = self::blocker('minimum_age', 'Visitor is under ' . self::MINIMUM_AGE . ' years old',
                'Minor visitors must be at least ' . self::MINIMUM_AGE . " years old. This visitor is {$age}.");
        }

        // Valid ID — adults only; a minor's birth certificate stands in for it.
        if (! $isMinor) {
            $items[] = self::validId($visitor);
        }

        $documentKeys = match ($type) {
            'spouse' => ['marriage_certificate'],
            'child' => ['visitor_birth_certificate'],
            'parent' => ['pdl_birth_certificate'],
            'sibling' => ['visitor_birth_certificate', 'pdl_birth_certificate'],
            'live_in_partner' => array_merge(
                ['cenomar_visitor', 'cenomar_pdl'],
                match ($relationship->has_children_together) {
                    true => ['child_birth_certificate'],
                    false => ['witness_statement'],
                    default => [],
                }
            ),
            'legal_guardian' => ['guardianship_authorization'],
            'extended_relative' => ['official_authorization'],
            'legal_counsel' => ['proof_of_counsel'],
            default => [],
        };

        if ($isMinor) {
            $documentKeys[] = 'visitor_birth_certificate';
        }

        if ($type === 'live_in_partner' && $relationship->has_children_together === null) {
            $items[] = [
                'key' => 'children_together',
                'kind' => 'detail',
                'label' => 'Children together?',
                'description' => 'Record whether the visitor and the PDL have children together. With children, a child\'s PSA birth certificate is required; without, a witness statement.',
                'status' => 'missing',
                'document' => null,
                'note' => null,
                'canUpload' => false,
            ];
        }

        foreach (array_unique($documentKeys) as $key) {
            $items[] = self::document($relationship, $key);
        }

        if ($type === 'legal_guardian') {
            $items[] = self::confirmation($relationship, 'no_immediate_family');
        }
        if ($isMinor) {
            $items[] = self::confirmation($relationship, 'accompanying_guardian');
        }

        return $items;
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

    private static function blocker(string $key, string $label, string $description): array
    {
        return [
            'key' => $key, 'kind' => 'blocker', 'label' => $label, 'description' => $description,
            'status' => 'blocked', 'document' => null, 'note' => null, 'canUpload' => false,
        ];
    }

    private static function validId($visitor): array
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
        ];
    }

    private static function document(VisitorPdlRelationship $relationship, string $key): array
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
        ];
    }

    private static function confirmation(VisitorPdlRelationship $relationship, string $key): array
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
        ];
    }
}
