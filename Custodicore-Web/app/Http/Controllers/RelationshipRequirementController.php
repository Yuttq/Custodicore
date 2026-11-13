<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\RelationshipDocument;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Services\RelationshipRequirements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Record Officer side of the BJMP visitor requirements: open, verify and
 * reject the documents visitors upload from the mobile app, record officer
 * confirmations, and set the relationship details the checklist depends on.
 * The checklist itself is App\Services\RelationshipRequirements.
 */
class RelationshipRequirementController extends Controller
{
    use Concerns\ResolvesActingStaff;

    private function logAudit(string $recordType, int $recordId, string $description): void
    {
        try {
            AuditLog::record('update', $recordType, $recordId, $description, \App\Models\Module::CODE_VISITOR_MANAGEMENT);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed: ' . $e->getMessage());
        }
    }

    /** Documents can't change once the relationship itself is verified. */
    private function abortIfLocked(?VisitorPdlRelationship $relationship)
    {
        // A registration upload is reviewed on the checklist once staff link the PDL.
        if ($relationship === null) {
            return back()->with('error', 'Link this visitor to the PDL first. The document is then reviewed on that relationship\'s checklist.');
        }
        if ($relationship->verification_status === 'verified') {
            return back()->with('error', 'This relationship is already verified, so its requirements are locked.');
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Files (private disk — never publicly reachable)
    // -----------------------------------------------------------------
    public function file(RelationshipDocument $document)
    {
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->response($document->file_path, $document->original_name);
    }

    /** The single file the older upload endpoint stored on the relationship. */
    public function legacyFile(VisitorProfile $visitor, VisitorPdlRelationship $relationship)
    {
        abort_unless($relationship->visitor_id === $visitor->visitor_id, 404);
        abort_unless($relationship->supporting_document_path && Storage::disk('local')->exists($relationship->supporting_document_path), 404);

        return Storage::disk('local')->response($relationship->supporting_document_path);
    }

    // -----------------------------------------------------------------
    // Review an uploaded requirement document
    // -----------------------------------------------------------------
    public function verifyDocument(RelationshipDocument $document)
    {
        if ($locked = $this->abortIfLocked($document->relationship)) {
            return $locked;
        }
        if ($document->status !== 'pending') {
            return back()->with('error', 'This document has already been reviewed.');
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $document->update([
            'status' => 'verified',
            'rejection_reason' => null,
            'reviewed_by' => $staffId,
            'reviewed_at' => now(),
        ]);

        $label = RelationshipRequirements::DOCUMENTS[$document->requirement_key][0] ?? $document->requirement_key;
        $this->logAudit('relationship_documents', $document->document_id, "Verified {$label} for relationship #{$document->relationship_id}");

        return back()->with('success', "{$label} approved.");
    }

    public function rejectDocument(Request $request, RelationshipDocument $document)
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'rejection_reason.min' => 'Give a reason of at least 5 characters so the visitor knows what to fix.',
            'rejection_reason.required' => 'Tell the visitor why this document was rejected.',
        ]);

        // A file sent with an app registration of a visitor not approved yet can
        // be rejected before the PDL is linked, so the visitor can replace it
        // from the app. It is still approved only with the account (AccountApproval).
        // Walk-ins keep their own document review (at the Front Desk, WalkInService::approveAtDesk).
        $isRegistrationUpload = $document->relationship_id === null
            && $document->pdl_link_request_id === null
            && $document->visitor
            && ! $document->visitor->isWalkIn()
            && $document->visitor->verification_status !== 'verified';

        if (! $isRegistrationUpload && ($locked = $this->abortIfLocked($document->relationship))) {
            return $locked;
        }
        if ($document->status !== 'pending') {
            return back()->with('error', 'This document has already been reviewed.');
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $reason = trim($validated['rejection_reason']);

        // Conditional update: a second click (or a second officer) cannot
        // reject the same file twice, so only one notification goes out.
        $rejected = RelationshipDocument::whereKey($document->document_id)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by' => $staffId,
                'reviewed_at' => now(),
            ]);
        if ($rejected === 0) {
            return back()->with('error', 'This document has already been reviewed.');
        }

        $label = $document->label();
        $this->logAudit('relationship_documents', $document->document_id, $isRegistrationUpload
            ? "Rejected {$label} sent with the registration of visitor #{$document->visitor_id}: {$reason}"
            : "Rejected {$label} for relationship #{$document->relationship_id}: {$reason}");

        if ($document->visitor) {
            \App\Support\DocumentRejectionNotice::send($document->visitor, $label, $reason,
                'relationship_documents', $document->document_id);
        }

        return back()->with('success', "{$label} rejected. The visitor can upload a new one from the app.");
    }

    // -----------------------------------------------------------------
    // Return a pending relationship: one decision instead of a Reject per
    // file. The files under review are marked for replacement (so the app
    // asks the visitor for new ones), the link stays pending, and the
    // visitor gets one notice with what to fix.
    // -----------------------------------------------------------------
    public function returnRelationship(Request $request, VisitorProfile $visitor, VisitorPdlRelationship $relationship)
    {
        abort_unless($relationship->visitor_id === $visitor->visitor_id, 404);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'rejection_reason.required' => 'Tell the visitor what to fix.',
            'rejection_reason.min' => 'Give a reason of at least 5 characters so the visitor knows what to fix.',
        ]);

        if ($relationship->verification_status !== 'pending') {
            return back()->with('error', 'Only a pending relationship can be returned.');
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $reason = trim($validated['rejection_reason']);
        $returned = RelationshipDocument::where('relationship_id', $relationship->relationship_id)
            ->where('status', 'pending')
            ->update(['status' => 'rejected', 'rejection_reason' => $reason, 'reviewed_by' => $staffId, 'reviewed_at' => now()]);

        $pdlName = $relationship->pdl?->full_name ?? 'the PDL';
        $this->logAudit('visitor_pdl_relationships', $relationship->relationship_id,
            "Returned relationship {$visitor->full_name} → {$pdlName} for changes"
            . ($returned ? " ({$returned} " . ($returned === 1 ? 'file' : 'files') . ' to replace)' : '') . ": {$reason}");

        VisitorController::notifyAdditionalPdl($visitor, $relationship, 'relationship_returned', 'PDL Relationship Needs Changes',
            "Staff reviewed your relationship to {$pdlName} ({$relationship->relationshipLabel()}) and need a few changes: {$reason} "
            . 'Upload the corrected documents in the app and staff will review them again.');

        return back()->with('success', "Returned to {$visitor->full_name} with what to fix. The link stays pending until they send the corrected documents.");
    }

    // -----------------------------------------------------------------
    // Officer confirmations (no file involved)
    // -----------------------------------------------------------------
    public function confirm(Request $request, VisitorProfile $visitor, VisitorPdlRelationship $relationship, string $key)
    {
        abort_unless($relationship->visitor_id === $visitor->visitor_id, 404);
        abort_unless(RelationshipRequirements::isConfirmationKey($key), 404);

        $validated = $request->validate([
            'note' => [$key === 'accompanying_guardian' ? 'required' : 'nullable', 'string', 'max:150'],
        ], [
            'note.required' => 'Enter the name of the parent or guardian who will accompany the minor.',
        ]);

        if ($locked = $this->abortIfLocked($relationship)) {
            return $locked;
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $confirmations = $relationship->officer_confirmations ?? [];
        $confirmations[$key] = [
            'by' => $staffId,
            'at' => now()->toIso8601String(),
            'note' => $validated['note'] ?? null,
        ];
        $relationship->update(['officer_confirmations' => $confirmations]);

        $label = RelationshipRequirements::CONFIRMATIONS[$key][0];
        $this->logAudit('visitor_pdl_relationships', $relationship->relationship_id,
            "Confirmed '{$label}' for {$visitor->full_name}" . (!empty($validated['note']) ? " ({$validated['note']})" : ''));

        return back()->with('success', "{$label}: confirmed.");
    }

    public function unconfirm(VisitorProfile $visitor, VisitorPdlRelationship $relationship, string $key)
    {
        abort_unless($relationship->visitor_id === $visitor->visitor_id, 404);
        abort_unless(RelationshipRequirements::isConfirmationKey($key), 404);

        if ($locked = $this->abortIfLocked($relationship)) {
            return $locked;
        }

        $confirmations = $relationship->officer_confirmations ?? [];
        unset($confirmations[$key]);
        $relationship->update(['officer_confirmations' => $confirmations ?: null]);

        return back()->with('success', RelationshipRequirements::CONFIRMATIONS[$key][0] . ': confirmation removed.');
    }

    // -----------------------------------------------------------------
    // Relationship details the checklist depends on
    // -----------------------------------------------------------------
    public function updateDetails(Request $request, VisitorProfile $visitor, VisitorPdlRelationship $relationship)
    {
        abort_unless($relationship->visitor_id === $visitor->visitor_id, 404);

        $validated = $request->validate([
            'relationship_type' => ['required', Rule::in(VisitorPdlRelationship::RELATIONSHIP_TYPES)],
            'has_children_together' => ['nullable', 'boolean'],
        ]);

        $changes = [
            'relationship_type' => $validated['relationship_type'],
            'priority_tier' => VisitorPdlRelationship::priorityTierFor($validated['relationship_type']),
            'has_children_together' => $validated['relationship_type'] === 'live_in_partner' && $request->filled('has_children_together')
                ? $request->boolean('has_children_together')
                : null,
        ];

        // Different details mean a different checklist, so an earlier
        // verification no longer stands.
        $changed = $changes['relationship_type'] !== $relationship->relationship_type
            || $changes['has_children_together'] !== $relationship->has_children_together;
        if ($changed && $relationship->verification_status === 'verified') {
            $changes += ['verification_status' => 'pending', 'verified_by' => null, 'verified_at' => null];
        }

        $oldLabel = $relationship->relationshipLabel();
        $relationship->update($changes);

        $this->logAudit('visitor_pdl_relationships', $relationship->relationship_id,
            "Updated relationship details for {$visitor->full_name}: {$oldLabel} → {$relationship->relationshipLabel()}");

        return back()->with('success', 'Relationship details updated.' . (isset($changes['verification_status']) ? ' It needs to be verified again.' : ''));
    }
}
