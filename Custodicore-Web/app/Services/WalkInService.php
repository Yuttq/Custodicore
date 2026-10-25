<?php

namespace App\Services;

use App\Http\Controllers\Api\VisitorPhotoController;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\EligibilityAssessment;
use App\Models\FacilityVisitationRule;
use App\Models\Module;
use App\Models\Pdl;
use App\Models\RelationshipDocument;
use App\Models\Role;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Models\VisitSession;
use App\Models\WalkInAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Walk-in visitors: people without a phone (or who can't use the app),
 * registered at the Front Desk. The registration creates exactly what the
 * app's does (AuthController::register): a Visitor account, a pending
 * profile, the government ID and the relationship documents. The PDL is only
 * the name the visitor gives; the Record Officer links the PDL, checks the
 * documents and approves in the Review Queue, where walk-ins come first.
 *
 * Each arrival gets a walk-in assessment (WalkInAssessment): the purpose of
 * the visit, then the Record Officer's checks and decision. Once Records has
 * approved and linked the visitor AND the decision is `approved`, the
 * Front Desk books a visit for today. It goes through the same checks as an
 * app request (VisitAssignmentService) and is confirmed straight away, since
 * the visitor is at the desk to confirm it.
 */
class WalkInService
{
    public function __construct(
        private readonly VisitAssignmentService $assignments,
        private readonly ScheduleAvailabilityService $availability,
    ) {}

    /**
     * @param  array{full_name:string,first_name:string,last_name:string,date_of_birth:string,gender:string,address?:?string,contact_number?:?string,email?:?string,
     *               guardian_full_name?:?string,guardian_relationship?:?string,guardian_contact_number?:?string,
     *               intended_pdl_name:string,intended_pdl_number?:?string,visit_purpose:string,relationship_type:string,has_children_together?:?bool,
     *               id_type?:?string,id_number?:?string}  $data
     * @param  array<string, UploadedFile>  $documents  relationship documents by requirement key
     */
    public function register(array $data, UploadedFile $photo, ?UploadedFile $governmentId, array $documents, int $staffId): VisitorProfile
    {
        // Written once, before the transaction: a rollback cannot remove files.
        $photoPath = VisitorPhotoController::storeFile($photo);
        $stored = [];

        try {
            return DB::transaction(function () use ($data, $photoPath, $governmentId, $documents, $staffId, &$stored) {
                $now = now();

                // No usable password: the visitor signs in only after claiming
                // the account with a password reset (needs an email on file).
                $account = Account::create([
                    'role_id' => Role::where('role_name', 'Visitor')->firstOrFail()->role_id,
                    'username' => $this->uniqueUsername($data['full_name']),
                    'email' => $data['email'] ?? null,
                    'password_hash' => Hash::make(Str::random(64)),
                    'status' => 'active',
                    // The officer confirms the visitor agreed at the desk.
                    'terms_accepted_at' => $now,
                    'privacy_accepted_at' => $now,
                    'consent_version' => (string) config('legal.version'),
                ]);

                $type = $data['relationship_type'];
                $hint = VisitorPdlRelationship::TYPE_LABELS[$type] ?? $type;
                if ($type === 'live_in_partner' && isset($data['has_children_together'])) {
                    $hint .= $data['has_children_together'] ? ' (has children together)' : ' (no children together)';
                }

                $profile = VisitorProfile::create([
                    'account_id' => $account->account_id,
                    'full_name' => $data['full_name'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'gender' => $data['gender'],
                    'address' => $data['address'] ?? null,
                    'contact_number' => ($data['contact_number'] ?? null) ?: 'N/A',
                    'guardian_full_name' => $data['guardian_full_name'] ?? null,
                    'guardian_relationship' => $data['guardian_relationship'] ?? null,
                    'guardian_contact_number' => $data['guardian_contact_number'] ?? null,
                    'relationship_hint' => $hint,
                    // Unverified, as in the app: the Record Officer links the PDL.
                    'intended_pdl_name' => $data['intended_pdl_name'],
                    'intended_pdl_number' => $data['intended_pdl_number'] ?? null,
                    'verification_status' => 'pending',
                    'registered_via' => 'walk_in',
                    'walk_in_registered_by' => $staffId,
                ]);

                if ($governmentId) {
                    $stored[] = $path = $governmentId->store('visitor-ids', VisitorId::DISK);
                    VisitorId::create([
                        'visitor_id' => $profile->visitor_id,
                        'id_type' => $data['id_type'],
                        'id_number' => $data['id_number'] ?: 'PENDING',
                        'file_path' => $path,
                        'verification_status' => 'pending',
                    ]);
                }

                // Kept on the visitor until the Record Officer links the PDL,
                // exactly like documents sent with an app registration.
                foreach ($documents as $key => $file) {
                    $stored[] = $path = $file->store('relationship-documents', 'local');
                    RelationshipDocument::create([
                        'relationship_id' => null,
                        'visitor_id' => $profile->visitor_id,
                        'requirement_key' => $key,
                        'file_path' => $path,
                        'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                        'status' => 'pending',
                        'uploaded_at' => $now,
                    ]);
                }

                $profile->update(['photo_path' => $photoPath, 'photo_uploaded_at' => $now]);

                WalkInAssessment::create([
                    'visitor_id' => $profile->visitor_id,
                    'visit_purpose' => $data['visit_purpose'],
                    'relationship_type' => $type,
                    'has_children_together' => $type === 'live_in_partner' && isset($data['has_children_together'])
                        ? (bool) $data['has_children_together']
                        : null,
                    'decision' => 'pending',
                ]);

                AuditLog::record('create', 'visitor_profiles', $profile->visitor_id,
                    "Front Desk registered walk-in visitor {$profile->full_name} (to visit \"{$profile->intended_pdl_name}\", "
                    .count($documents).' relationship document(s)'.($governmentId ? ', government ID' : '').')',
                    Module::CODE_VISITOR_MANAGEMENT);

                return $profile;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($photoPath);
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Where a walk-in stands, for the Front Desk. The label is one of the
     * five walk-in statuses (Pending Approval, Approved, Rejected, Checked
     * In, Checked Out); the key says why, for the page's explanation:
     *   waiting  — the Record Officer has not approved the account yet
     *   returned — rejected; rejection_reason says why
     *   declined — the Record Officer declined this walk-in (assessment)
     *   linking  — approved, but no verified relationship to a PDL yet
     *   assess   — approved and linked; waiting for the walk-in decision
     *   ready    — can be booked for today
     *   booked   — has a confirmed visit today, not checked in yet
     *   inside   — checked in now
     *   done     — today's visit is over
     *
     * @return array{key:string,label:string,tone:string,visit:?VisitRequest}
     */
    public function status(VisitorProfile $visitor): array
    {
        $visit = $this->todaysVisit($visitor);
        $decision = $this->assessment($visitor)?->decision;
        [$key, $label, $tone] = match (true) {
            $visitor->verification_status === 'rejected' => ['returned', 'Rejected', 'danger'],
            $decision === 'declined' && $visit === null => ['declined', 'Rejected', 'danger'],
            $visitor->verification_status !== 'verified' => ['waiting', 'Pending Approval', 'warning'],
            $visit && $visit->checkins->contains('status', 'checked_in') => ['inside', 'Checked In', 'info'],
            $visit && $visit->status === 'completed' => ['done', 'Checked Out', 'neutral'],
            $visit !== null => ['booked', 'Approved', 'success'],
            $this->verifiedRelationships($visitor)->isEmpty() => ['linking', 'Pending Approval', 'warning'],
            $decision !== 'approved' => ['assess', 'Pending Approval', 'warning'],
            default => ['ready', 'Approved', 'success'],
        };

        return compact('key', 'label', 'tone', 'visit');
    }

    /**
     * Today's sessions for each verified relationship, as the visitor
     * calendar sees them (availability, seats, weekly limit, ...).
     *
     * @return array<int, array{relationship:VisitorPdlRelationship, slots:array<int, array<string, mixed>>}>
     */
    public function todaysSessions(VisitorProfile $visitor): array
    {
        $relationships = $this->verifiedRelationships($visitor);
        $today = $this->availability->now()->startOfDay();
        $results = collect($this->availability->forRelationships($visitor, $relationships, $today, $today));

        return $relationships->map(fn (VisitorPdlRelationship $relationship, int $i) => [
            'relationship' => $relationship,
            'slots' => collect($results[$i]['days'][0]['slots'] ?? [])
                ->reject(fn ($slot) => in_array($slot['reason'], [ScheduleAvailabilityService::REASON_PAST, ScheduleAvailabilityService::REASON_ENDED], true))
                ->values()
                ->all(),
        ])->values()->all();
    }

    /**
     * Approved walk-ins the Record Officer still has to decide on (Review
     * Queue). Pending accounts are already in the queue's Accounts list.
     */
    public function awaitingDecision()
    {
        return VisitorProfile::where('registered_via', 'walk_in')
            ->where('verification_status', 'verified')
            ->whereHas('walkInAssessments', fn ($q) => $q->where('decision', 'pending')->whereNull('visit_request_id')
                ->whereRaw('walk_in_assessments.assessment_id = (select max(a.assessment_id) from walk_in_assessments a where a.visitor_id = walk_in_assessments.visitor_id)'))
            ->orderBy('created_at')
            ->get();
    }

    /** The current arrival's assessment (the newest). */
    public function assessment(VisitorProfile $visitor): ?WalkInAssessment
    {
        return $visitor->walkInAssessments()->with('processedBy')->first();
    }

    /**
     * The automatic part of the walk-in assessment, from the records:
     *   pdl_verification     verified when a PDL matches the name or ID number
     *                        the visitor gave (or one is already linked), else not_found;
     *   visitor_eligibility  verified when the account is approved, a government
     *                        ID is verified and no flag is active, else for_review;
     *   authorized_visitor   yes when a relationship to the PDL is verified (all
     *                        its documents checked), no when every one was
     *                        rejected, else for_review;
     *   files                every file the visitor gave, with its review status.
     * `blockers` lists what still stops an Approved decision.
     *
     * @return array<string, mixed>
     */
    public function checks(VisitorProfile $visitor): array
    {
        $visitor->loadMissing(['idDocuments', 'relationships.pdl']);
        $relationships = $visitor->relationships->reject(fn ($rel) => $rel->verification_status === 'rejected');
        $linkedPdls = $relationships->pluck('pdl')->filter();
        $matches = $this->matchingPdls($visitor)->reject(fn (Pdl $pdl) => $linkedPdls->contains('pdl_id', $pdl->pdl_id));

        $pdlVerification = $linkedPdls->isNotEmpty() || $matches->isNotEmpty() ? 'verified' : 'not_found';

        $flagged = $visitor->activeFlags()->exists();
        $eligibility = $visitor->verification_status === 'verified' && $visitor->hasVerifiedId() && ! $flagged
            ? 'verified' : 'for_review';

        $authorized = match (true) {
            $visitor->relationships->contains(fn ($rel) => $rel->isVerified()) => 'yes',
            $visitor->relationships->isNotEmpty() && $relationships->isEmpty() => 'no',
            default => 'for_review',
        };

        $documents = RelationshipDocument::where('visitor_id', $visitor->visitor_id)
            ->orWhereIn('relationship_id', $visitor->relationships->pluck('relationship_id'))
            ->with(['relationship.pdl', 'reviewedBy'])
            ->orderBy('uploaded_at')
            ->get();
        $pendingFiles = $visitor->idDocuments->where('verification_status', 'pending')->count()
            + $documents->where('status', 'pending')->count();

        $blockers = array_values(array_filter([
            $pdlVerification === 'not_found' ? 'No PDL matches the name or ID number given. Find the PDL and link it.' : null,
            $linkedPdls->isEmpty() && $matches->count() === 1 ? 'Verify every document: the matched PDL is then linked automatically.' : null,
            $linkedPdls->isEmpty() && $matches->count() > 1 ? 'Several PDLs match: link the right one.' : null,
            $visitor->verification_status !== 'verified' ? 'Approve the visitor\'s account (after verifying their ID).' : null,
            $flagged ? 'The visitor has an active flag.' : null,
            $pendingFiles > 0 ? "Review every file: {$pendingFiles} still waiting." : null,
            $authorized !== 'yes' && $linkedPdls->isNotEmpty() ? 'Verify the relationship to the PDL (all its documents must be verified).' : null,
        ]));

        return [
            'pdl_verification' => $pdlVerification,
            'visitor_eligibility' => $eligibility,
            'authorized_visitor' => $authorized,
            'linkedPdls' => $linkedPdls->values(),
            'matches' => $matches->values(),
            'flagged' => $flagged,
            'ids' => $visitor->idDocuments,
            'documents' => $documents,
            'pendingFiles' => $pendingFiles,
            'blockers' => $blockers,
            'canApprove' => $blockers === [],
        ];
    }

    /**
     * Reviews one of the walk-in's relationship documents. Unlike the
     * relationship checklist, it works before the PDL is linked, so Records
     * checks the documents first; then autoLink() links the PDL.
     */
    public function reviewDocument(VisitorProfile $visitor, RelationshipDocument $document, bool $verified, ?string $reason, int $staffId): ?string
    {
        $relationship = $document->relationship;
        if ((int) ($document->visitor_id ?? $relationship?->visitor_id) !== (int) $visitor->visitor_id) {
            abort(404);
        }
        if ($relationship?->verification_status === 'verified') {
            throw ValidationException::withMessages(['document' => 'This relationship is already verified, so its documents are locked.']);
        }
        if ($document->status !== 'pending') {
            throw ValidationException::withMessages(['document' => 'This document has already been reviewed.']);
        }

        $document->update([
            'status' => $verified ? 'verified' : 'rejected',
            'rejection_reason' => $verified ? null : $reason,
            'reviewed_by' => $staffId,
            'reviewed_at' => now(),
        ]);

        AuditLog::record('update', 'relationship_documents', $document->document_id,
            ($verified ? 'Verified' : 'Rejected')." {$document->label()} of walk-in {$visitor->full_name}"
            .($verified ? '' : ": {$reason}"),
            Module::CODE_VISITOR_MANAGEMENT);

        return $verified ? $this->autoLink($visitor, $staffId) : null;
    }

    /**
     * Run after each review step. Once every document for the relationship
     * the walk-in claimed is verified and exactly one PDL matches the name or
     * number they gave, links them to that PDL as that relationship. Then,
     * when the relationship's whole checklist is met (documents and valid
     * ID), verifies it. Returns what it did, or null.
     */
    public function autoLink(VisitorProfile $visitor, int $staffId): ?string
    {
        $assessment = $this->assessment($visitor);
        if (! $visitor->isWalkIn() || ! $assessment?->relationship_type || $assessment->visit_request_id) {
            return null;
        }

        $notes = [];
        $relationship = $visitor->relationships()->where('verification_status', '!=', 'rejected')->first();

        if (! $relationship) {
            $required = RelationshipRequirements::documentKeysFor($assessment->relationship_type, $assessment->has_children_together);
            if ($visitor->date_of_birth?->age < RelationshipRequirements::ADULT_AGE) {
                $required[] = RelationshipRequirements::MINOR_DOCUMENT;
            }
            $verifiedKeys = RelationshipDocument::where('visitor_id', $visitor->visitor_id)
                ->whereNull('relationship_id')
                ->where('status', 'verified')
                ->pluck('requirement_key')
                ->all();
            $matches = $this->matchingPdls($visitor);

            if (array_diff($required, $verifiedKeys) !== [] || $matches->count() !== 1) {
                return null;
            }

            $pdl = $matches->first();
            $relationship = VisitorPdlRelationship::create([
                'visitor_id' => $visitor->visitor_id,
                'pdl_id' => $pdl->pdl_id,
                'relationship_type' => $assessment->relationship_type,
                'has_children_together' => $assessment->has_children_together,
                'priority_tier' => VisitorPdlRelationship::priorityTierFor($assessment->relationship_type),
                'verification_status' => 'pending',
            ]);

            AuditLog::record('create', 'visitor_pdl_relationships', $relationship->relationship_id,
                "Automatically linked walk-in {$visitor->full_name} as {$relationship->relationshipLabel()} to {$pdl->full_name} ({$pdl->pdl_number}): documents verified, PDL matched",
                Module::CODE_VISITOR_MANAGEMENT);
            $notes[] = "Linked to {$pdl->full_name} as {$relationship->relationshipLabel()}.";
        }

        if ($relationship->verification_status === 'pending'
            && RelationshipRequirements::allMet(RelationshipRequirements::for($relationship->fresh()))) {
            $relationship->update(['verification_status' => 'verified', 'verified_by' => $staffId, 'verified_at' => now()]);

            AuditLog::record('update', 'visitor_pdl_relationships', $relationship->relationship_id,
                "Automatically verified relationship of walk-in {$visitor->full_name}: every requirement met",
                Module::CODE_VISITOR_MANAGEMENT);
            $notes[] = 'Relationship verified: every requirement is met.';
        }

        $visitor->unsetRelation('relationships');

        return $notes ? implode(' ', $notes) : null;
    }

    /**
     * PDLs matching what the visitor gave: the exact PDL ID number, or a name
     * containing every word of the given name (in the full name or alias).
     */
    public function matchingPdls(VisitorProfile $visitor)
    {
        $number = trim((string) $visitor->intended_pdl_number);
        $words = collect(preg_split('/\s+/u', mb_strtolower((string) $visitor->intended_pdl_name)))
            ->map(fn ($word) => trim($word, ".'’-"))
            ->filter(fn ($word) => mb_strlen($word) >= 2)
            ->values();

        if ($number === '' && $words->isEmpty()) {
            return collect();
        }

        return Pdl::query()
            ->where(function ($q) use ($number, $words) {
                if ($number !== '') {
                    $q->orWhereRaw('LOWER(pdl_number) = ?', [mb_strtolower($number)]);
                }
                if ($words->isNotEmpty()) {
                    $q->orWhere(fn ($name) => $words->each(fn ($word) => $name->whereRaw('LOWER(full_name) LIKE ?', ['%'.addcslashes($word, '%_\\').'%'])))
                        ->orWhere(fn ($alias) => $words->each(fn ($word) => $alias->whereRaw('LOWER(alias) LIKE ?', ['%'.addcslashes($word, '%_\\').'%'])));
                }
            })
            ->orderBy('full_name')
            ->limit(5)
            ->get();
    }

    /**
     * Records the Record Officer's decision on the current arrival. The
     * checks are filled in by the system (checks()) and saved with it.
     * Approved is refused while any check still blocks it.
     *
     * @param  array{decision:string,walk_in_reason:string,remarks:?string}  $data
     */
    public function assess(VisitorProfile $visitor, array $data, int $staffId): WalkInAssessment
    {
        $assessment = $this->assessment($visitor)
            ?? throw ValidationException::withMessages(['decision' => 'This visitor has no walk-in registration to assess.']);

        if ($assessment->visit_request_id !== null) {
            throw ValidationException::withMessages(['decision' => 'A visit was already booked for this walk-in.']);
        }

        $checks = $this->checks($visitor);
        if ($data['decision'] === 'approved' && ! $checks['canApprove']) {
            throw ValidationException::withMessages([
                'decision' => 'The walk-in cannot be approved yet: '.implode(' ', $checks['blockers']),
            ]);
        }

        $assessment->update($data + collect($checks)->only(WalkInAssessment::AUTOMATIC)->all() + [
            'processed_by' => $staffId,
            'processed_at' => now(),
        ]);

        AuditLog::record('update', 'walk_in_assessments', $assessment->assessment_id,
            "Record Officer assessed walk-in {$visitor->full_name}: {$assessment->label('decision')}"
            .($assessment->remarks ? " ({$assessment->remarks})" : ''),
            Module::CODE_VISITOR_MANAGEMENT);

        return $assessment;
    }

    /**
     * Books today's visit at the desk: the same request and approval as a
     * visitor-booked visit (every rule and the eligibility check apply), in
     * one step, marked as a walk-in. Nothing is kept if any check fails.
     *
     * @param  array<int, string>  $startTimes
     */
    public function bookVisit(VisitorProfile $visitor, VisitorPdlRelationship $relationship, array $startTimes): VisitRequest
    {
        $assessment = $this->assessment($visitor);
        if ($assessment?->decision !== 'approved') {
            throw ValidationException::withMessages(['start_times' => 'The Records Officer has not approved this walk-in yet.']);
        }

        return DB::transaction(function () use ($visitor, $relationship, $startTimes, $assessment) {
            $today = CarbonImmutable::parse($this->availability->now()->toDateString());

            $visit = $this->assignments->submitVisitorRequest($visitor, $relationship, $today, $startTimes);
            $visit = $this->assignments->approveVisitorRequest($visit);
            $visit->update(['is_walk_in' => true]);
            $assessment->update(['visit_request_id' => $visit->visit_request_id]);

            AuditLog::record('update', 'visit_requests', $visit->visit_request_id,
                "Booked walk-in visit for {$visitor->full_name} → {$visit->pdl->full_name}",
                Module::CODE_VISIT_SCHEDULING);

            return $visit;
        });
    }

    /**
     * Right after Records approves the walk-in, so the Front Desk only has
     * to check the visitor in: books the next regular session today that
     * still has seats, or else, since the visitor is already at the
     * facility, a walk-in session today (walkInSession()), even on a day
     * without visiting for the PDL. Null when they already have a visit today
     * or today's visiting hours are over.
     */
    public function autoBook(VisitorProfile $visitor): ?VisitRequest
    {
        if ($this->todaysVisit($visitor)) {
            return null;
        }

        foreach ($this->todaysSessions($visitor) as $entry) {
            $slot = collect($entry['slots'])->where('available', true)->sortBy('startTime')->first();
            if (! $slot) {
                continue;
            }

            try {
                return $this->bookVisit($visitor, $entry['relationship'], [$slot['startTime']]);
            } catch (ValidationException) {
                // Filled or closed meanwhile: fall back to a walk-in session.
            }
        }

        $relationship = $this->verifiedRelationships($visitor)->first();

        return $relationship ? $this->walkInSession($visitor, $relationship) : null;
    }

    /**
     * A visit today outside the regular visiting days/seats, for a walk-in
     * Records approved: the PDL's next session today that has not ended,
     * with the usual times of that session (from the PDL's own rules, any
     * weekday). The dated session is not a special visiting date, so it does
     * not open the day for app bookings. The PDL must still be in active
     * custody without restrictions. Null after today's last session.
     */
    private function walkInSession(VisitorProfile $visitor, VisitorPdlRelationship $relationship): ?VisitRequest
    {
        $pdl = $relationship->pdl()->with('activeRestrictions')->first();
        if (! $pdl || $pdl->custody_status !== 'active' || $pdl->activeRestrictions->isNotEmpty()) {
            return null;
        }

        $now = $this->availability->now();
        $today = $now->toDateString();
        $rule = FacilityVisitationRule::rulesForPdl($pdl)
            ->filter(fn (FacilityVisitationRule $rule) => $rule->isInEffectOn($today))
            ->sortBy(fn (FacilityVisitationRule $rule) => (string) $rule->time_slot_start)
            ->first(fn (FacilityVisitationRule $rule) => ! $this->availability->sessionHasEnded($today, $rule->time_slot_end, $now));
        if (! $rule) {
            return null;
        }

        $assessment = $this->assessment($visitor);

        return DB::transaction(function () use ($visitor, $relationship, $pdl, $rule, $today, $assessment) {
            $schedule = VisitSchedule::where('rule_id', $rule->rule_id)
                ->whereDate('schedule_date', $today)
                ->where('time_slot_start', $rule->time_slot_start)
                ->lockForUpdate()
                ->first()
                ?? VisitSchedule::create([
                    'rule_id' => $rule->rule_id,
                    'schedule_date' => $today,
                    'time_slot_start' => $rule->time_slot_start,
                    'time_slot_end' => $rule->time_slot_end,
                    'max_capacity' => $rule->max_capacity,
                    'slots_taken' => 0,
                    // Walk-ins only: closed to bookings (and never a special visiting date).
                    'status' => 'closed',
                ]);

            $visit = VisitRequest::create([
                'visitor_id' => $visitor->visitor_id,
                'pdl_id' => $pdl->pdl_id,
                'relationship_id' => $relationship->relationship_id,
                'schedule_id' => $schedule->schedule_id,
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'is_walk_in' => true,
            ]);
            VisitSession::firstOrCreate(['visit_request_id' => $visit->visit_request_id, 'schedule_id' => $schedule->schedule_id]);

            EligibilityAssessment::assessFor($visit->fresh(['visitor.idDocuments', 'visitor.flags', 'pdl.restrictions', 'relationship']));
            $assessment?->update(['visit_request_id' => $visit->visit_request_id]);

            AuditLog::record('create', 'visit_requests', $visit->visit_request_id,
                "Walk-in visit for {$visitor->full_name} → {$pdl->full_name} today "
                .substr((string) $rule->time_slot_start, 0, 5).'–'.substr((string) $rule->time_slot_end, 0, 5)
                .' (approved by Records; outside the regular visiting schedule)',
                Module::CODE_VISIT_SCHEDULING);

            return $visit->fresh(['pdl', 'sessionSchedules']);
        });
    }

    public function todaysVisit(VisitorProfile $visitor): ?VisitRequest
    {
        return VisitRequest::where('visitor_id', $visitor->visitor_id)
            ->whereIn('status', ['confirmed', 'completed'])
            ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', today()))
            ->with(['pdl', 'checkins', 'sessionSchedules'])
            ->latest('visit_request_id')
            ->first();
    }

    private function verifiedRelationships(VisitorProfile $visitor)
    {
        return $visitor->relationships()->with('pdl')->get()
            ->filter(fn (VisitorPdlRelationship $relationship) => $relationship->isVerified())
            ->values();
    }

    /** "walkin.maria.santos", numbered when taken (accounts.username is 50 characters). */
    private function uniqueUsername(string $fullName): string
    {
        $base = 'walkin.'.Str::limit(Str::slug($fullName, '.'), 36, '');
        $username = $base;
        $suffix = 1;

        while (Account::where('username', $username)->exists()) {
            $username = $base.$suffix++;
        }

        return $username;
    }
}
