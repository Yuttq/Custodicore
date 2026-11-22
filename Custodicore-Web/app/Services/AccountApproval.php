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
 *   - the officer confirmations ticked on the card ("no immediate family"
 *     for a legal guardian, the accompanying adult for a minor), saved in
 *     officer_confirmations the same way the visitor page saves them,
 *   - and that relationship itself.
 * Nothing is left to "verify" afterwards. If any BJMP requirement of the
 * relationship is still incomplete, the whole approval is refused and rolled
 * back: the ID, account, link and documents stay as they were.
 *
 * Walk-ins keep their own flow: the PDL is linked from the Front Desk details
 * after approval (WalkInService::autoLink), so an incomplete relationship
 * only adds a note for them, as before.
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

        $confirmationOf = fn (string $key) => [$key, RelationshipRequirements::CONFIRMATIONS[$key][0]];

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
                'confirmations' => array_map($confirmationOf, $confirmations),
            ];
        }

        // Checklist blockers that apply whatever relationship is picked (same wording as RelationshipRequirements).
        $age = $visitor->date_of_birth?->age;
        $blockers = $age !== null && $age < RelationshipRequirements::MINIMUM_AGE
            ? ['Visitor is under ' . RelationshipRequirements::MINIMUM_AGE . ' years old']
            : [];

        return [
            'blockers' => $blockers,
            'uploaded' => $visitor->registrationDocuments
                ->where('status', '!=', 'rejected')
                ->mapWithKeys(fn ($doc) => [$doc->requirement_key => $doc->label()])
                ->all(),
            'types' => $types,
        ];
    }

    /**
     * For the Account Review card when the visitor already has a pending PDL
     * link: its real checklist, as approve() will see it. Items waiting for
     * review are approved by Approve, confirmations can be ticked on the
     * card, and anything else blocks the approval.
     *
     * @return array<int, array{key: string, kind: string, label: string, state: string}>  state: ok|confirm|blocked
     */
    public function linkedPreview(VisitorPdlRelationship $relationship): array
    {
        return collect(RelationshipRequirements::for($relationship))->map(fn (array $item) => [
            'key' => $item['key'],
            'kind' => $item['kind'],
            'label' => $item['label'],
            'state' => match (true) {
                in_array($item['status'], ['met', 'pending_review'], true) => 'ok',
                $item['kind'] === 'confirmation' => 'confirm',
                default => 'blocked',
            },
        ])->values()->all();
    }

    /**
     * @param  array<int, string>  $confirmations  officer confirmation keys ticked on the card
     * @param  ?string  $accompanyingAdult  name of the adult accompanying a minor visitor
     * @return array{notes: array<int, string>, relationship: ?VisitorPdlRelationship}
     */
    public function approve(
        VisitorProfile $visitor,
        int $staffId,
        ?int $pdlId,
        ?string $relationshipType,
        ?bool $hasChildrenTogether,
        array $confirmations = [],
        ?string $accompanyingAdult = null,
    ): array {
        $ids = $visitor->idDocuments()->get();
        if ($ids->whereIn('verification_status', ['pending', 'verified'])->isEmpty()) {
            throw ValidationException::withMessages([
                'id' => $ids->isEmpty()
                    ? 'This visitor has not uploaded a government ID yet.'
                    : 'Every ID this visitor uploaded was rejected. Wait for a new one before approving.',
            ]);
        }
        // Rechecked under a row lock inside the transaction below.
        $this->assertNothingToCorrect($visitor);

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
            if (isset(RelationshipRequirements::RELATIONSHIP_RULES[$relationshipType]['children_together']) && $hasChildrenTogether === null) {
                throw ValidationException::withMessages([
                    'has_children_together' => 'Choose whether the visitor and the PDL have children together.',
                ]);
            }
        }

        $accompanyingAdult = trim((string) $accompanyingAdult) ?: null;

        // A refusal below throws inside the transaction, so everything rolls back.
        return DB::transaction(function () use ($visitor, $staffId, $ids, $relationship, $pdlId, $relationshipType, $hasChildrenTogether, $confirmations, $accompanyingAdult) {
            $notes = [];

            // 0. Lock the visitor row (the same lock a photo rejection or a
            //    duplicate set-aside takes) and recheck: an officer may have
            //    rejected an item after the check above. Throws (rolls back) if so.
            $locked = VisitorProfile::whereKey($visitor->visitor_id)->lockForUpdate()->first();
            $this->assertNothingToCorrect($locked);

            // 1. IDs waiting for review: the officer checked them on the card.
            //    Only IDs still pending: rejected and set-aside IDs are never changed.
            //    Two or more waiting: the newest is approved, older duplicates are set aside.
            $this->setAsideOlderPendingIds($visitor, $staffId);
            foreach ($visitor->idDocuments()->where('verification_status', 'pending')->get() as $id) {
                $updated = \App\Models\VisitorId::whereKey($id->visitor_id_doc_id)->where('verification_status', 'pending')
                    ->update(['verification_status' => 'verified', 'verified_by' => $staffId, 'verified_at' => now(), 'rejection_reason' => null]);
                if ($updated) {
                    AuditLog::record('update', 'visitor_ids', $id->visitor_id_doc_id,
                        "Approved {$id->typeLabel()} for {$visitor->full_name} (with the account)", Module::CODE_VISITOR_MANAGEMENT);
                }
            }

            // 2. The account, and its photo if still waiting (the officer saw it on the card).
            $visitor->update(['verification_status' => 'verified', 'verified_by' => $staffId, 'verified_at' => now(), 'rejection_reason' => null]);
            $visitor->verifyPendingPhoto($staffId, "Approved photo for {$visitor->full_name} (with the account)");

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

            // 4. Its documents, the officer confirmations and the relationship itself.
            if ($relationship && $relationship->verification_status === 'pending') {
                RelationshipDocument::where('relationship_id', $relationship->relationship_id)
                    ->where('status', 'pending')
                    ->update(['status' => 'verified', 'rejection_reason' => null, 'reviewed_by' => $staffId, 'reviewed_at' => now()]);

                if (! $visitor->isWalkIn()) {
                    $this->saveConfirmations($visitor, $relationship, $staffId, $confirmations, $accompanyingAdult);
                }

                $requirements = RelationshipRequirements::for($relationship->fresh(['visitor.idDocuments', 'documents']));
                if (! $visitor->isWalkIn() && ! RelationshipRequirements::allMet($requirements)) {
                    throw ValidationException::withMessages([
                        'relationship' => 'The relationship still needs: ' . implode(', ', RelationshipRequirements::unmetLabels($requirements))
                            . '. Nothing was approved.',
                    ]);
                }
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
            } elseif ($relationship && $relationship->verification_status === 'verified') {
                // Already verified link: its files still waiting are approved with the account.
                $verified = RelationshipDocument::where('relationship_id', $relationship->relationship_id)
                    ->where('status', 'pending')
                    ->update(['status' => 'verified', 'rejection_reason' => null, 'reviewed_by' => $staffId, 'reviewed_at' => now()]);
                if ($verified) {
                    AuditLog::record('update', 'visitor_pdl_relationships', $relationship->relationship_id,
                        "Approved {$verified} waiting file(s) of {$visitor->full_name}'s relationship (with the account)",
                        Module::CODE_VISITOR_MANAGEMENT);
                }
            }

            // 5. Files sent with the registration that are on no checklist: the
            //    officer saw them on the card. Only files still pending.
            $registrationFiles = RelationshipDocument::registrationUploads()
                ->where('visitor_id', $visitor->visitor_id)
                ->where('status', 'pending')
                ->get();
            foreach ($registrationFiles as $document) {
                $updated = RelationshipDocument::whereKey($document->document_id)->where('status', 'pending')
                    ->update(['status' => 'verified', 'rejection_reason' => null, 'reviewed_by' => $staffId, 'reviewed_at' => now()]);
                if ($updated) {
                    AuditLog::record('update', 'relationship_documents', $document->document_id,
                        "Approved {$document->label()} sent with the registration of {$visitor->full_name} (with the account)",
                        Module::CODE_VISITOR_MANAGEMENT);
                }
            }

            $visitor->unsetRelation('relationships');

            return ['notes' => $notes, 'relationship' => $relationship?->fresh()];
        });
    }

    /**
     * Refuses the final Approve while anything needs correcting: an item
     * staff rejected (photo, government ID, a file) that the visitor has not
     * replaced yet. Two or more IDs waiting no longer block it: Approve keeps
     * the newest and sets the older ones aside (setAsideOlderPendingIds).
     * Read from the database, not cached relations.
     *
     * @throws ValidationException
     */
    public function assertNothingToCorrect(VisitorProfile $visitor): void
    {
        $needs = $visitor->itemReviewState()['needsReplacement'];
        if ($needs !== []) {
            throw ValidationException::withMessages([
                'items' => 'Waiting for the visitor to replace: ' . implode(', ', $needs) . '. Nothing was approved.',
            ]);
        }
    }

    /**
     * Two or more government IDs waiting: keeps the newest for approval and
     * sets the older ones aside (kept with their files, never approved; the
     * visitor is not notified), in place of the old "Set aside duplicate"
     * button. Call inside the approval transaction, visitor row locked.
     */
    public function setAsideOlderPendingIds(VisitorProfile $visitor, int $staffId): void
    {
        $pending = \App\Models\VisitorId::where('visitor_id', $visitor->visitor_id)->where('verification_status', 'pending')
            ->orderByDesc('uploaded_at')->orderByDesc('visitor_id_doc_id')->get();

        foreach ($pending->slice(1) as $older) {
            $updated = \App\Models\VisitorId::whereKey($older->visitor_id_doc_id)->where('verification_status', 'pending')
                ->update([
                    'verification_status' => \App\Models\VisitorId::STATUS_SUPERSEDED,
                    'verified_by' => $staffId,
                    'verified_at' => now(),
                    'rejection_reason' => 'Older duplicate: set aside when the newer ID was approved',
                ]);
            if ($updated) {
                AuditLog::record('update', 'visitor_ids', $older->visitor_id_doc_id,
                    "Set aside older duplicate {$older->typeLabel()} of {$visitor->full_name} (newer ID approved)", Module::CODE_VISITOR_MANAGEMENT);
            }
        }
    }

    /**
     * Saves the confirmations ticked on the card that the relationship's
     * checklist asks for and does not have yet, in the same shape as the
     * visitor page (RelationshipRequirementController::confirm).
     */
    private function saveConfirmations(VisitorProfile $visitor, VisitorPdlRelationship $relationship, int $staffId, array $confirmations, ?string $accompanyingAdult): void
    {
        $wanted = collect(RelationshipRequirements::for($relationship->fresh(['visitor.idDocuments', 'documents'])))
            ->where('kind', 'confirmation')
            ->where('status', '!=', 'met')
            ->pluck('key')
            ->intersect($confirmations)
            ->values();
        if ($wanted->isEmpty()) {
            return;
        }

        if ($wanted->contains('accompanying_guardian') && $accompanyingAdult === null) {
            throw ValidationException::withMessages([
                'accompanying_adult' => 'Enter the name of the parent or guardian who will accompany the minor.',
            ]);
        }

        $saved = $relationship->officer_confirmations ?? [];
        foreach ($wanted as $key) {
            $note = $key === 'accompanying_guardian' ? $accompanyingAdult : null;
            $saved[$key] = ['by' => $staffId, 'at' => now()->toIso8601String(), 'note' => $note];

            AuditLog::record('update', 'visitor_pdl_relationships', $relationship->relationship_id,
                "Confirmed '" . RelationshipRequirements::CONFIRMATIONS[$key][0] . "' for {$visitor->full_name}"
                    . ($note ? " ({$note})" : '') . ' (with the account)',
                Module::CODE_VISITOR_MANAGEMENT);
        }
        $relationship->update(['officer_confirmations' => $saved]);
    }
}
