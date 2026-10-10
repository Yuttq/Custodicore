<?php

namespace App\Http\Controllers;

use App\Models\Pdl;
use App\Models\VisitorProfile;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorFlag;
use App\Models\VisitRequest;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Mail\VisitorAccountApproved;
use App\Services\RelationshipRequirements;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class VisitorController extends Controller
{
    /**
     * Same temporary stand-in as PdlController::currentStaffId(). See that
     * method's doc comment — auth isn't wired to staff_profiles yet.
     */
    private function currentStaffId(): int
    {
        $staff = \App\Models\StaffProfile::first();

        if (!$staff) {
            throw new \RuntimeException(
                'No staff_profiles row exists yet. Create one via Tinker first.'
            );
        }

        return $staff->staff_id;
    }

    private function logAudit(string $actionType, string $recordType, int $recordId, string $description): void
    {
        try {
            AuditLog::record($actionType, $recordType, $recordId, $description, \App\Models\Module::CODE_VISITOR_MANAGEMENT);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed: ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // LIST + SEARCH
    // -----------------------------------------------------------------
    public function index(Request $request)
    {
        $query = trim((string) $request->input('q', ''));
        $status = trim((string) $request->input('status', ''));

        $visitors = VisitorProfile::query()
            ->when($query !== '', function ($q) use ($query) {
                $q->where('full_name', 'like', "%{$query}%")
                    ->orWhere('contact_number', 'like', "%{$query}%");
            })
            ->when($status !== '', fn ($q) => $q->where('verification_status', $status))
            ->withCount(['idDocuments', 'activeFlags'])
            ->orderBy('created_at', 'desc')
            ->paginate(25)
            ->withQueryString();

        // Visitor-submitted visit requests awaiting review, soonest visit first.
        $pendingVisitRequests = $this->pendingVisitRequests()->get();

        $stats = [
            'total_visitors' => VisitorProfile::count(),
            'pending_verification' => VisitorProfile::where('verification_status', 'pending')->count(),
            'flagged' => VisitorProfile::whereHas('activeFlags')->count(),
            'pending_visit_requests' => $pendingVisitRequests->count(),
        ];

        return view('visitor.index', compact('visitors', 'query', 'status', 'stats', 'pendingVisitRequests'));
    }

    // -----------------------------------------------------------------
    // DETAIL / SHOW — profile + ID docs + relationships + flags + assign
    // -----------------------------------------------------------------
    public function show(VisitorProfile $visitor)
    {
        $visitor->load([
            'account',
            'idDocuments',
            'relationships.pdl',
            'relationships.documents',
            'flags' => fn ($q) => $q->orderBy('created_at', 'desc'),
            'visitRequests' => fn ($q) => $q->with(['pdl', 'schedule', 'sessionSchedules'])->orderByDesc('assigned_at')->limit(10),
        ]);

        $assignableRelationships = $visitor->relationships
            ->filter(fn ($rel) => $rel->verification_status !== 'rejected' && $rel->pdl)
            ->values();

        $schedulesByClassification = [];
        foreach ($assignableRelationships->pluck('pdl.classification')->unique()->filter() as $classification) {
            $schedulesByClassification[$classification] = VisitAssignmentController::openSchedulesForClassification($classification);
        }

        // PDLs this visitor isn't related to yet, for the "Add Relationship" form.
        $relatablePdls = Pdl::whereNotIn('pdl_id', $visitor->relationships->pluck('pdl_id'))
            ->orderBy('full_name')
            ->get(['pdl_id', 'pdl_number', 'full_name', 'custody_status']);

        $pendingVisitRequests = $this->pendingVisitRequests()
            ->where('visit_requests.visitor_id', $visitor->visitor_id)
            ->get();

        return view('visitor.show', compact('visitor', 'assignableRelationships', 'schedulesByClassification', 'relatablePdls', 'pendingVisitRequests'));
    }

    /** Visitor-submitted visit requests (`assigned`) awaiting Record Officer review. */
    private function pendingVisitRequests()
    {
        return VisitRequest::query()
            ->select('visit_requests.*')
            ->where('visit_requests.status', 'assigned')
            ->join('visit_schedules', 'visit_schedules.schedule_id', '=', 'visit_requests.schedule_id')
            ->with(['visitor', 'pdl', 'relationship', 'schedule', 'sessionSchedules', 'eligibilityAssessment'])
            ->orderBy('visit_schedules.schedule_date')
            ->orderBy('visit_schedules.time_slot_start')
            ->orderBy('visit_requests.assigned_at');
    }

    // -----------------------------------------------------------------
    // ACCOUNT REVIEW — approve or reject the visitor's submitted
    // information and documents (visitor_profiles.verification_status).
    // Separate from email verification, which the visitor does themselves.
    // -----------------------------------------------------------------
    public function approve(Request $request, VisitorProfile $visitor)
    {
        if ($visitor->verification_status === 'verified') {
            return back()->with('success', 'This visitor is already approved.');
        }

        try {
            $visitor->update([
                'verification_status' => 'verified',
                'verified_by' => $this->reviewingStaffId($request),
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Visitor approval failed: ' . $e->getMessage());
            return back()->with('error', 'Could not approve this visitor. ' . $e->getMessage());
        }

        Notification::notify(
            $visitor->account_id,
            'account_approved',
            'Account Approved',
            'Your account has been approved. Your information and documents have been reviewed and approved by staff. You can now use the visitor services available in the app.',
            'visitor_profiles',
            $visitor->visitor_id
        );

        $visitor->loadMissing('account');
        if ($visitor->account) {
            try {
                Mail::to($visitor->account->email)->send(new VisitorAccountApproved($visitor->account));
            } catch (\Throwable $e) {
                // The in-app notification above is the authoritative record.
                Log::warning('Approval email could not be sent for visitor #' . $visitor->visitor_id . ' (' . $e::class . ')');
            }
        }

        $this->logAudit('update', 'visitor_profiles', $visitor->visitor_id,
            "Approved visitor account: {$visitor->full_name}");

        return back()->with('success', 'Visitor approved. The visitor has been notified.');
    }

    public function reject(Request $request, VisitorProfile $visitor)
    {
        // Shown to the visitor in the app — keep it to what they can act on.
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'rejection_reason.required' => 'Tell the visitor why their account was not approved.',
            'rejection_reason.min' => 'Give a reason of at least 5 characters so the visitor knows what to fix.',
        ]);
        $reason = trim((string) $data['rejection_reason']);

        if ($visitor->verification_status === 'rejected') {
            return back()->with('error', 'This visitor is already rejected.');
        }

        try {
            $visitor->update([
                'verification_status' => 'rejected',
                'verified_by' => $this->reviewingStaffId($request),
                'verified_at' => now(),
                'rejection_reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::error('Visitor rejection failed: ' . $e->getMessage());
            return back()->with('error', 'Could not reject this visitor. ' . $e->getMessage());
        }

        Notification::notify(
            $visitor->account_id,
            'account_rejected',
            'Account Not Approved',
            \Illuminate\Support\Str::limit(
                'Your submitted information and documents were not approved.' . ($reason ? " Reason: {$reason}" : ' Please contact facility staff for details.'),
                500
            ),
            'visitor_profiles',
            $visitor->visitor_id
        );

        $this->logAudit('update', 'visitor_profiles', $visitor->visitor_id,
            "Rejected visitor account: {$visitor->full_name}");

        return back()->with('success', 'Visitor rejected. The visitor has been notified.');
    }

    /** The signed-in staff member's profile, else the existing stand-in. */
    private function reviewingStaffId(Request $request): int
    {
        return $request->user()?->staffProfile?->staff_id ?? $this->currentStaffId();
    }

    // -----------------------------------------------------------------
    // IDENTITY VERIFICATION — verify or reject an uploaded ID document
    // -----------------------------------------------------------------
    public function verifyId(VisitorProfile $visitor, VisitorId $idDocument)
    {
        abort_unless($idDocument->visitor_id === $visitor->visitor_id, 404);

        if ($idDocument->verification_status === 'verified') {
            return back()->with('error', 'This ID document is already verified.');
        }

        try {
            $idDocument->update([
                'verification_status' => 'verified',
                'verified_by' => $this->currentStaffId(),
                'verified_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ID verification failed: ' . $e->getMessage());
            return back()->with('error', 'Could not verify this ID. ' . $e->getMessage());
        }

        $this->logAudit('update', 'visitor_ids', $idDocument->visitor_id_doc_id,
            "Verified {$idDocument->typeLabel()} for {$visitor->full_name}");

        return back()->with('success', 'ID document verified.');
    }

    public function rejectId(Request $request, VisitorProfile $visitor, VisitorId $idDocument)
    {
        abort_unless($idDocument->visitor_id === $visitor->visitor_id, 404);

        if ($idDocument->verification_status === 'rejected') {
            return back()->with('error', 'This ID document is already rejected.');
        }

        try {
            $idDocument->update([
                'verification_status' => 'rejected',
                'verified_by' => $this->currentStaffId(),
                'verified_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ID rejection failed: ' . $e->getMessage());
            return back()->with('error', 'Could not reject this ID. ' . $e->getMessage());
        }

        $this->logAudit('update', 'visitor_ids', $idDocument->visitor_id_doc_id,
            "Rejected {$idDocument->typeLabel()} for {$visitor->full_name}");

        return back()->with('success', 'ID document rejected.');
    }

    // -----------------------------------------------------------------
    // RELATIONSHIP VERIFICATION — verify or reject a visitor↔PDL relationship
    // -----------------------------------------------------------------
    // Staff register the relationship as pending; verifying it is a separate
    // step (verifyRelationship below).
    public function storeRelationship(Request $request, VisitorProfile $visitor)
    {
        $validated = $request->validate([
            'pdl_id' => [
                'required', 'integer',
                Rule::exists('pdl_profiles', 'pdl_id'),
                Rule::unique('visitor_pdl_relationships', 'pdl_id')->where('visitor_id', $visitor->visitor_id),
            ],
            'relationship_type' => ['required', Rule::in(VisitorPdlRelationship::RELATIONSHIP_TYPES)],
            'has_children_together' => ['nullable', 'boolean'],
        ], [
            'pdl_id.unique' => 'This visitor already has a relationship with the selected PDL.',
        ]);

        // BJMP priority: spouse, parents and legal guardian.
        $validated['priority_tier'] = VisitorPdlRelationship::priorityTierFor($validated['relationship_type']);
        $validated['has_children_together'] = $validated['relationship_type'] === 'live_in_partner' && $request->filled('has_children_together')
            ? $request->boolean('has_children_together')
            : null;

        try {
            $relationship = VisitorPdlRelationship::create([
                ...$validated,
                'visitor_id' => $visitor->visitor_id,
                'verification_status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Unique (visitor_id, pdl_id) — a concurrent request got there first.
            return back()->withInput()->withErrors([
                'pdl_id' => 'This visitor already has a relationship with the selected PDL.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Relationship creation failed: ' . $e->getMessage());
            return back()->withInput()->with('error', 'Could not add this relationship. ' . $e->getMessage());
        }

        $this->logAudit('create', 'visitor_pdl_relationships', $relationship->relationship_id,
            "Added relationship: {$visitor->full_name} as {$relationship->relationshipLabel()} to PDL #{$relationship->pdl_id} (pending verification)");

        return redirect()->route('visitor.show', $visitor->visitor_id)
            ->with('success', 'Relationship added. It is pending verification.');
    }

    public function verifyRelationship(VisitorProfile $visitor, VisitorPdlRelationship $relationship)
    {
        abort_unless($relationship->visitor_id === $visitor->visitor_id, 404);

        if ($relationship->verification_status === 'verified') {
            return back()->with('error', 'This relationship is already verified.');
        }

        $requirements = RelationshipRequirements::for($relationship);
        if (! RelationshipRequirements::allMet($requirements)) {
            return back()->with('error', 'This relationship cannot be verified yet. Still needed: '
                . implode(', ', RelationshipRequirements::unmetLabels($requirements)) . '.');
        }

        try {
            $relationship->update([
                'verification_status' => 'verified',
                'verified_by' => $this->currentStaffId(),
                'verified_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Relationship verification failed: ' . $e->getMessage());
            return back()->with('error', 'Could not verify this relationship. ' . $e->getMessage());
        }

        $this->logAudit('update', 'visitor_pdl_relationships', $relationship->relationship_id,
            "Verified relationship: {$visitor->full_name} as {$relationship->relationshipLabel()} to PDL #{$relationship->pdl_id}");

        return back()->with('success', 'Relationship verified.');
    }

    public function rejectRelationship(VisitorProfile $visitor, VisitorPdlRelationship $relationship)
    {
        abort_unless($relationship->visitor_id === $visitor->visitor_id, 404);

        if ($relationship->verification_status === 'rejected') {
            return back()->with('error', 'This relationship is already rejected.');
        }

        try {
            $relationship->update([
                'verification_status' => 'rejected',
                'verified_by' => $this->currentStaffId(),
                'verified_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Relationship rejection failed: ' . $e->getMessage());
            return back()->with('error', 'Could not reject this relationship. ' . $e->getMessage());
        }

        $this->logAudit('update', 'visitor_pdl_relationships', $relationship->relationship_id,
            "Rejected relationship: {$visitor->full_name} as {$relationship->relationshipLabel()} to PDL #{$relationship->pdl_id}");

        return back()->with('success', 'Relationship rejected.');
    }

    // -----------------------------------------------------------------
    // FLAG a visitor (history/behavior tracking)
    // -----------------------------------------------------------------
    public function storeFlag(Request $request, VisitorProfile $visitor)
    {
        $validated = $request->validate([
            'flag_type' => ['required', 'in:' . implode(',', VisitorFlag::TYPES)],
            'description' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'description.min' => 'Describe the reason for the flag in at least 10 characters.',
        ]);

        // One open flag per type is enough; resolve it before adding another.
        if ($visitor->flags()->where('flag_type', $validated['flag_type'])->where('status', 'active')->exists()) {
            return back()->withInput()->withErrors(['flag_type' => 'This visitor already has an active flag of this type.']);
        }

        try {
            VisitorFlag::create([
                ...$validated,
                'visitor_id' => $visitor->visitor_id,
                'flagged_by' => $this->currentStaffId(),
                'status' => 'active',
            ]);
        } catch (\Throwable $e) {
            Log::error('Flagging visitor failed: ' . $e->getMessage());
            return back()->with('error', 'Could not add this flag. ' . $e->getMessage());
        }

        return back()->with('success', 'Visitor flagged.');
    }

    public function resolveFlag(VisitorProfile $visitor, VisitorFlag $flag)
    {
        abort_unless($flag->visitor_id === $visitor->visitor_id, 404);

        if ($flag->status !== 'active') {
            return back()->with('error', 'This flag has already been resolved.');
        }

        try {
            $flag->update([
                'status' => 'resolved',
                'resolved_by' => $this->currentStaffId(),
                'resolved_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Resolving flag failed: ' . $e->getMessage());
            return back()->with('error', 'Could not resolve this flag. ' . $e->getMessage());
        }

        return back()->with('success', 'Flag resolved.');
    }
}
