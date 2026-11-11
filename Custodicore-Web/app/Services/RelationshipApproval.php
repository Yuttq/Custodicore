<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\RelationshipDocument;
use App\Models\VisitorPdlRelationship;
use Illuminate\Support\Facades\DB;

/**
 * One Approve for a visitor↔PDL relationship: the officer has looked at its
 * documents, so approving the relationship also approves every document
 * still waiting for review and records the officer confirmations ("no
 * immediate family"; the adult accompanying a minor, taken from the guardian
 * named at registration) — the Approve pop-up states them — then approves
 * the relationship. No separate Approve, Confirm or tick box per item.
 * Anything else still missing (a file not uploaded, a detail) blocks it and
 * nothing changes.
 */
class RelationshipApproval
{
    /** Counts as done on Approve: met, a file waiting for review, or a confirmation ticked on the form. */
    private static function coveredByApprove(array $item): bool
    {
        return $item['status'] === 'met'
            || ($item['kind'] === 'document' && $item['status'] === 'pending_review')
            || ($item['kind'] === 'confirmation' && $item['status'] !== 'met');
    }

    /** Approve can be used: everything left is covered by the Approve form. */
    public static function ready(array $items): bool
    {
        return collect($items)->every(fn ($item) => self::coveredByApprove($item));
    }

    /** What still blocks Approve (not counting files under review or confirmations on the form). */
    public static function blockers(array $items): array
    {
        return collect($items)->reject(fn ($item) => self::coveredByApprove($item))->pluck('label')->values()->all();
    }

    /** Officer confirmations the Approve form must ask for: key => label. */
    public static function confirmationsNeeded(array $items): array
    {
        return collect($items)
            ->where('kind', 'confirmation')
            ->where('status', '!=', 'met')
            ->mapWithKeys(fn ($item) => [$item['key'] => $item['label']])
            ->all();
    }

    /** The adult accompanying a minor: entered on the form, else the guardian named at registration. */
    public static function companion(VisitorPdlRelationship $relationship, ?string $entered = null): ?string
    {
        $name = trim((string) ($entered ?: $relationship->visitor?->guardian_full_name));

        return $name === '' ? null : $name;
    }

    /**
     * @param  ?string  $accompanyingAdult  only needed when no guardian was named at registration
     * @return array{approved: bool, missing: array<int, string>, documents: int}
     */
    public function approve(VisitorPdlRelationship $relationship, int $staffId, ?string $accompanyingAdult = null): array
    {
        $relationship->loadMissing(['visitor.idDocuments', 'documents']);
        $items = RelationshipRequirements::for($relationship);

        $missing = self::blockers($items);
        $companion = self::companion($relationship, $accompanyingAdult);
        if (array_key_exists('accompanying_guardian', self::confirmationsNeeded($items)) && $companion === null) {
            $missing[] = 'Name of the parent or guardian accompanying the minor';
        }
        if ($missing !== []) {
            return ['approved' => false, 'missing' => $missing, 'documents' => 0];
        }

        return DB::transaction(function () use ($relationship, $staffId, $items, $companion) {
            // Approve is the officer's confirmation; saved like the visitor page used to (by, at, note).
            $saved = $relationship->officer_confirmations ?? [];
            foreach (self::confirmationsNeeded($items) as $key => $label) {
                $note = $key === 'accompanying_guardian' ? $companion : null;
                $saved[$key] = ['by' => $staffId, 'at' => now()->toIso8601String(), 'note' => $note];
                AuditLog::record('update', 'visitor_pdl_relationships', $relationship->relationship_id,
                    "Confirmed '{$label}' for {$relationship->visitor?->full_name}" . ($note ? " ({$note})" : '') . ' (with the relationship)',
                    Module::CODE_VISITOR_MANAGEMENT);
            }

            $documents = RelationshipDocument::where('relationship_id', $relationship->relationship_id)
                ->where('status', 'pending')
                ->update(['status' => 'verified', 'rejection_reason' => null, 'reviewed_by' => $staffId, 'reviewed_at' => now()]);

            $relationship->update([
                'officer_confirmations' => $saved,
                'verification_status' => 'verified',
                'verified_by' => $staffId,
                'verified_at' => now(),
            ]);

            return ['approved' => true, 'missing' => [], 'documents' => $documents];
        });
    }
}
