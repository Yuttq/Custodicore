<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\QrCode;
use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Services\RelationshipRequirements;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Backs the mobile app's visit-related calls in src/services/api.js.
 *
 * Naming note: the mobile app calls its route param "scheduleId" throughout
 * (confirmSchedule(scheduleId), getQrToken(scheduleId), getTimeline(scheduleId))
 * — that's a holdover from before this schema existed. Every one of those
 * calls is really about ONE booked visit, so on this side {id} is always a
 * visit_requests.visit_request_id, not a visit_schedules.schedule_id. Worth
 * renaming on the mobile side later for clarity, but not required for this
 * to work correctly.
 */
class VisitorApiController extends Controller
{
    private function currentVisitor(Request $request): VisitorProfile
    {
        $profile = $request->user()?->visitorProfile;

        abort_unless($profile, 403, 'This account has no visitor profile.');

        return $profile;
    }

    public function upcoming(Request $request): JsonResponse
    {
        $visitor = $this->currentVisitor($request);

        // Earliest visit dated today or later (same rule as the mobile
        // pickNextVisit). A past visit still awaiting `visits:expire` is skipped.
        $visit = VisitRequest::query()
            ->select('visit_requests.*')
            ->join('visit_schedules', 'visit_schedules.schedule_id', '=', 'visit_requests.schedule_id')
            ->where('visit_requests.visitor_id', $visitor->visitor_id)
            ->whereIn('visit_requests.status', ['assigned', 'pending_confirmation', 'confirmed'])
            ->whereDate('visit_schedules.schedule_date', '>=', today()->toDateString())
            ->with(['pdl', 'schedule', 'sessionSchedules'])
            ->orderBy('visit_schedules.schedule_date')
            ->orderBy('visit_schedules.time_slot_start')
            ->orderBy('visit_requests.visit_request_id')
            ->first();

        if (! $visit) {
            return response()->json(['visit' => null], 200);
        }

        return response()->json($this->visitPayload($visit));
    }

    /**
     * Full visit list for the authenticated visitor (mobile visit cards).
     */
    public function index(Request $request): JsonResponse
    {
        $visitor = $this->currentVisitor($request);

        $visits = VisitRequest::where('visitor_id', $visitor->visitor_id)
            ->with(['pdl', 'schedule', 'sessionSchedules'])
            ->orderByDesc('assigned_at')
            ->get()
            ->map(fn ($v) => $this->visitPayload($v));

        return response()->json(['visits' => $visits->values()]);
    }

    /** Final outcomes shown in the mobile Visitation History screen. */
    public const HISTORY_STATUSES = ['completed', 'declined', 'cancelled', 'no_show'];

    /**
     * Past visits only (final statuses). Active visits come from index().
     */
    public function history(Request $request): JsonResponse
    {
        $visitor = $this->currentVisitor($request);

        $visits = VisitRequest::where('visitor_id', $visitor->visitor_id)
            ->whereIn('status', self::HISTORY_STATUSES)
            ->with(['pdl', 'schedule', 'sessionSchedules'])
            ->orderByDesc('assigned_at')
            ->get()
            ->map(fn ($v) => $this->visitPayload($v));

        return response()->json(['visits' => $visits->values()]);
    }

    /**
     * POST /api/visit-requests — the visitor requests one visit for a
     * verified PDL relationship: one day and one or more of its sessions —
     * `startTime` for a single session, or `startTimes` (e.g. ["09:00",
     * "13:00"]) for several, which form ONE visit (whole day). Created as
     * `assigned` (awaiting Record Officer review); the visitor cannot
     * confirm it themselves.
     */
    public function storeVisitRequest(Request $request, VisitAssignmentService $assignments, ScheduleAvailabilityService $availability): JsonResponse
    {
        $visitor = $this->currentVisitor($request);

        $data = $request->validate([
            'relationshipId' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'startTime' => ['required_without:startTimes', 'nullable', 'date_format:H:i'],
            'startTimes' => ['required_without:startTime', 'nullable', 'array', 'min:1', 'max:4'],
            'startTimes.*' => ['date_format:H:i', 'distinct'],
        ]);

        // Only the visitor's own relationship — anyone else's is "not found".
        $relationship = VisitorPdlRelationship::where('relationship_id', $data['relationshipId'])
            ->where('visitor_id', $visitor->visitor_id)
            ->first();
        abort_unless($relationship, 404, 'Relationship not found.');

        $visitRequest = $assignments->submitVisitorRequest(
            $visitor,
            $relationship,
            CarbonImmutable::createFromFormat('Y-m-d', $data['date'], $availability->timezone())->startOfDay(),
            $data['startTimes'] ?? $data['startTime']
        );

        return response()->json($this->visitPayload($visitRequest), 201);
    }

    /**
     * Visitor confirms a staff-assigned visit (pending_confirmation only).
     * `assigned` is a visitor-submitted request awaiting staff review and can
     * only be approved by a Record Officer.
     */
    public function confirm(Request $request, VisitRequest $visitRequest, VisitAssignmentService $assignments): JsonResponse
    {
        $this->authorizeOwnership($request, $visitRequest);

        return response()->json($this->visitPayload($assignments->confirmAssignedVisit($visitRequest)));
    }

    /**
     * `declined` is only for a staff-assigned visit; a request awaiting
     * staff review (`assigned`) is approved or rejected by staff.
     */
    public function decline(Request $request, VisitRequest $visitRequest, VisitAssignmentService $assignments): JsonResponse
    {
        $this->authorizeOwnership($request, $visitRequest);

        return response()->json($this->visitPayload($assignments->declineAssignedVisit(
            $visitRequest,
            $request->input('reason', 'Visitor unable to attend (declined via mobile app)')
        )));
    }

    public function qr(Request $request, VisitRequest $visitRequest): JsonResponse
    {
        $this->authorizeOwnership($request, $visitRequest);

        $status = $visitRequest->status;

        if ($status === 'assigned') {
            return response()->json([
                'message' => 'Your visit request is awaiting review by the facility. A QR pass is issued once it is approved.',
                'status' => $status,
            ], 409);
        }

        if ($status === 'pending_confirmation') {
            return response()->json([
                'message' => 'Confirm your visit attendance before a QR pass can be issued.',
                'status' => $status,
            ], 409);
        }

        if (in_array($status, ['declined', 'cancelled', 'completed', 'no_show'], true)) {
            return response()->json([
                'message' => 'A QR pass is not available for this visit.',
                'status' => $status,
            ], 409);
        }

        if ($status !== 'confirmed') {
            return response()->json([
                'message' => 'A QR pass is only available once your visit is confirmed.',
                'status' => $status,
            ], 409);
        }

        $visitRequest->loadMissing(['pdl', 'schedule', 'sessionSchedules', 'qrCode', 'checkins']);

        // Already checked in / QR consumed — do not re-issue a gate pass.
        // A whole-day visitor out for the midday break keeps the same QR.
        if ($visitRequest->checkins->contains('status', 'checked_in')) {
            return response()->json([
                'message' => 'You are already checked in. A new QR pass is not available.',
                'status' => 'checked_in',
            ], 409);
        }

        if ($visitRequest->qrCode && $visitRequest->qrCode->status === 'used') {
            return response()->json([
                'message' => 'This QR pass has already been used at the gate.',
                'status' => 'used',
            ], 409);
        }

        $expiryMinutes = (int) \App\Models\SystemSetting::value('qr.expiry_minutes', '180');

        // Reuse an existing active, non-expired token; otherwise generate/refresh.
        $existing = $visitRequest->qrCode;
        if ($existing && $existing->isValid()) {
            $qr = $existing;
        } else {
            $qr = QrCode::generateFor($visitRequest, $expiryMinutes);
        }

        $window = $this->visitWindow($visitRequest);

        return response()->json([
            'qrToken' => $qr->qr_token,
            'expiresAt' => optional($qr->expires_at)->toIso8601String(),
            'referenceNumber' => $this->referenceNumber($visitRequest),
            'schedule' => [
                'scheduledAt' => $window['scheduledAt'],
                'endAt' => $window['endAt'],
                'dateDisplay' => $window['dateDisplay'],
                'timeLabel' => $window['timeLabel'],
                'isWholeDay' => $window['isWholeDay'],
                'sessions' => $window['sessions'],
                'pdlName' => $visitRequest->pdl?->full_name,
                'facilityName' => 'BJMP Facility',
            ],
            'scheduleId' => (string) $visitRequest->visit_request_id,
            'status' => $visitRequest->status,
        ]);
    }

    public function timeline(Request $request, VisitRequest $visitRequest): JsonResponse
    {
        $this->authorizeOwnership($request, $visitRequest);
        $visitRequest->loadMissing(['visitor.idDocuments', 'relationship', 'eligibilityAssessment', 'qrCode', 'checkins']);

        $visitor = $visitRequest->visitor;
        $idVerified = $visitor?->idDocuments->firstWhere('verification_status', 'verified');
        $idUploaded = $visitor?->idDocuments->first();
        $relationship = $visitRequest->relationship;
        $eligibility = $visitRequest->eligibilityAssessment;
        $qr = $visitRequest->qrCode;
        // A whole-day visit has a gate record per session: checked in at the
        // first entry, checked out at the final exit (a midday exit is not
        // the end of the visit).
        $firstCheckIn = $visitRequest->checkins->whereNotNull('check_in_time')->min('check_in_time');
        $finalCheckOut = $visitRequest->status === 'completed'
            ? $visitRequest->checkins->whereNotNull('check_out_time')->max('check_out_time')
            : null;

        // A visitor's own request (POST /api/visit-requests) has no
        // confirmation deadline — staff assignments always set one — and is
        // reviewed by staff rather than confirmed by the visitor, so its
        // scheduling steps are worded for that. `assigned` only ever comes
        // from a visitor request.
        $visitorRequested = $visitRequest->status === 'assigned' || $visitRequest->confirmation_deadline === null;

        $schedulingSteps = $visitorRequested
            ? [
                ['id' => 'request_submitted', 'title' => 'Request Submitted', 'description' => $visitRequest->status === 'assigned'
                    ? 'Your visit request was submitted and is awaiting review by facility staff.'
                    : 'Your visit request was submitted for review by facility staff.', 'occurredAt' => $visitRequest->assigned_at],
                ['id' => 'request_approved', 'title' => 'Request Approved', 'description' => 'Your visit request was approved by facility staff.', 'occurredAt' => $visitRequest->confirmed_at],
            ]
            : [
                ['id' => 'schedule_assigned', 'title' => 'Schedule Assigned', 'description' => 'An officer assigned your visit date and time. No self-booking is required.', 'occurredAt' => $visitRequest->assigned_at],
                ['id' => 'attendance_confirmed', 'title' => 'Attendance Confirmed', 'description' => 'You confirmed attendance for the assigned visit window.', 'occurredAt' => $visitRequest->confirmed_at],
            ];

        $steps = [
            ['id' => 'account_created', 'title' => 'Account Created', 'description' => 'Visitor portal account was created and activated.', 'occurredAt' => $visitor?->created_at],
            ['id' => 'documents_submitted', 'title' => 'Documents Submitted', 'description' => 'Required relationship and identification documents were uploaded.', 'occurredAt' => $idUploaded?->uploaded_at],
            ['id' => 'identity_verified', 'title' => 'Identity Verified', 'description' => 'Government-issued ID was reviewed and verified by the facility.', 'occurredAt' => $idVerified?->verified_at],
            ['id' => 'relationship_verified', 'title' => 'Relationship Verified', 'description' => 'Relationship to the PDL was confirmed against submitted records.', 'occurredAt' => $relationship?->verification_status === 'verified' ? $relationship->verified_at : null],
            ['id' => 'visitor_eligible', 'title' => 'Visitor Eligible', 'description' => 'You are cleared for visitation under current facility policy.', 'occurredAt' => $eligibility?->overall_result === 'eligible' ? $eligibility->assessed_at : null],
            ...$schedulingSteps,
            ['id' => 'qr_generated', 'title' => 'QR Generated', 'description' => 'Entry QR pass was issued for gate and front desk presentation.', 'occurredAt' => $qr?->generated_at],
            ['id' => 'checked_in', 'title' => 'Checked In', 'description' => 'Check-in was recorded at the facility visitor entrance.', 'occurredAt' => $firstCheckIn],
            ['id' => 'checked_out', 'title' => 'Checked Out', 'description' => 'Check-out was recorded at the end of your visit session.', 'occurredAt' => $finalCheckOut],
            ['id' => 'visit_completed', 'title' => 'Visit Completed', 'description' => 'Visit session closed. Thank you for following facility rules.', 'occurredAt' => $visitRequest->status === 'completed' ? ($finalCheckOut ?? $visitRequest->updated_at) : null],
        ];

        $lastCompletedIndex = -1;
        foreach ($steps as $i => $step) {
            if ($step['occurredAt']) {
                $lastCompletedIndex = $i;
            }
        }

        $isTerminal = in_array($visitRequest->status, ['declined', 'cancelled', 'no_show']);

        // Closing event for visits that ended without completing. Uses the
        // real cancelled_at timestamp; no_show has no dedicated column, so
        // its time is left null rather than guessed.
        if ($isTerminal && $visitorRequested && $visitRequest->status === 'cancelled') {
            // Only a staff rejection cancels a visitor's request.
            $steps[] = [
                'id' => 'request_rejected',
                'title' => 'Request Rejected',
                'description' => 'Your visit request was rejected by facility staff.'
                    .($visitRequest->cancellation_reason ? " Reason: {$visitRequest->cancellation_reason}" : ''),
                'occurredAt' => $visitRequest->cancelled_at,
                'terminal' => true,
            ];
        } elseif ($isTerminal) {
            $terminal = [
                'declined' => ['visit_declined', 'Visit Declined', 'You declined this assigned visit.'],
                'cancelled' => ['visit_cancelled', 'Visit Cancelled', 'This visit was cancelled.'],
                'no_show' => ['visit_no_show', 'No Show', 'The visit was recorded as a no-show.'],
            ][$visitRequest->status];

            $steps[] = [
                'id' => $terminal[0],
                'title' => $terminal[1],
                'description' => $visitRequest->cancellation_reason ?: $terminal[2],
                'occurredAt' => $visitRequest->status === 'no_show' ? null : $visitRequest->cancelled_at,
                'terminal' => true,
            ];
        }

        $steps = array_map(function ($step, $i) use ($lastCompletedIndex, $isTerminal) {
            if ($step['occurredAt'] || ! empty($step['terminal'])) {
                $stepState = 'completed';
            } elseif (! $isTerminal && $i === $lastCompletedIndex + 1) {
                $stepState = 'current';
            } else {
                $stepState = 'pending';
            }

            return [
                'id' => "{$step['id']}",
                'stepState' => $stepState,
                'title' => $step['title'],
                'description' => $step['description'],
                // Some sources are uncast strings (e.g. visitor_pdl_relationships.verified_at).
                'occurredAt' => $step['occurredAt'] ? \Carbon\Carbon::parse($step['occurredAt'])->toIso8601String() : null,
                'officerNote' => null,
            ];
        }, $steps, array_keys($steps));

        return response()->json(['steps' => $steps]);
    }

    /** Accepted upload formats for government IDs and supporting documents. */
    public const DOCUMENT_FILE_RULES = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'];

    /**
     * Uploads a government ID for the authenticated visitor. Always stored
     * as `pending` — only staff (VisitorController::verifyId/rejectId) can
     * change verification_status.
     */
    public function storeDocument(Request $request): JsonResponse
    {
        $visitor = $this->currentVisitor($request);

        // Accept the stored key (national_id) or its display label (National ID).
        $request->merge(['documentType' => VisitorId::resolveType($request->input('documentType', ''))]);

        $data = $request->validate([
            'documentType' => ['required', 'string', Rule::in(VisitorId::TYPES)],
            'idNumber' => ['nullable', 'string', 'max:50'],
            'file' => self::DOCUMENT_FILE_RULES,
        ], [
            'documentType.in' => 'Choose one of the accepted government ID types.',
            'file.mimes' => 'Upload a JPG, PNG, WEBP, or PDF file.',
            'file.max' => 'The file is too large. Maximum size is 10 MB.',
        ]);

        $document = self::storeGovernmentId(
            $visitor, $request->file('file'), $data['documentType'], $data['idNumber'] ?? null
        );

        return response()->json($this->governmentIdPayload($document->fresh()), 201);
    }

    /**
     * Stores an uploaded government ID for a visitor, always as `pending`.
     * Shared by POST /documents and AuthController::register() (which
     * accepts the ID with the registration form, since a newly registered
     * visitor has no token until their email is verified).
     */
    public static function storeGovernmentId(VisitorProfile $visitor, UploadedFile $file, string $type, ?string $idNumber): VisitorId
    {
        $path = $file->store('visitor-ids', 'public');

        $document = VisitorId::create([
            'visitor_id' => $visitor->visitor_id,
            'id_type' => $type,
            'id_number' => $idNumber ?: 'PENDING',
            'file_path' => $path,
            'verification_status' => 'pending',
        ]);

        AuditLog::record('create', 'visitor_ids', $document->visitor_id_doc_id,
            "Visitor uploaded {$document->typeLabel()} via mobile app", Module::CODE_VISITOR_MANAGEMENT);

        return $document;
    }

    /**
     * The authenticated visitor's own verification documents: government
     * IDs (visitor_ids) and the supporting-document state of each
     * visitor↔PDL relationship. File paths and ID numbers are not exposed.
     */
    public function documents(Request $request): JsonResponse
    {
        $visitor = $this->currentVisitor($request);
        $visitor->load([
            'idDocuments' => fn ($q) => $q->orderByDesc('uploaded_at')->orderByDesc('visitor_id_doc_id'),
            'relationships' => fn ($q) => $q->with(['pdl', 'documents'])->orderByDesc('created_at'),
        ]);

        return response()->json([
            'verificationStatus' => $visitor->verification_status,
            'verifiedAt' => $visitor->verified_at?->toIso8601String(),
            'relationshipHint' => $visitor->relationship_hint,
            'governmentIds' => $visitor->idDocuments->map(fn ($d) => $this->governmentIdPayload($d))->values(),
            'relationships' => $visitor->relationships->map(fn ($r) => $this->relationshipPayload($r))->values(),
        ]);
    }

    /**
     * Uploads the supporting document (e.g. marriage certificate) for one of
     * the visitor's own relationships, using the existing
     * visitor_pdl_relationships.supporting_document_path column. Does not
     * change the relationship's verification_status — staff review it.
     */
    public function storeSupportingDocument(Request $request, VisitorPdlRelationship $relationship): JsonResponse
    {
        $visitor = $this->currentVisitor($request);
        abort_unless((int) $relationship->visitor_id === (int) $visitor->visitor_id, 404);

        if ($relationship->verification_status === 'verified') {
            return response()->json([
                'message' => 'This relationship is already verified. Its supporting document cannot be replaced.',
                'status' => 'verified',
            ], 409);
        }

        $request->validate([
            'file' => self::DOCUMENT_FILE_RULES,
        ], [
            'file.mimes' => 'Upload a JPG, PNG, WEBP, or PDF file.',
            'file.max' => 'The file is too large. Maximum size is 10 MB.',
        ]);

        // Private disk — supporting documents are never publicly served.
        $path = $request->file('file')->store('supporting-documents', 'local');

        $relationship->update(['supporting_document_path' => $path]);

        AuditLog::record('update', 'visitor_pdl_relationships', $relationship->relationship_id,
            'Visitor uploaded supporting document via mobile app', Module::CODE_VISITOR_MANAGEMENT);

        return response()->json($this->relationshipPayload($relationship->fresh('pdl')), 201);
    }

    /**
     * Uploads the file for one BJMP requirement of the visitor's own
     * relationship (marriage certificate, CENOMAR, ...). The requirement must
     * be one the relationship's checklist actually asks for and not already
     * verified. The new file goes in as pending; staff verify or reject it.
     */
    public function storeRequirementDocument(Request $request, VisitorPdlRelationship $relationship, string $requirementKey): JsonResponse
    {
        $visitor = $this->currentVisitor($request);
        abort_unless((int) $relationship->visitor_id === (int) $visitor->visitor_id, 404);

        if ($relationship->verification_status === 'verified') {
            return response()->json([
                'message' => 'This relationship is already verified. Its documents cannot be replaced.',
                'status' => 'verified',
            ], 409);
        }

        $item = collect(RelationshipRequirements::for($relationship))->firstWhere('key', $requirementKey);
        if (! $item || $item['kind'] !== 'document') {
            return response()->json(['message' => 'This document is not required for this relationship.'], 422);
        }
        if (! $item['canUpload']) {
            return response()->json(['message' => 'This document has already been verified.'], 409);
        }

        $request->validate([
            'file' => self::DOCUMENT_FILE_RULES,
        ], [
            'file.mimes' => 'Upload a JPG, PNG, WEBP, or PDF file.',
            'file.max' => 'The file is too large. Maximum size is 10 MB.',
        ]);

        // Private disk — requirement documents are never publicly served.
        $file = $request->file('file');
        $document = RelationshipDocument::create([
            'relationship_id' => $relationship->relationship_id,
            'requirement_key' => $requirementKey,
            'file_path' => $file->store('relationship-documents', 'local'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);

        AuditLog::record('create', 'relationship_documents', $document->document_id,
            "Visitor uploaded {$item['label']} via mobile app", Module::CODE_VISITOR_MANAGEMENT);

        return response()->json($this->relationshipPayload($relationship->fresh(['pdl', 'documents', 'visitor.idDocuments'])), 201);
    }

    private function governmentIdPayload(VisitorId $d): array
    {
        return [
            'id' => (string) $d->visitor_id_doc_id,
            'documentType' => $d->id_type,
            'documentTypeLabel' => $d->typeLabel(),
            'status' => $d->verification_status,
            'uploadedAt' => $d->uploaded_at?->toIso8601String(),
            'verifiedAt' => $d->verified_at?->toIso8601String(),
            'hasFile' => (bool) $d->file_path,
        ];
    }

    private function relationshipPayload(VisitorPdlRelationship $r): array
    {
        return [
            'id' => (string) $r->relationship_id,
            'relationshipType' => $r->relationship_type,
            'relationshipLabel' => $r->relationshipLabel(),
            'pdlName' => $r->pdl?->full_name,
            'status' => $r->verification_status,
            'verifiedAt' => $r->verified_at ? \Carbon\Carbon::parse($r->verified_at)->toIso8601String() : null,
            'hasSupportingDocument' => (bool) $r->supporting_document_path,
            'hasChildrenTogether' => $r->has_children_together,
            // BJMP checklist. File paths stay private; only status is shared.
            'requirements' => collect(RelationshipRequirements::for($r))->map(fn ($item) => [
                'key' => $item['key'],
                'kind' => $item['kind'],
                'label' => $item['label'],
                'description' => $item['description'],
                'status' => $item['status'],
                'canUpload' => $item['canUpload'] && $r->verification_status !== 'verified',
                'rejectionReason' => $item['kind'] === 'document' && $item['status'] === 'rejected' ? $item['note'] : null,
                'uploadedAt' => $item['document']?->uploaded_at?->toIso8601String(),
            ])->values(),
        ];
    }

    // -----------------------------------------------------------------
    private function authorizeOwnership(Request $request, VisitRequest $visitRequest): void
    {
        $visitor = $this->currentVisitor($request);
        abort_unless($visitRequest->visitor_id === $visitor->visitor_id, 404);
    }

    private function referenceNumber(VisitRequest $visitRequest): string
    {
        return 'VIS-' . $visitRequest->assigned_at?->format('Y-md') . '-' . str_pad((string) $visitRequest->visit_request_id, 3, '0', STR_PAD_LEFT);
    }

    /**
     * When the visit runs: from its first session's start to its last
     * session's end. A whole-day visit (morning + afternoon) is still one
     * visit; `sessions` lists its windows, with the midday break between.
     */
    private function visitWindow(VisitRequest $v): array
    {
        $schedules = $v->relationLoaded('sessionSchedules') ? $v->sessionSchedules : $v->sessionSchedules()->get();
        if ($schedules->isEmpty() && $v->schedule) {
            $schedules = collect([$v->schedule]);
        }

        $first = $schedules->first();
        $last = $schedules->last();
        $date = $first?->schedule_date;
        $stamp = fn ($time) => ($date && $time) ? $date->format('Y-m-d').'T'.$this->normalizeTime((string) $time).'+08:00' : null;

        return [
            'scheduledAt' => $stamp($first?->time_slot_start),
            'endAt' => $stamp($last?->time_slot_end),
            'dateDisplay' => $date?->format('F j, Y'),
            'timeLabel' => ($first?->time_slot_start && $last?->time_slot_end)
                ? $this->formatTimeLabel((string) $first->time_slot_start).' - '.$this->formatTimeLabel((string) $last->time_slot_end)
                : null,
            'isWholeDay' => $schedules->count() > 1,
            'sessions' => $schedules->map(fn ($s) => [
                'period' => (int) substr((string) $s->time_slot_start, 0, 2) < 12 ? 'morning' : 'afternoon',
                'startTime' => substr((string) $s->time_slot_start, 0, 5),
                'endTime' => substr((string) $s->time_slot_end, 0, 5),
                'timeLabel' => $this->formatTimeLabel((string) $s->time_slot_start).' - '.$this->formatTimeLabel((string) $s->time_slot_end),
            ])->values()->all(),
        ];
    }

    private function visitPayload(VisitRequest $v): array
    {
        $window = $this->visitWindow($v);

        return [
            'id' => (string) $v->visit_request_id,
            'scheduleId' => (string) $v->visit_request_id,
            'scheduledAt' => $window['scheduledAt'],
            'endAt' => $window['endAt'],
            'dateDisplay' => $window['dateDisplay'],
            'timeLabel' => $window['timeLabel'],
            'isWholeDay' => $window['isWholeDay'],
            'sessions' => $window['sessions'],
            'pdlName' => $v->pdl?->full_name,
            'facility' => 'BJMP Facility',
            'referenceNumber' => $this->referenceNumber($v),
            'visitType' => 'regular',
            'status' => $v->status,
            'cancellationReason' => $v->cancellation_reason,
        ];
    }

    private function normalizeTime(string $time): string
    {
        // Ensure HH:MM:SS for ISO-ish timestamps
        $parts = explode(':', substr($time, 0, 8));
        return sprintf('%02d:%02d:%02d', (int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0));
    }

    private function formatTimeLabel(string $time): string
    {
        try {
            return \Carbon\Carbon::createFromFormat('H:i:s', $this->normalizeTime($time))->format('g:i A');
        } catch (\Throwable) {
            return substr($time, 0, 5);
        }
    }
}
