<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use Illuminate\Support\Collection;

/**
 * What a returned visitor has to redo. The app only lets a visitor replace
 * an item staff rejected (VisitorProfile::canUploadGovernmentId,
 * canChangePhoto, the document upload rules), so returning the account alone
 * would leave a blurry ID locked. Return therefore lists the items it can
 * mark for redoing (items()) and rejects the ticked ones together with the
 * return (rejectForReturn()), with the return's reason. The visitor gets the
 * one "Account Returned" notification, not one per item.
 *
 * Item keys: "photo", "id:{visitor_id_doc_id}", "doc:{document_id}".
 */
class ReturnForCorrection
{
    /**
     * Items the officer can tick on the Return form: the photo, the current
     * government ID and the newest file per requirement, each only while it
     * is waiting for review or approved (a rejected one is already unlocked).
     *
     * @return Collection<int, array{key: string, label: string}>
     */
    public static function items(VisitorProfile $visitor): Collection
    {
        if ($visitor->isWalkIn()) {
            return collect(); // corrected at the Front Desk
        }

        $items = collect();

        if ($visitor->photo_path && in_array($visitor->photo_status, [VisitorProfile::PHOTO_PENDING, VisitorProfile::PHOTO_VERIFIED], true)) {
            $items->push(['key' => 'photo', 'label' => 'Photo']);
        }

        $id = $visitor->currentGovernmentId();
        if ($id && in_array($id->verification_status, ['pending', 'verified'], true)) {
            $items->push(['key' => 'id:' . $id->visitor_id_doc_id, 'label' => $id->typeLabel()]);
        }

        foreach (self::currentDocuments($visitor) as $doc) {
            $items->push(['key' => 'doc:' . $doc->document_id, 'label' => $doc->label()]);
        }

        return $items;
    }

    /**
     * Rejects the ticked items with the return's reason. Call inside the
     * Return transaction with the visitor row locked. Keys that are no longer
     * offered (changed meanwhile, or not this visitor's) are ignored.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string> labels of the items marked for redoing
     */
    public static function rejectForReturn(VisitorProfile $visitor, array $keys, string $reason, int $staffId): array
    {
        $offered = self::items($visitor)->keyBy('key');
        $done = [];

        foreach (array_unique($keys) as $key) {
            $item = $offered->get($key);
            if (! $item) {
                continue;
            }

            [$kind, $id] = array_pad(explode(':', $key, 2), 2, null);
            $updated = match ($kind) {
                'photo' => VisitorProfile::whereKey($visitor->visitor_id)
                    ->whereIn('photo_status', [VisitorProfile::PHOTO_PENDING, VisitorProfile::PHOTO_VERIFIED])
                    ->update([
                        'photo_status' => VisitorProfile::PHOTO_REJECTED,
                        'photo_rejection_reason' => $reason,
                        'photo_reviewed_by' => $staffId,
                        'photo_reviewed_at' => now(),
                    ]),
                'id' => VisitorId::whereKey((int) $id)->where('visitor_id', $visitor->visitor_id)
                    ->whereIn('verification_status', ['pending', 'verified'])
                    ->update([
                        'verification_status' => 'rejected',
                        'verified_by' => $staffId,
                        'verified_at' => now(),
                        'rejection_reason' => $reason,
                    ]),
                'doc' => RelationshipDocument::whereKey((int) $id)->where('visitor_id', $visitor->visitor_id)
                    ->whereIn('status', ['pending', 'verified'])
                    ->update([
                        'status' => 'rejected',
                        'rejection_reason' => $reason,
                        'reviewed_by' => $staffId,
                        'reviewed_at' => now(),
                    ]),
                default => 0,
            };

            if ($updated) {
                $done[] = $item['label'];
                [$type, $recordId] = match ($kind) {
                    'photo' => ['visitor_profiles', $visitor->visitor_id],
                    'id' => ['visitor_ids', (int) $id],
                    default => ['relationship_documents', (int) $id],
                };
                AuditLog::record('update', $type, $recordId,
                    "Marked {$item['label']} of {$visitor->full_name} to redo, with the account return: {$reason}",
                    Module::CODE_VISITOR_MANAGEMENT);
            }
        }

        return $done;
    }

    /**
     * The visitor's newest file per requirement that can be marked for
     * redoing: sent with the registration (no PDL yet) or on a relationship
     * still under review. PDL-link request files are decided with that request.
     */
    private static function currentDocuments(VisitorProfile $visitor): Collection
    {
        return RelationshipDocument::where('visitor_id', $visitor->visitor_id)
            ->whereNull('pdl_link_request_id')
            ->with('relationship')
            ->orderByDesc('document_id')
            ->get()
            ->filter(fn ($d) => $d->relationship_id === null || $d->relationship?->verification_status === 'pending')
            ->unique(fn ($d) => ($d->relationship_id ?? 'registration') . '|' . $d->requirement_key)
            ->filter(fn ($d) => in_array($d->status, ['pending', 'verified'], true))
            ->values();
    }
}
