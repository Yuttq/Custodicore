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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PdlController extends Controller
{
    // PDL photo: a real picture (checked from the file's content, not just
    // its name), JPG/PNG/WEBP, at most 5 MB.
    private const PHOTO_RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

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
        $validated = $this->validateProfile($request, [
            'cell_block' => ['required', 'string', 'max:50', $this->cellBlockRule($request->input('gender'))],
            'photo' => ['required', ...self::PHOTO_RULES],
        ]);
        unset($validated['photo']);

        $photoPath = $request->file('photo')->store('pdl-photos', 'local');

        try {
            $pdl = Pdl::create([
                ...$validated,
                'photo_path' => $photoPath,
                'pdl_number' => 'PDL-' . now()->format('Y') . '-' . str_pad((string) (Pdl::max('pdl_id') + 1), 5, '0', STR_PAD_LEFT),
                'custody_status' => 'active',
                'registered_by' => $this->currentStaffId(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($photoPath); // don't leave an orphaned file behind
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
        $pdl->load(['legalRecords', 'disciplinaryRecords', 'restrictions', 'registeredBy']);

        // Visitation eligibility status, shown on the PDL's own page — this
        // is PDL-side information (is *this PDL* currently clear to
        // receive visitors), as opposed to visitor-side verification
        // (identity/relationship/history), which stays on the Visitor's
        // own page in Visitor Management and isn't duplicated here.
        $eligibilityStatus = $this->computeEligibilityStatus($pdl);
        $cellBlocks = $this->assignableCellBlocks($pdl->cell_block);

        // Visiting days for this PDL's classification, from the facility's
        // current visitation rules (e.g. "Thu, Sat").
        $dayOrder = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $visitingDays = \App\Models\FacilityVisitationRule::where('pdl_classification', $pdl->classification)
            ->whereDate('effective_from', '<=', today())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->pluck('day_of_week')
            ->unique()
            ->sortBy(fn ($d) => array_search($d, $dayOrder, true))
            ->map(fn ($d) => ucfirst($d))
            ->implode(', ');

        return view('pdl.show', compact('pdl', 'eligibilityStatus', 'cellBlocks', 'visitingDays'));
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
        $validated = $this->validateProfile($request, [
            'cell_block' => ['required_if:custody_status,active', 'nullable', 'string', 'max:50', $this->cellBlockRule($request->input('gender'), $pdl, $request->input('custody_status'))],
            'custody_status' => ['required', 'in:active,released,transferred,deceased'],
            'photo' => ['nullable', ...self::PHOTO_RULES],
        ], $pdl);
        unset($validated['photo']);

        // A deceased PDL's record is final; it can't be moved back into custody.
        if ($pdl->custody_status === 'deceased' && $validated['custody_status'] !== 'deceased') {
            return back()->withInput()->withErrors([
                'custody_status' => 'This PDL is recorded as deceased. The custody status can no longer be changed.',
            ]);
        }

        $statusChanged = $validated['custody_status'] !== $pdl->custody_status;
        $cellChanged = $validated['cell_block'] !== $pdl->cell_block;
        $previousStatus = $pdl->custody_status;
        $previousCell = $pdl->cell_block;

        $oldPhoto = $pdl->photo_path;
        $newPhoto = $request->hasFile('photo') ? $request->file('photo')->store('pdl-photos', 'local') : null;
        if ($newPhoto) {
            $validated['photo_path'] = $newPhoto;
        }

        try {
            $pdl->update($validated);
        } catch (\Throwable $e) {
            if ($newPhoto) {
                Storage::disk('local')->delete($newPhoto);
            }
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

        if ($newPhoto) {
            // Saved successfully, so the previous picture is no longer needed.
            if ($oldPhoto) {
                Storage::disk('local')->delete($oldPhoto);
            }
            $this->logAudit('update', 'pdl_profiles', $pdl->pdl_id, "Photo replaced for {$pdl->full_name} (password re-confirmed)");
        }

        return redirect()
            ->route('pdl.show', $pdl->pdl_id)
            ->with('success', 'PDL profile updated.');
    }

    /** Serves the PDL's photo from the private disk to signed-in staff. */
    public function photo(Pdl $pdl)
    {
        abort_unless($pdl->photo_path && Storage::disk('local')->exists($pdl->photo_path), 404);

        return Storage::disk('local')->response($pdl->photo_path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    // -----------------------------------------------------------------
    // Add a legal record (one PDL can have many)
    // -----------------------------------------------------------------
    public function storeLegalRecord(Request $request, Pdl $pdl)
    {
        $validated = $request->validate([
            'case_number' => [
                'required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\/\-]*$/',
                // The same case can't be filed twice on one PDL.
                Rule::unique('pdl_legal_records', 'case_number')->where('pdl_id', $pdl->pdl_id),
            ],
            'offense' => ['required', 'string', 'min:3', 'max:255'],
            'court' => ['nullable', 'string', 'max:150'],
            'case_status' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ], [
            'case_number.regex' => 'Case number may only contain letters, numbers, spaces, dots, slashes and dashes.',
            'case_number.unique' => 'This case number is already on file for this PDL.',
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
        $admitted = $pdl->admission_date->toDateString();
        $validated = $request->validate([
            // An incident can only happen while the PDL is in custody.
            'incident_date' => ['required', 'date', 'before_or_equal:today', "after_or_equal:{$admitted}"],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:255'],
            'triggers_restriction' => ['nullable', 'boolean'],
        ], [
            'incident_date.before_or_equal' => 'The incident date cannot be in the future.',
            'incident_date.after_or_equal' => 'The incident date cannot be before the PDL\'s admission (' . $pdl->admission_date->format('M j, Y') . ').',
            'description.min' => 'Describe the incident in at least 10 characters.',
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
        $admitted = $pdl->admission_date->toDateString();
        $validated = $request->validate([
            'restriction_type' => [
                'required', 'in:' . implode(',', PdlRestriction::TYPES),
                // Only one active restriction of each type at a time.
                Rule::unique('pdl_restrictions', 'restriction_type')->where('pdl_id', $pdl->pdl_id)->where('status', 'active'),
            ],
            'start_date' => ['required', 'date', "after_or_equal:{$admitted}"],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'restriction_type.unique' => 'This PDL already has an active restriction of this type. Lift it first or edit that one.',
            'start_date.after_or_equal' => 'The start date cannot be before the PDL\'s admission (' . $pdl->admission_date->format('M j, Y') . ').',
            'end_date.after_or_equal' => 'The end date must be on or after the start date and not in the past.',
            'reason.min' => 'Give a reason of at least 5 characters.',
        ]);

        if ($pdl->custody_status !== 'active') {
            return back()->withInput()->with('error', 'Restrictions can only be added to PDLs in active custody.');
        }

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

        if (! $restriction->isActive()) {
            return back()->with('error', 'This restriction has already been lifted.');
        }

        try {
            $restriction->update([
                'status' => 'lifted',
                // Lifted early → it ends today, not on the originally planned date.
                'end_date' => $restriction->end_date && $restriction->end_date->lte(today())
                    ? $restriction->end_date
                    : now()->toDateString(),
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
     * Shared rules for registering and editing a PDL profile. $extra adds
     * the fields that differ (cell_block, custody_status).
     *
     * Beyond the field formats: the PDL must be at least 18 on admission
     * (minors are not held in BJMP jails), the admission can't be in the
     * future or before birth, the composed full name must fit its column,
     * and the same person (name + birth date) can't be registered twice.
     */
    private function validateProfile(Request $request, array $extra, ?Pdl $pdl = null): array
    {
        $name = ['string', 'max:100', "regex:/^\\pL[\\pL\\s.'\\-]*$/u"];

        $validator = validator($request->all(), [
            'first_name' => ['required', ...$name],
            'middle_name' => ['nullable', ...$name],
            'last_name' => ['required', ...$name],
            'alias' => ['nullable', 'string', 'max:150'],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['required', 'in:male,female,other'],
            'classification' => ['required', 'in:drug_related,non_drug_related'],
            'admission_date' => ['required', 'date', 'before_or_equal:today', 'after:date_of_birth'],
            ...$extra,
        ], [
            'first_name.regex' => 'First name may only contain letters, spaces, dots, apostrophes and dashes.',
            'middle_name.regex' => 'Middle name may only contain letters, spaces, dots, apostrophes and dashes.',
            'last_name.regex' => 'Last name may only contain letters, spaces, dots, apostrophes and dashes.',
            'date_of_birth.before' => 'The date of birth must be in the past.',
            'date_of_birth.after' => 'Please enter a valid date of birth.',
            'admission_date.before_or_equal' => 'The admission date cannot be in the future.',
            'admission_date.after' => 'The admission date must be after the date of birth.',
            'cell_block.required' => 'Please select a cell for this PDL.',
            'cell_block.required_if' => 'Please select a cell for this PDL.',
            'photo.required' => 'Please add a photo of the PDL.',
            'photo.image' => 'The photo must be a JPG, PNG or WEBP picture.',
            'photo.mimes' => 'The photo must be a JPG, PNG or WEBP picture.',
            'photo.max' => 'The photo is too large. Maximum size is 5 MB.',
            'photo.uploaded' => 'The photo could not be uploaded. Try a smaller picture (up to 5 MB).',
        ]);

        $validator->after(function ($v) use ($request, $pdl) {
            if ($v->errors()->hasAny(['first_name', 'middle_name', 'last_name', 'date_of_birth', 'admission_date'])) {
                return;
            }

            $fullName = Pdl::composeFullName($request->input('first_name'), $request->input('middle_name'), $request->input('last_name'));
            if (mb_strlen($fullName) > 150) {
                $v->errors()->add('last_name', 'The full name is too long (150 characters at most in total).');
            }

            $birth = \Carbon\Carbon::parse($request->input('date_of_birth'));
            if ($birth->diffInYears(\Carbon\Carbon::parse($request->input('admission_date'))) < 18) {
                $v->errors()->add('date_of_birth', 'A PDL must be at least 18 years old on the admission date.');
            }

            $duplicate = Pdl::where('full_name', $fullName)
                ->whereDate('date_of_birth', $birth->toDateString())
                ->when($pdl, fn ($q) => $q->where('pdl_id', '!=', $pdl->pdl_id))
                ->first();
            if ($duplicate) {
                $v->errors()->add('first_name', "A PDL with this name and date of birth is already registered ({$duplicate->pdl_number}).");
            }
        });

        return $validator->validate();
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