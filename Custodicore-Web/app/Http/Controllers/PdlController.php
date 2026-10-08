<?php

namespace App\Http\Controllers;

use App\Models\CellBlock;
use App\Models\Pdl;
use App\Models\PdlLegalRecord;
use App\Models\PdlDisciplinaryRecord;
use App\Models\PdlRestriction;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PdlController extends Controller
{
    /**
     * TEMPORARY STAND-IN — not real auth.
     *
     * registered_by / recorded_by / imposed_by need a real staff_profiles.staff_id.
     * Proper fix is wiring auth() to accounts/staff_profiles (someone else's
     * task, not this module's). Until then, this just grabs the first
     * staff_profiles row that exists so the rest of this controller is
     * actually testable end-to-end.
     *
     * Swap the body of this method for the real thing once auth exists,
     * e.g.: return auth()->user()->staffProfile->staff_id;
     */
    private function currentStaffId(): int
    {
        $staff = \App\Models\StaffProfile::first();

        if (!$staff) {
            throw new \RuntimeException(
                'No staff_profiles row exists yet. Create at least one via '
                . 'Tinker before testing PDL registration/edits — see the '
                . 'testing steps for the exact commands.'
            );
        }

        return $staff->staff_id;
    }

    /**
     * Write an audit log entry without letting a logging failure hide
     * whether the *actual* action (the PDL create/update/etc.) succeeded.
     * We hit exactly this bug once already: module_id pointed at a row
     * that didn't exist yet, and it turned a successful PDL registration
     * into what looked like a total failure. This logs the problem instead
     * of throwing it back at the user.
     */
    private function logAudit(string $actionType, string $recordType, int $recordId, string $description): void
    {
        try {
            AuditLog::record($actionType, $recordType, $recordId, $description, \App\Models\Module::CODE_PDL_MANAGEMENT);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed (main action still succeeded): ' . $e->getMessage(), [
                'action_type' => $actionType,
                'record_type' => $recordType,
                'record_id' => $recordId,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // LIST + SEARCH + FILTER
    // -----------------------------------------------------------------
    public function index(Request $request)
    {
        $query = trim((string) $request->input('q', ''));
        $status = trim((string) $request->input('status', ''));
        $cellBlock = trim((string) $request->input('cell', ''));

        $pdls = Pdl::query()
            ->when($query !== '', function ($q) use ($query) {
                $q->where(function ($q2) use ($query) {
                    $q2->where('full_name', 'like', "%{$query}%")
                        ->orWhere('alias', 'like', "%{$query}%")
                        ->orWhere('pdl_number', 'like', "%{$query}%");
                });
            })
            ->when($status !== '', fn ($q) => $q->where('custody_status', $status))
            ->when($cellBlock !== '', fn ($q) => $q->where('cell_block', $cellBlock))
            ->orderBy('admission_date', 'desc')
            ->paginate(25)
            ->withQueryString();

        $cellBlocks = CellBlock::orderBy('name')->pluck('name');

        $stats = [
            'total_population' => Pdl::count(),
            'currently_active' => Pdl::where('custody_status', 'active')->count(),
            'deceased' => Pdl::where('custody_status', 'deceased')->count(),
            'released_ytd' => Pdl::where('custody_status', 'released')
                ->whereYear('updated_at', now()->year)
                ->count(),
        ];

        return view('pdl.index', compact('pdls', 'query', 'status', 'cellBlock', 'cellBlocks', 'stats'));
    }

    // -----------------------------------------------------------------
    // REGISTER NEW PDL
    // -----------------------------------------------------------------
    public function create()
    {
        $cellBlocks = $this->assignableCellBlocks();

        return view('pdl.create', compact('cellBlocks'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'alias' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
            'gender' => ['required', 'in:male,female,other'],
            'classification' => ['required', 'in:drug_related,non_drug_related'],
            'cell_block' => ['required', 'string', 'max:50', $this->cellBlockRule($request->input('gender'))],
            'admission_date' => ['required', 'date'],
        ], [
            'cell_block.required' => 'Please select a cell for this PDL.',
        ]);

        try {
            $pdl = Pdl::create([
                ...$validated,
                'pdl_number' => 'PDL-' . now()->format('Y') . '-' . str_pad((string) (Pdl::max('pdl_id') + 1), 5, '0', STR_PAD_LEFT),
                'custody_status' => 'active',
                'registered_by' => $this->currentStaffId(),
            ]);
        } catch (\Throwable $e) {
            Log::error('PDL registration failed: ' . $e->getMessage());
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Could not register this PDL. ' . $this->friendlyMessage($e));
        }

        $this->logAudit(
            'create',
            'pdl_profiles',
            $pdl->pdl_id,
            "Registered new PDL: {$pdl->full_name} ({$pdl->pdl_number})"
        );

        return redirect()
            ->route('pdl.show', $pdl->pdl_id)
            ->with('success', "PDL profile for {$pdl->full_name} created successfully.");
    }

    // -----------------------------------------------------------------
    // DETAIL / SHOW — core profile + the three one-to-many record types
    // -----------------------------------------------------------------
    public function show(Pdl $pdl)
    {
        $pdl->load(['legalRecords', 'disciplinaryRecords', 'restrictions']);

        // Visitation eligibility status, shown on the PDL's own page — this
        // is PDL-side information (is *this PDL* currently clear to
        // receive visitors), as opposed to visitor-side verification
        // (identity/relationship/history), which stays on the Visitor's
        // own page in Visitor Management and isn't duplicated here.
        $eligibilityStatus = $this->computeEligibilityStatus($pdl);
        $cellBlocks = $this->assignableCellBlocks($pdl->cell_block);

        return view('pdl.show', compact('pdl', 'eligibilityStatus', 'cellBlocks'));
    }

    private function computeEligibilityStatus(Pdl $pdl): array
    {
        if ($pdl->activeRestrictions()->exists()) {
            return ['label' => 'Restricted — Visitation Blocked', 'class' => 'restricted'];
        }

        $latestAssessment = \App\Models\EligibilityAssessment::whereHas(
            'visitRequest',
            fn ($q) => $q->where('pdl_id', $pdl->pdl_id)
        )->orderBy('assessed_at', 'desc')->first();

        if (!$latestAssessment) {
            return ['label' => 'Not Yet Assessed', 'class' => 'transferred'];
        }

        return match ($latestAssessment->overall_result) {
            'eligible' => ['label' => 'Cleared for Visitation', 'class' => 'active'],
            'flagged_for_review' => ['label' => 'Pending Review', 'class' => 'transferred'],
            'rejected' => ['label' => 'Rejected', 'class' => 'rejected'],
            default => ['label' => 'Not Yet Assessed', 'class' => 'transferred'],
        };
    }

    // -----------------------------------------------------------------
    // UPDATE core profile fields
    // -----------------------------------------------------------------
    public function update(Request $request, Pdl $pdl)
    {
        // A PDL in active custody must be in a cell; released, transferred
        // and deceased PDLs may have none.
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'alias' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
            'gender' => ['required', 'in:male,female,other'],
            'classification' => ['required', 'in:drug_related,non_drug_related'],
            'cell_block' => ['required_if:custody_status,active', 'nullable', 'string', 'max:50', $this->cellBlockRule($request->input('gender'), $pdl, $request->input('custody_status'))],
            'admission_date' => ['required', 'date'],
            'custody_status' => ['required', 'in:active,released,transferred,deceased'],
        ], [
            'cell_block.required_if' => 'Please select a cell for this PDL.',
        ]);

        $statusChanged = $validated['custody_status'] !== $pdl->custody_status;
        $cellChanged = $validated['cell_block'] !== $pdl->cell_block;
        $previousStatus = $pdl->custody_status;
        $previousCell = $pdl->cell_block;

        try {
            $pdl->update($validated);
        } catch (\Throwable $e) {
            Log::error('PDL update failed: ' . $e->getMessage());
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Could not save these changes. ' . $this->friendlyMessage($e));
        }

        if ($statusChanged || $cellChanged) {
            $parts = [];
            if ($statusChanged) {
                $parts[] = "Custody status: {$previousStatus} → {$validated['custody_status']}";
            }
            if ($cellChanged) {
                $parts[] = "Cell/Block: " . ($previousCell ?? '—') . ' → ' . ($validated['cell_block'] ?? '—');
            }

            $this->logAudit(
                'update',
                'pdl_custody_status',
                $pdl->pdl_id,
                implode('; ', $parts) . ' (password re-confirmed)'
            );
        }

        return redirect()
            ->route('pdl.show', $pdl->pdl_id)
            ->with('success', 'PDL profile updated.');
    }

    // -----------------------------------------------------------------
    // Add a legal record (one PDL can have many)
    // -----------------------------------------------------------------
    public function storeLegalRecord(Request $request, Pdl $pdl)
    {
        $validated = $request->validate([
            'case_number' => ['required', 'string', 'max:50'],
            'offense' => ['required', 'string', 'max:255'],
            'court' => ['nullable', 'string', 'max:150'],
            'case_status' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string'],
        ]);

        try {
            PdlLegalRecord::create([
                ...$validated,
                'pdl_id' => $pdl->pdl_id,
                'recorded_by' => $this->currentStaffId(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Legal record creation failed: ' . $e->getMessage());
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Could not add this legal record. ' . $this->friendlyMessage($e));
        }

        return redirect()->route('pdl.show', $pdl->pdl_id)->with('success', 'Legal record added.');
    }

    // -----------------------------------------------------------------
    // Add a disciplinary record
    // -----------------------------------------------------------------
    public function storeDisciplinaryRecord(Request $request, Pdl $pdl)
    {
        $validated = $request->validate([
            'incident_date' => ['required', 'date'],
            'description' => ['required', 'string'],
            'action_taken' => ['nullable', 'string', 'max:255'],
            'triggers_restriction' => ['nullable', 'boolean'],
        ]);
        $validated['triggers_restriction'] = $request->boolean('triggers_restriction');

        try {
            PdlDisciplinaryRecord::create([
                ...$validated,
                'pdl_id' => $pdl->pdl_id,
                'recorded_by' => $this->currentStaffId(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Disciplinary record creation failed: ' . $e->getMessage());
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Could not add this disciplinary record. ' . $this->friendlyMessage($e));
        }

        return redirect()->route('pdl.show', $pdl->pdl_id)->with('success', 'Disciplinary record added.');
    }

    // -----------------------------------------------------------------
    // Add a restriction
    // -----------------------------------------------------------------
    public function storeRestriction(Request $request, Pdl $pdl)
    {
        $validated = $request->validate([
            'restriction_type' => ['required', 'in:' . implode(',', PdlRestriction::TYPES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            PdlRestriction::create([
                ...$validated,
                'pdl_id' => $pdl->pdl_id,
                'status' => 'active',
                'imposed_by' => $this->currentStaffId(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Restriction creation failed: ' . $e->getMessage());
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Could not add this restriction. ' . $this->friendlyMessage($e));
        }

        return redirect()->route('pdl.show', $pdl->pdl_id)->with('success', 'Restriction added.');
    }

    // -----------------------------------------------------------------
    // Lift a restriction (soft state change, not a delete — matches the
    // "immutable audit trail" spirit from the rest of this project)
    // -----------------------------------------------------------------
    public function liftRestriction(Pdl $pdl, PdlRestriction $restriction)
    {
        abort_unless($restriction->pdl_id === $pdl->pdl_id, 404);

        try {
            $restriction->update([
                'status' => 'lifted',
                'end_date' => $restriction->end_date ?? now()->toDateString(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Lifting restriction failed: ' . $e->getMessage());
            return redirect()
                ->back()
                ->with('error', 'Could not lift this restriction. ' . $this->friendlyMessage($e));
        }

        return redirect()->route('pdl.show', $pdl->pdl_id)->with('success', 'Restriction lifted.');
    }

    /**
     * Blocks offered in the Cell/Block dropdown, with occupancy counts.
     * Inactive blocks are hidden, except the one a PDL is already in so the
     * edit form can still show it.
     */
    private function assignableCellBlocks(?string $currentBlock = null)
    {
        return CellBlock::withOccupancy()
            ->where(fn ($q) => $q->where('is_active', true)
                ->when($currentBlock, fn ($q2) => $q2->orWhere('name', $currentBlock)))
            ->orderBy('name')
            ->get();
    }

    /**
     * The chosen block must exist, be active, match the PDL's gender and
     * have a free bed. On edit, a PDL staying in their current block is
     * left alone, and the bed check only applies when they'd be taking a
     * new bed (moving blocks, or going back to active custody).
     */
    private function cellBlockRule(?string $gender, ?Pdl $pdl = null, ?string $newStatus = 'active'): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($gender, $pdl, $newStatus) {
            $staysInSameBlock = $pdl && $value === $pdl->cell_block;

            if ($staysInSameBlock && ($pdl->custody_status === CellBlock::OCCUPYING_STATUS || $newStatus !== CellBlock::OCCUPYING_STATUS)) {
                return;
            }

            $block = CellBlock::withOccupancy()->where('name', $value)->first();

            if (!$block || (!$block->is_active && !$staysInSameBlock)) {
                $fail('Please choose a cell block from the list.');
                return;
            }

            if (!$staysInSameBlock && !$block->acceptsGender($gender)) {
                $fail("{$block->name} is designated for {$block->designation} PDLs only.");
                return;
            }

            if ($newStatus === CellBlock::OCCUPYING_STATUS && $block->isFull()) {
                $fail("{$block->name} is full ({$block->occupied()}/{$block->capacity} occupied). Choose another cell block.");
            }
        };
    }

    /**
     * Turn a raw exception into something safe to show a user. Never echoes
     * the raw exception message (which can leak table/column names, SQL, or
     * file paths) — just a general category so support/dev can dig further
     * via the log entry this method's callers also write.
     */
    private function friendlyMessage(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException) {
            return 'A database error occurred. Please try again, or contact support if this keeps happening.';
        }

        if ($e instanceof \RuntimeException) {
            return $e->getMessage(); // these are our own deliberate messages (e.g. currentStaffId()), safe to show as-is
        }

        return 'An unexpected error occurred. Please try again.';
    }
}