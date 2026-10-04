<?php

namespace App\Http\Controllers;

use App\Models\VisitorProfile;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorFlag;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

        $stats = [
            'total_visitors' => VisitorProfile::count(),
            'pending_verification' => VisitorProfile::where('verification_status', 'pending')->count(),
            'flagged' => VisitorProfile::whereHas('activeFlags')->count(),
        ];

        return view('visitor.index', compact('visitors', 'query', 'status', 'stats'));
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
            'flags' => fn ($q) => $q->orderBy('created_at', 'desc'),
            'visitRequests' => fn ($q) => $q->with(['pdl', 'schedule'])->orderByDesc('assigned_at')->limit(10),
        ]);

        $assignableRelationships = $visitor->relationships
            ->filter(fn ($rel) => $rel->verification_status !== 'rejected' && $rel->pdl)
            ->values();

        $schedulesByClassification = [];
        foreach ($assignableRelationships->pluck('pdl.classification')->unique()->filter() as $classification) {
            $schedulesByClassification[$classification] = VisitAssignmentController::openSchedulesForClassification($classification);
        }

        return view('visitor.show', compact('visitor', 'assignableRelationships', 'schedulesByClassification'));
    }

    // -----------------------------------------------------------------
    // IDENTITY VERIFICATION — verify or reject an uploaded ID document
    // -----------------------------------------------------------------
    public function verifyId(VisitorProfile $visitor, VisitorId $idDocument)
    {
        abort_unless($idDocument->visitor_id === $visitor->visitor_id, 404);

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
    public function verifyRelationship(VisitorProfile $visitor, VisitorPdlRelationship $relationship)
    {
        abort_unless($relationship->visitor_id === $visitor->visitor_id, 404);

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
            'description' => ['required', 'string'],
        ]);

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
