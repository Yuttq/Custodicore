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
use App\Support\Offenses;
use App\Support\Sanitizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PdlController extends Controller
{
    // PDL photo: a real picture (checked from the file's content, not just
    // its name), JPG/PNG/WEBP, at most 5 MB.
    private const PHOTO_RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

    // registered_by / recorded_by / imposed_by: the signed-in officer.
    use Concerns\ResolvesActingStaff;

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
        $query = Sanitizer::text((string) $request->input('q', '')) ?? '';
        $status = trim((string) $request->input('status', ''));
        $cellBlock = trim((string) $request->input('cell', ''));
        $offense = in_array($request->input('offense'), Offenses::codes(), true) ? $request->input('offense') : '';
        // Replaces the old Visitation Tracking page.
        $visits = in_array($request->input('visits'), ['stale', 'never'], true) ? $request->input('visits') : '';

        // Last actual visit = latest front-desk check-in for this PDL.
        $checkins = fn () => \Illuminate\Support\Facades\DB::table('visit_checkins as vc')
            ->join('visit_requests as vr', 'vr.visit_request_id', '=', 'vc.visit_request_id')
            ->whereColumn('vr.pdl_id', 'pdl_profiles.pdl_id')
            ->whereNotNull('vc.check_in_time');

        $pdls = Pdl::query()
            ->select('pdl_profiles.*')
            ->addSelect(['last_visit_at' => $checkins()->selectRaw('MAX(vc.check_in_time)')])
            ->when($query !== '', function ($q) use ($query) {
                $like = Sanitizer::like($query);
                $q->where(function ($q2) use ($like) {
                    $q2->where('full_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('alias', 'like', $like)
                        ->orWhere('pdl_number', 'like', $like);
                });
            })
            ->when($status !== '', fn ($q) => $q->where('custody_status', $status))
            ->when($cellBlock !== '', fn ($q) => $q->where('cell_block', $cellBlock))
            ->when($offense !== '', fn ($q) => $q->whereHas('legalRecords', fn ($r) => $r->where('offense_code', $offense)))
            // Visit filters only make sense for PDLs in custody.
            ->when($visits === 'never', fn ($q) => $q->where('custody_status', 'active')->whereNotExists($checkins()->select(\Illuminate\Support\Facades\DB::raw(1))))
            ->when($visits === 'stale', fn ($q) => $q->where('custody_status', 'active')
                ->whereNotExists($checkins()->where('vc.check_in_time', '>=', now()->subDays(30))->select(\Illuminate\Support\Facades\DB::raw(1))))
            ->with(['legalRecords', 'restrictions'])
            ->orderBy('admission_date', 'desc')
            ->paginate(10)
            ->withQueryString();

        $cellBlocks = CellBlock::orderBy('name')->pluck('name');

        // Population = PDLs currently in custody. Released, transferred and
        // deceased PDLs drop out of it (they keep their own counts below).
        $stats = [
            'total_population' => Pdl::where('custody_status', 'active')->count(),
            'transferred' => Pdl::where('custody_status', 'transferred')->count(),
            'deceased' => Pdl::where('custody_status', 'deceased')->count(),
            'released_ytd' => Pdl::where('custody_status', 'released')
                ->whereYear('updated_at', now()->year)
                ->count(),
        ];

        // Every weekly visiting rule, loaded once for the "Visiting" column.
        $visitingRules = \App\Models\FacilityVisitationRule::orderBy('time_slot_start')->get();

        return view('pdl.index', compact('pdls', 'query', 'status', 'cellBlock', 'cellBlocks', 'stats', 'offense', 'visits', 'visitingRules'));
    }

    // -----------------------------------------------------------------
    // INPUT CLEANING (shared by register and edit; the duplicate-person
    // check lives in validateProfile())
    // -----------------------------------------------------------------

    private const CASE_MESSAGES = [
        'case_number.required' => 'Enter the case number.',
        'case_number.regex' => 'Case number may only contain letters, numbers, spaces, dots, slashes and dashes.',
        'case_number.unique' => 'This case number is already on file for this PDL.',
        'offense_code.required' => 'Choose the offense from the list (or Other).',
        'offense_code.in' => 'Choose the offense from the list (or Other).',
        'offense_other.required_if' => 'Type the offense when "Other" is chosen.',
        'case_status.required' => 'Choose the case status.',
        'case_status.in' => 'Choose the case status from the list.',
        'date_filed.before_or_equal' => 'The date filed cannot be in the future.',
        'next_hearing_at.after_or_equal' => 'The next hearing must be today or later.',
        'convicted_on.required' => 'Enter the date of conviction.',
        'convicted_on.before_or_equal' => 'The date of conviction cannot be in the future.',
        'sentence_years.max' => 'Sentence years must be 99 or less.',
        'sentence_months.max' => 'Sentence months must be 0 to 11.',
        'sentence_days.max' => 'Sentence days must be 0 to 30.',
    ];

    /** Case number + offense (picked from App\Support\Offenses, or Other with own wording) + court. */
    private function caseRules(?Pdl $pdl = null): array
    {
        $caseNumber = ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\/\-]*$/'];
        if ($pdl) {
            // The same case can't be filed twice on one PDL.
            $caseNumber[] = Rule::unique('pdl_legal_records', 'case_number')->where('pdl_id', $pdl->pdl_id);
        }

        return [
            'case_number' => $caseNumber,
            'offense_code' => ['required', Rule::in(Offenses::codes())],
            'offense_other' => ['nullable', 'required_if:offense_code,' . Offenses::OTHER, 'string', 'min:3', 'max:255'],
            'court' => ['nullable', 'string', 'max:150'],
        ];
    }

    private function cleanCaseInput(Request $request): void
    {
        $request->merge([
            'case_number' => mb_strtoupper((string) Sanitizer::text($request->input('case_number'))) ?: null,
            'offense_other' => Sanitizer::text($request->input('offense_other')),
            'court' => Sanitizer::text($request->input('court')),
            'hearing_notes' => Sanitizer::text($request->input('hearing_notes')),
        ]);
    }

    /** Validated case input → pdl_legal_records columns. */
    private function caseAttributes(array $validated): array
    {
        $code = $validated['offense_code'];

        return [
            'case_number' => $validated['case_number'],
            'offense_code' => $code,
            'offense' => $code === Offenses::OTHER ? $validated['offense_other'] : Offenses::label($code),
            'court' => $validated['court'] ?? null,
        ];
    }

    /**
     * Case status, date filed, next court hearing and (when convicted) the
     * conviction date and sentence. Shared by Register PDL, Add Legal Record
     * and Update Legal Record.
     */
    private function caseDetailRules(Request $request): array
    {
        $convicted = in_array($request->input('case_status'), PdlLegalRecord::CONVICTED_STATUSES, true);

        return [
            // Missing means a new case: Pending trial (the forms always send one).
            'case_status' => ['nullable', Rule::in(PdlLegalRecord::STATUSES)],
            'date_filed' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'next_hearing_at' => ['nullable', 'date', 'after_or_equal:today'],
            'hearing_notes' => ['nullable', 'string', 'max:255'],
            'convicted_on' => [Rule::requiredIf($convicted), 'nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'sentence_years' => ['nullable', 'integer', 'min:0', 'max:99'],
            'sentence_months' => ['nullable', 'integer', 'min:0', 'max:11'],
            'sentence_days' => ['nullable', 'integer', 'min:0', 'max:30'],
        ];
    }

    /** Checks that need more than one field: a conviction needs a sentence and can't precede the filing. */
    private function checkCaseDetails(array $validated): void
    {
        if (! in_array($validated['case_status'] ?? null, PdlLegalRecord::CONVICTED_STATUSES, true)) {
            return;
        }
        if (((int) ($validated['sentence_years'] ?? 0) + (int) ($validated['sentence_months'] ?? 0) + (int) ($validated['sentence_days'] ?? 0)) === 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sentence_years' => 'Enter the sentence (years, months and/or days).',
            ]);
        }
        if (! empty($validated['date_filed']) && $validated['convicted_on'] < $validated['date_filed']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'convicted_on' => 'The date of conviction cannot be before the date the case was filed.',
            ]);
        }
    }

    /** Validated details → pdl_legal_records columns (conviction cleared unless convicted). */
    private function caseDetailAttributes(array $validated): array
    {
        $status = $validated['case_status'] ?? PdlLegalRecord::PENDING;
        $convicted = in_array($status, PdlLegalRecord::CONVICTED_STATUSES, true);

        return [
            'case_status' => $status,
            'date_filed' => $validated['date_filed'] ?? null,
            'convicted_on' => $convicted ? $validated['convicted_on'] : null,
            'sentence_years' => $convicted ? (int) ($validated['sentence_years'] ?? 0) : null,
            'sentence_months' => $convicted ? (int) ($validated['sentence_months'] ?? 0) : null,
            'sentence_days' => $convicted ? (int) ($validated['sentence_days'] ?? 0) : null,
        ];
    }

    /** Records the next hearing, if one was entered and the case is still open. */
    private function addHearing(PdlLegalRecord $record, array $validated, int $staffId): ?\App\Models\CourtHearing
    {
        if (empty($validated['next_hearing_at']) || $record->isClosed()) {
            return null;
        }

        return $record->hearings()->firstOrCreate(
            ['hearing_at' => \Illuminate\Support\Carbon::parse($validated['next_hearing_at'])],
            ['notes' => $validated['hearing_notes'] ?? null, 'recorded_by' => $staffId]
        );
    }

    /** Cleans names/alias/cell before validation so "JUAN  dela cruz " is stored as "Juan Dela Cruz". */
    private function cleanPdlInput(Request $request): void
    {
        $request->merge([
            'first_name' => Sanitizer::name($request->input('first_name')),
            'middle_name' => Sanitizer::name($request->input('middle_name')),
            'last_name' => Sanitizer::name($request->input('last_name')),
            'alias' => Sanitizer::text($request->input('alias')),
            'cell_block' => Sanitizer::text($request->input('cell_block')),
        ]);
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
        $this->cleanPdlInput($request);

        $this->cleanCaseInput($request);

        $validated = $this->validateProfile($request, [
            'cell_block' => ['required', 'string', 'max:50', $this->cellBlockRule($request->input('gender'))],
            'photo' => ['required', ...self::PHOTO_RULES],
            // The case they're detained for; it becomes their first legal record.
            ...$this->caseRules(),
            ...$this->caseDetailRules($request),
        ]);
        $this->checkCaseDetails($validated);
        $case = [...$this->caseAttributes($validated), ...$this->caseDetailAttributes($validated)];
        $caseInput = $validated;
        $validated = array_diff_key($validated, array_flip(['photo', 'case_number', 'offense_code', 'offense_other', 'court',
            ...array_keys($this->caseDetailRules($request))]));

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return redirect()->back()->withInput()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $photoPath = $request->file('photo')->store('pdl-photos', 'local');

        try {
            $pdl = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $photoPath, $case, $caseInput, $staffId) {
                $pdl = Pdl::create([
                    ...$validated,
                    // Visiting-day group follows the offense (RA 9165 = drug-related).
                    'classification' => Offenses::isDrug($case['offense_code']) ? 'drug_related' : 'non_drug_related',
                    'photo_path' => $photoPath,
                    'pdl_number' => 'PDL-' . now()->format('Y') . '-' . str_pad((string) (Pdl::max('pdl_id') + 1), 5, '0', STR_PAD_LEFT),
                    'custody_status' => 'active',
                    'registered_by' => $staffId,
                ]);

                $record = PdlLegalRecord::create([
                    ...$case,
                    'pdl_id' => $pdl->pdl_id,
                    'recorded_by' => $staffId,
                ]);
                $this->addHearing($record, $caseInput, $staffId);

                return $pdl;
            });
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
        $pdl->load(['legalRecords.hearings', 'disciplinaryRecords', 'restrictions', 'registeredBy']);

        // Visitation eligibility status, shown on the PDL's own page — this
        // is PDL-side information (is *this PDL* currently clear to
        // receive visitors), as opposed to visitor-side verification
        // (identity/relationship/history), which stays on the Visitor's
        // own page in Visitor Management and isn't duplicated here.
        $eligibilityStatus = $this->computeEligibilityStatus($pdl);
        $cellBlocks = $this->assignableCellBlocks($pdl->cell_block);

        // When this PDL can be visited: their dorm's schedule, or the
        // classification days when the dorm has none (24-hour times).
        $visiting = \App\Services\PdlVisitingAvailability::for($pdl);

        $custodyHistory = \App\Services\ActivityLog::forPdl($pdl);

        return view('pdl.show', compact('pdl', 'eligibilityStatus', 'cellBlocks', 'visiting', 'custodyHistory'));
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
        $this->cleanPdlInput($request);

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
        $this->cleanCaseInput($request);
        $request->merge([
            'remarks' => Sanitizer::paragraph($request->input('remarks')),
        ]);

        $validated = $request->validate([
            ...$this->caseRules($pdl),
            ...$this->caseDetailRules($request),
            'remarks' => ['nullable', 'string', 'max:2000'],
        ], self::CASE_MESSAGES);
        $this->checkCaseDetails($validated);

        $previousClassification = $pdl->classification;

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return redirect()->back()->withInput()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        try {
            $record = PdlLegalRecord::create([
                ...$this->caseAttributes($validated),
                ...$this->caseDetailAttributes($validated),
                'remarks' => $validated['remarks'] ?? null,
                'pdl_id' => $pdl->pdl_id,
                'recorded_by' => $staffId,
            ]);
            $this->addHearing($record, $validated, $staffId);
            $classificationChanged = $pdl->refreshClassification();
        } catch (\Throwable $e) {
            Log::error('Legal record creation failed: ' . $e->getMessage());
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Could not add this legal record. ' . $this->friendlyMessage($e));
        }

        $this->logAudit('create', 'pdl_legal_records', $record->legal_record_id,
            "Added legal record {$record->case_number} ({$record->offense}) for {$pdl->full_name}");

        if ($classificationChanged) {
            $this->logAudit('update', 'pdl_profiles', $pdl->pdl_id,
                "Classification of {$pdl->full_name} changed from " . str_replace('_', '-', $previousClassification)
                . ' to ' . str_replace('_', '-', $pdl->classification) . " because of case {$record->case_number}");

            return redirect()->route('pdl.show', $pdl->pdl_id)->with('success',
                "Legal record added. {$pdl->full_name} is now classified as {$pdl->classificationLabel()}, so their visiting days have changed.");
        }

        return redirect()->route('pdl.show', $pdl->pdl_id)->with('success', 'Legal record added.');
    }

    // -----------------------------------------------------------------
    // Update a legal record: status, date filed, next hearing, conviction
    // and remarks. The case number and offense stay as filed.
    // -----------------------------------------------------------------
    public function updateLegalRecord(Request $request, Pdl $pdl, PdlLegalRecord $record)
    {
        abort_unless((int) $record->pdl_id === (int) $pdl->pdl_id, 404);

        $request->merge([
            'remarks' => Sanitizer::paragraph($request->input('remarks')),
            'hearing_notes' => Sanitizer::text($request->input('hearing_notes')),
        ]);
        $validated = $request->validate([
            ...$this->caseDetailRules($request),
            'remarks' => ['nullable', 'string', 'max:2000'],
        ], self::CASE_MESSAGES);
        $this->checkCaseDetails($validated);

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return redirect()->back()->withInput()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $before = [
            'status' => $record->case_status,
            'filed' => $record->date_filed?->toDateString(),
            'conviction' => $record->convicted_on?->toDateString() . '|' . $record->sentenceLabel(),
            'remarks' => $record->remarks,
        ];

        try {
            $hearing = \Illuminate\Support\Facades\DB::transaction(function () use ($record, $validated, $staffId) {
                $record->update([...$this->caseDetailAttributes($validated), 'remarks' => $validated['remarks'] ?? null]);

                return $this->addHearing($record, $validated, $staffId);
            });
        } catch (\Throwable $e) {
            Log::error('Legal record update failed: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Could not update this legal record. ' . $this->friendlyMessage($e));
        }

        // What changed, in words, for the activity log.
        $record->refresh();
        $changes = [];
        if ($before['status'] !== $record->case_status) {
            $changes[] = 'status ' . ($before['status'] ?? 'none') . ' → ' . $record->case_status;
        }
        if ($before['filed'] !== $record->date_filed?->toDateString()) {
            $changes[] = 'date filed ' . ($record->date_filed?->format('M j, Y') ?? 'cleared');
        }
        if ($before['conviction'] !== $record->convicted_on?->toDateString() . '|' . $record->sentenceLabel()) {
            $changes[] = $record->isConvicted()
                ? 'convicted ' . $record->convicted_on->format('M j, Y') . ', sentence ' . $record->sentenceLabel()
                : 'conviction cleared';
        }
        if ($hearing && $hearing->wasRecentlyCreated) {
            $changes[] = 'next hearing ' . $hearing->hearing_at->format('M j, Y H:i');
        }
        if ($before['remarks'] !== $record->remarks) {
            $changes[] = 'remarks updated';
        }

        if ($changes === []) {
            return redirect()->route('pdl.show', $pdl->pdl_id)->with('success', 'No changes to save.');
        }

        $this->logAudit('update', 'pdl_legal_records', $record->legal_record_id,
            "Updated legal record {$record->case_number} for {$pdl->full_name}: " . implode('; ', $changes));

        return redirect()->route('pdl.show', $pdl->pdl_id)->with('success', "Legal record {$record->case_number} updated.");
    }

    // -----------------------------------------------------------------
    // Add a disciplinary record
    // -----------------------------------------------------------------
    public function storeDisciplinaryRecord(Request $request, Pdl $pdl)
    {
        $request->merge([
            'description' => Sanitizer::paragraph($request->input('description')),
            'action_taken' => Sanitizer::text($request->input('action_taken')),
        ]);

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

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return redirect()->back()->withInput()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        try {
            $record = PdlDisciplinaryRecord::create([
                ...$validated,
                'pdl_id' => $pdl->pdl_id,
                'recorded_by' => $staffId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Disciplinary record creation failed: ' . $e->getMessage());
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Could not add this disciplinary record. ' . $this->friendlyMessage($e));
        }

        $this->logAudit('create', 'pdl_disciplinary_records', $record->getKey(),
            "Added disciplinary record for {$pdl->full_name} (incident on {$record->incident_date->format('M j, Y')})");

        return redirect()->route('pdl.show', $pdl->pdl_id)->with('success', 'Disciplinary record added.');
    }

    // -----------------------------------------------------------------
    // Add a restriction
    // -----------------------------------------------------------------
    public function storeRestriction(Request $request, Pdl $pdl)
    {
        $request->merge(['reason' => Sanitizer::text($request->input('reason'))]);

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

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return redirect()->back()->withInput()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        try {
            $restriction = PdlRestriction::create([
                ...$validated,
                'pdl_id' => $pdl->pdl_id,
                'status' => 'active',
                'imposed_by' => $staffId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Restriction creation failed: ' . $e->getMessage());
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Could not add this restriction. ' . $this->friendlyMessage($e));
        }

        $this->logAudit('create', 'pdl_restrictions', $restriction->restriction_id,
            'Added ' . str_replace('_', ' ', $restriction->restriction_type) . " restriction for {$pdl->full_name}: {$restriction->reason}");

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

        $this->logAudit('update', 'pdl_restrictions', $restriction->restriction_id,
            'Lifted ' . str_replace('_', ' ', $restriction->restriction_type) . " restriction for {$pdl->full_name}");

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
            'admission_date' => ['required', 'date', 'before_or_equal:today', 'after:date_of_birth'],
            ...$extra,
        ], [
            ...self::CASE_MESSAGES,
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
            ->with('visitationRules')
            ->where(fn ($q) => $q->where('is_active', true)
                ->when($currentBlock, fn ($q2) => $q2->orWhere('name', $currentBlock)))
            ->orderBy('name')
            ->get();
    }

    /**
     * The chosen block must exist, be active and match the PDL's gender. On
     * edit, a PDL staying in their current block is left alone. Capacity is
     * only a recommendation (dorms are overpopulated in practice), so a block
     * at or over capacity can still be chosen; the screens highlight it.
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
            return $e->getMessage(); // these are our own deliberate messages, safe to show as-is
        }

        return 'An unexpected error occurred. Please try again.';
    }
}