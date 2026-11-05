<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Pdl;
use App\Models\RelationshipDocument;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One Approve on Account Review does the whole check at once: the officer has
 * looked at the visitor's photo, ID and documents on the card, so approving
 * the account also approves
 *   - every government ID still waiting for review,
 *   - the PDL relationship: the one already on file, or the PDL and
 *     relationship the visitor gave at registration (the officer confirms or
 *     changes them on the card), with the documents uploaded for it,
 *   - and that relationship itself once its BJMP requirements are complete.
 * Nothing is left to "verify" afterwards. Items only an officer can confirm
 * (e.g. "no immediate family" for a legal guardian) keep the relationship
 * pending, and the result says what is still needed.
 */
class AccountApproval
{
    /**
     * For the Account Review card's "Approve will…" checklist: per
     * relationship type, the documents and officer confirmations its
     * checklist asks for, plus what this visitor uploaded at registration.
     * A preview only; approve() decides with the real checklist.
     *
     * @return array{uploaded: array<string, string>, types: array<string, array<string, mixed>>}
     */
    public function preview(VisitorProfile $visitor): array
    {
        $isMinor = $visitor->date_of_birth?->age !== null && $visitor->date_of_birth->age < RelationshipRequirements::ADULT_AGE;
        $labels = fn (array $keys) => array_map(fn ($key) => [$key, RelationshipRequirements::DOCUMENTS[$key][0] ?? $key], array_values(array_unique($keys)));
        $withMinor = fn (array $keys) => $isMinor ? [...$keys, RelationshipRequirements::MINOR_DOCUMENT] : $keys;

        $types = [];
        foreach (VisitorPdlRelationship::RELATIONSHIP_TYPES as $type) {
            $rule = RelationshipRequirements::RELATIONSHIP_RULES[$type] ?? null;
            $confirmations = $rule['confirmations'] ?? [];
            if ($isMinor) {
                $confirmations[] = 'accompanying_guardian';
            }
            $types[$type] = [
                'blocked' => ! $rule,   // old generic types: pick the specific relationship
                'children' => isset($rule['children_together']),
                'docs' => $labels($withMinor(RelationshipRequirements::documentKeysFor($type, null))),
                'docsWithChildren' => $labels($withMinor(RelationshipRequirements::documentKeysFor($type, true))),
                'docsWithoutChildren' => $labels($withMinor(RelationshipRequirements::documentKeysFor($type, false))),
                'confirmations' => array_map(fn ($key) => RelationshipRequirements::CONFIRMATIONS[$key][0], $confirmations),
            ];
        }

        return [
            'uploaded' => $visitor->registrationDocuments
                ->where('status', '!=', 'rejected')
                ->mapWithKeys(fn ($doc) => [$doc->requirement_key => $doc->label()])
                ->all(),
            'types' => $types,
        ];
    }

    /**
     * @return array{notes: array<int, string>, relationship: ?VisitorPdlRelationship}
     */
    public function approve(VisitorProfile $visitor, int $staffId, ?int $pdlId, ?string $relationshipType, ?bool $hasChildrenTogether): array
    {
        $ids = $visitor->idDocuments()->get();
        if ($ids->whereIn('verification_status', ['pending', 'verified'])->isEmpty()) {
            throw ValidationException::withMessages([
                'id' => $ids->isEmpty()
                    ? 'This visitor has not uploaded a government ID yet.'
                    : 'Every ID this visitor uploaded was rejected. Wait for a new one before approving.',
            ]);
        }

        $relationship = $visitor->relationships()->where('verification_status', '!=', 'rejected')->first();
        if (! $relationship && ! $visitor->isWalkIn()) {
            if (! $pdlId || ! $relationshipType) {
                throw ValidationException::withMessages([
                    'pdl_id' => 'Choose the PDL this visitor will visit and the relationship before approving.',
                ]);
            }
            $pdl = Pdl::find($pdlId);
            if (! $pdl) {
                throw ValidationException::withMessages(['pdl_id' => 'Choose the PDL from the search results.']);
            }
        }

        return DB::transaction(function () use ($visitor, $staffId, $ids, $relationship, $pdlId, $relationshipType, $hasChildrenTogether) {
            $notes = [];

            // 1. IDs waiting for review: the officer checked them on the card.
            foreach ($ids->where('verification_status', 'pending') as $id) {
                $id->update(['verification_status' => 'verified', 'verified_by' => $staffId, 'verified_at' => now(), 'rejection_reason' => null]);
                AuditLog::record('update', 'visitor_ids', $id->visitor_id_doc_id,
                    "Approved {$id->typeLabel()} for {$visitor->full_name} (with the account)", Module::CODE_VISITOR_MANAGEMENT);
            }

            // 2. The account.
            $visitor->update(['verification_status' => 'verified', 'verified_by' => $staffId, 'verified_at' => now(), 'rejection_reason' => null]);

            // 3. The PDL link the visitor asked for. Creating it moves their
            //    registration uploads onto its checklist.
            if (! $relationship && $pdlId) {
                $relationship = VisitorPdlRelationship::create([
                    'visitor_id' => $visitor->visitor_id,
                    'pdl_id' => $pdlId,
                    'relationship_type' => $relationshipType,
                    'has_children_together' => $relationshipType === 'live_in_partner' ? $hasChildrenTogether : null,
                    'priority_tier' => VisitorPdlRelationship::priorityTierFor($relationshipType),
                    'verification_status' => 'pending',
                ]);
                AuditLog::record('create', 'visitor_pdl_relationships', $relationship->relationship_id,
                    "Linked {$visitor->full_name} as {$relationship->relationshipLabel()} to {$relationship->pdl->full_name} (with the account)",
                    Module::CODE_VISITOR_MANAGEMENT);
                $notes[] = "Linked to {$relationship->pdl->full_name} as {$relationship->relationshipLabel()}.";
            }

            // 4. Its documents and, once complete, the relationship itself.
            if ($relationship && $relationship->verification_status === 'pending') {
                RelationshipDocument::where('relationship_id', $relationship->relationship_id)
                    ->where('status', 'pending')
                    ->update(['status' => 'verified', 'rejection_reason' => null, 'reviewed_by' => $staffId, 'reviewed_at' => now()]);

                $requirements = RelationshipRequirements::for($relationship->fresh(['visitor.idDocuments', 'documents']));
                if (RelationshipRequirements::allMet($requirements)) {
                    $relationship->update(['verification_status' => 'verified', 'verified_by' => $staffId, 'verified_at' => now()]);
                    AuditLog::record('update', 'visitor_pdl_relationships', $relationship->relationship_id,
                        "Approved relationship {$visitor->full_name} → {$relationship->pdl->full_name} (with the account)",
                        Module::CODE_VISITOR_MANAGEMENT);
                    $notes[] = 'Relationship approved; the visitor can request visits.';
                } else {
                    $notes[] = 'Relationship still needs: ' . implode(', ', RelationshipRequirements::unmetLabels($requirements))
                        . '. Finish it on the visitor\'s page.';
                }
            }

            $visitor->unsetRelation('relationships');

            return ['notes' => $notes, 'relationship' => $relationship?->fresh()];
        });
    }
}
