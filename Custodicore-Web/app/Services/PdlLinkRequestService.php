<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Pdl;
use App\Models\PdlLinkRequest;
use App\Models\RelationshipDocument;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * An approved visitor's requests to add another PDL (Phase 5B).
 *
 * Stage 2 covers the visitor side: submit and cancel. Stage 3 the staff
 * side: link the request to the actual PDL, or reject it. A request's files are
 * relationship_documents rows with pdl_link_request_id set and no
 * relationship_id, stored on the private `local` disk like every other
 * requirement file. They are never registration documents
 * (RelationshipDocument::scopeRegistrationUploads).
 *
 * Document rules (later stages keep them):
 *   - linked: the request's documents move to the relationship (kept);
 *   - rejected or cancelled: the request's documents and stored files are deleted;
 *   - reopening a rejected relationship: its old rejected documents and files
 *     are deleted before the request's documents are attached.
 */
class PdlLinkRequestService
{
    /** Private disk, as for every relationship requirement file. */
    public const DISK = 'local';

    /** Same folder as registration and checklist uploads. */
    public const FOLDER = 'relationship-documents';

    /** A closed (rejected / cancelled) request stays visible in the app this long. */
    public const RECENT_DAYS = 30;

    /**
     * The documents a request must carry: the same files registration asks
     * for that relationship (the minor's birth certificate included), with
     * the live-in partner's child birth certificate added when they have
     * children together (no witness statement).
     * Facility-issued documents are never sent by the visitor.
     *
     * @return array<int, string>
     */
    public static function requiredDocumentKeys(string $relationshipType, ?bool $hasChildrenTogether, bool $isMinor): array
    {
        $keys = RelationshipRequirements::documentKeysFor($relationshipType, $hasChildrenTogether);
        if ($isMinor) {
            $keys[] = RelationshipRequirements::MINOR_DOCUMENT;
        }

        return array_values(array_filter(
            array_unique($keys),
            fn (string $key) => RelationshipRequirements::providedBy($key) === RelationshipRequirements::PROVIDED_BY_VISITOR
        ));
    }

    /**
     * Creates the open request and stores its documents in one transaction.
     * A file stored before a failure is removed again, so nothing is left behind.
     *
     * @param  array{pdlName: string, relationshipType: string, hasChildrenTogether: ?bool}  $data
     * @param  array<string, UploadedFile>  $files  requirement key => file (already validated)
     *
     * @throws OpenPdlRequestExists when the visitor already has an open request
     */
    public function submit(VisitorProfile $visitor, array $data, array $files): PdlLinkRequest
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($visitor, $data, $files, &$storedPaths) {
                // Lock the visitor row so two submissions are handled one after
                // the other; the unique open_visitor_id is the final guard.
                VisitorProfile::whereKey($visitor->visitor_id)->lockForUpdate()->first();
                if (PdlLinkRequest::open()->where('visitor_id', $visitor->visitor_id)->exists()) {
                    throw new OpenPdlRequestExists();
                }

                $request = PdlLinkRequest::create([
                    'visitor_id' => $visitor->visitor_id,
                    'pdl_name' => $data['pdlName'],
                    'relationship_type' => $data['relationshipType'],
                    'has_children_together' => $data['relationshipType'] === 'live_in_partner' ? $data['hasChildrenTogether'] : null,
                ]);

                foreach ($files as $key => $file) {
                    $path = $file->store(self::FOLDER, self::DISK);
                    if (! $path) {
                        throw new \RuntimeException("Could not store the {$key} file.");
                    }
                    $storedPaths[] = $path;

                    RelationshipDocument::create([
                        'relationship_id' => null,
                        'visitor_id' => $visitor->visitor_id,
                        'pdl_link_request_id' => $request->request_id,
                        'requirement_key' => $key,
                        'file_path' => $path,
                        'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                        'status' => 'pending',
                        'uploaded_at' => now(),
                    ]);
                }

                AuditLog::record('create', 'pdl_link_requests', $request->request_id,
                    "Visitor requested to add PDL \"{$request->pdl_name}\" as {$request->relationshipLabel()} via mobile app ("
                    . count($files) . ' document' . (count($files) === 1 ? '' : 's') . ')',
                    Module::CODE_VISITOR_MANAGEMENT);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            // Another submission won the race for the single open slot.
            $this->deleteFiles($storedPaths, 'submit rollback');
            throw new OpenPdlRequestExists();
        } catch (\Throwable $e) {
            $this->deleteFiles($storedPaths, 'submit rollback');
            throw $e;
        }
    }

    /**
     * Cancels the visitor's own open request: frees the open slot and deletes
     * that request's documents (rows, then their stored files once the
     * database change is committed). Nothing else is touched.
     *
     * @throws PdlRequestNotOpen when the request is no longer open
     */
    public function cancel(VisitorProfile $visitor, PdlLinkRequest $request): PdlLinkRequest
    {
        $paths = DB::transaction(function () use ($visitor, $request) {
            $locked = PdlLinkRequest::whereKey($request->request_id)
                ->where('visitor_id', $visitor->visitor_id)
                ->lockForUpdate()
                ->first();
            if (! $locked || ! $locked->isOpen()) {
                throw new PdlRequestNotOpen($locked?->status);
            }

            // Only this request's own, never-linked files.
            $documents = RelationshipDocument::where('pdl_link_request_id', $locked->request_id)
                ->whereNull('relationship_id')
                ->get(['document_id', 'file_path']);
            RelationshipDocument::whereIn('document_id', $documents->pluck('document_id'))->delete();

            $locked->update(PdlLinkRequest::closingAttributes(PdlLinkRequest::STATUS_CANCELLED));

            AuditLog::record('update', 'pdl_link_requests', $locked->request_id,
                "Visitor cancelled the request to add PDL \"{$locked->pdl_name}\" ({$documents->count()} document"
                . ($documents->count() === 1 ? '' : 's') . ' deleted)',
                Module::CODE_VISITOR_MANAGEMENT);

            return $documents->pluck('file_path')->filter()->all();
        });

        $this->deleteFiles($paths, "cancel request #{$request->request_id}");

        return $request->fresh();
    }

    /**
     * Stage 3: staff matched the request to the actual PDL. In one
     * transaction, with the request row (and an existing relationship row)
     * locked so a visitor cancellation or a second officer cannot interleave:
     *
     *   - no relationship with that PDL yet → a new pending relationship;
     *   - a pending one                     → reused;
     *   - a rejected one                    → reopened as pending, after its
     *                                         old rejected documents are deleted;
     *   - an approved one                   → refused (already linked).
     *
     * Only this request's own files move to the relationship, keeping
     * pdl_link_request_id as their origin, still pending review. The
     * relationship is never approved here: staff review it on the visitor
     * page as before. Relationship rows are saved quietly so the "attach
     * registration uploads" hook cannot pull unrelated files in.
     *
     * @return array{relationship: VisitorPdlRelationship, outcome: 'created'|'reused'|'reopened'}
     *
     * @throws PdlRequestNotOpen when the request is no longer open
     * @throws \DomainException  when the link is refused (message is for staff)
     */
    public function link(PdlLinkRequest $request, int $pdlId, string $relationshipType, ?bool $hasChildrenTogether, int $staffId): array
    {
        $hasChildrenTogether = $relationshipType === 'live_in_partner' ? $hasChildrenTogether : null;

        try {
            [$relationship, $outcome, $oldPaths] = DB::transaction(function () use ($request, $pdlId, $relationshipType, $hasChildrenTogether, $staffId) {
                $locked = PdlLinkRequest::whereKey($request->request_id)->lockForUpdate()->first();
                if (! $locked || ! $locked->isOpen()) {
                    throw new PdlRequestNotOpen($locked?->status);
                }

                $visitor = VisitorProfile::find($locked->visitor_id);
                if (! $visitor || $visitor->verification_status !== 'verified') {
                    throw new \DomainException('This visitor\'s account is not approved right now, so the PDL cannot be linked. Reject the request or wait until the account is approved again.');
                }
                $pdl = Pdl::find($pdlId);
                if (! $pdl) {
                    throw new \DomainException('The selected PDL was not found.');
                }

                $details = [
                    'relationship_type' => $relationshipType,
                    'priority_tier' => VisitorPdlRelationship::priorityTierFor($relationshipType),
                    'has_children_together' => $hasChildrenTogether,
                ];
                $oldPaths = [];

                $relationship = VisitorPdlRelationship::where('visitor_id', $visitor->visitor_id)
                    ->where('pdl_id', $pdl->pdl_id)
                    ->lockForUpdate()
                    ->first();

                if ($relationship?->verification_status === 'verified') {
                    throw new \DomainException("{$visitor->full_name} is already linked to {$pdl->full_name} through an approved relationship. Reject this request instead.");
                }

                if (! $relationship) {
                    $outcome = 'created';
                    $relationship = new VisitorPdlRelationship($details + [
                        'visitor_id' => $visitor->visitor_id,
                        'pdl_id' => $pdl->pdl_id,
                        'verification_status' => 'pending',
                    ]);
                    $relationship->saveQuietly();
                } elseif ($relationship->verification_status === 'rejected') {
                    $outcome = 'reopened';
                    // Only the relationship's rejected documents go; approved
                    // (and any other) documents stay. The request's documents
                    // are added on top for review.
                    $old = RelationshipDocument::where('relationship_id', $relationship->relationship_id)
                        ->where('status', 'rejected')
                        ->get(['document_id', 'file_path']);
                    RelationshipDocument::whereIn('document_id', $old->pluck('document_id'))->delete();
                    $oldPaths = $old->pluck('file_path')->filter()->all();

                    $relationship->fill($details + [
                        'verification_status' => 'pending',
                        'verified_by' => null,
                        'verified_at' => null,
                        // Confirmations belonged to the rejected review.
                        'officer_confirmations' => null,
                    ])->saveQuietly();
                } else {
                    $outcome = 'reused';
                    $relationship->fill($details)->saveQuietly();
                }

                // Only this request's own, not-yet-linked files.
                $moved = RelationshipDocument::where('pdl_link_request_id', $locked->request_id)
                    ->where('visitor_id', $visitor->visitor_id)
                    ->whereNull('relationship_id')
                    ->update(['relationship_id' => $relationship->relationship_id]);

                $locked->update(PdlLinkRequest::closingAttributes(PdlLinkRequest::STATUS_LINKED) + [
                    'relationship_id' => $relationship->relationship_id,
                    'reviewed_by' => $staffId,
                    'reviewed_at' => now(),
                ]);

                $action = ['created' => 'new pending relationship', 'reused' => 'existing pending relationship', 'reopened' => 'reopened rejected relationship'][$outcome];
                AuditLog::record('update', 'pdl_link_requests', $locked->request_id,
                    "Linked PDL request \"{$locked->pdl_name}\" from {$visitor->full_name} to PDL #{$pdl->pdl_id} as {$relationship->relationshipLabel()} "
                    . "({$action} #{$relationship->relationship_id}; {$moved} document" . ($moved === 1 ? '' : 's') . ' moved'
                    . ($outcome === 'reopened' ? ', ' . count($oldPaths) . ' rejected document' . (count($oldPaths) === 1 ? '' : 's') . ' deleted' : '') . ')',
                    Module::CODE_VISITOR_MANAGEMENT);

                return [$relationship, $outcome, $oldPaths];
            });
        } catch (UniqueConstraintViolationException) {
            // Someone added this visitor↔PDL relationship at the same moment.
            throw new \DomainException('A relationship with this PDL was just added by someone else. Open the request again and retry.');
        }

        $this->deleteFiles($oldPaths, "reopen relationship #{$relationship->relationship_id}");

        return ['relationship' => $relationship, 'outcome' => $outcome];
    }

    /**
     * Stage 3: staff reject an open request. Frees the open slot, records the
     * reason and reviewer, and deletes the request's documents (rows, then
     * their stored files once committed).
     *
     * @throws PdlRequestNotOpen when the request is no longer open
     */
    public function reject(PdlLinkRequest $request, string $reason, int $staffId): PdlLinkRequest
    {
        $paths = DB::transaction(function () use ($request, $reason, $staffId) {
            $locked = PdlLinkRequest::whereKey($request->request_id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isOpen()) {
                throw new PdlRequestNotOpen($locked?->status);
            }

            $documents = RelationshipDocument::where('pdl_link_request_id', $locked->request_id)
                ->whereNull('relationship_id')
                ->get(['document_id', 'file_path']);
            RelationshipDocument::whereIn('document_id', $documents->pluck('document_id'))->delete();

            $locked->update(PdlLinkRequest::closingAttributes(PdlLinkRequest::STATUS_REJECTED) + [
                'rejection_reason' => $reason,
                'reviewed_by' => $staffId,
                'reviewed_at' => now(),
            ]);

            AuditLog::record('update', 'pdl_link_requests', $locked->request_id,
                "Rejected the request to add PDL \"{$locked->pdl_name}\": {$reason} ({$documents->count()} document"
                . ($documents->count() === 1 ? '' : 's') . ' deleted)',
                Module::CODE_VISITOR_MANAGEMENT);

            return $documents->pluck('file_path')->filter()->all();
        });

        $this->deleteFiles($paths, "reject request #{$request->request_id}");

        return $request->fresh();
    }

    /**
     * The request the app shows: the open one, else the latest rejected or
     * cancelled one from the last RECENT_DAYS days. A linked request is not
     * shown (its relationship is listed under My PDLs instead).
     */
    public function currentForVisitor(VisitorProfile $visitor): ?PdlLinkRequest
    {
        $latest = PdlLinkRequest::where('visitor_id', $visitor->visitor_id)
            ->orderByDesc('created_at')
            ->orderByDesc('request_id')
            ->with('documents')
            ->first();

        if (! $latest) {
            return null;
        }
        if ($latest->isOpen()) {
            return $latest;
        }
        $closedRecently = in_array($latest->status, [PdlLinkRequest::STATUS_REJECTED, PdlLinkRequest::STATUS_CANCELLED], true)
            && $latest->updated_at?->gte(now()->subDays(self::RECENT_DAYS));

        return $closedRecently ? $latest : null;
    }

    /**
     * Deletes stored files. A failure is logged (paths only, no personal
     * data) and never undoes the database change that already happened.
     *
     * @param  array<int, string>  $paths
     */
    private function deleteFiles(array $paths, string $context): void
    {
        foreach ($paths as $path) {
            try {
                if (Storage::disk(self::DISK)->exists($path)) {
                    Storage::disk(self::DISK)->delete($path);
                }
            } catch (\Throwable $e) {
                Log::warning("PDL request file could not be deleted ({$context}): {$path} (" . $e::class . ')');
            }
        }
    }
}
