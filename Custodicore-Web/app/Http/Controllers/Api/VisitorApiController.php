<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Notification;
use App\Models\QrCode;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $visit = VisitRequest::where('visitor_id', $visitor->visitor_id)
            ->whereIn('status', ['assigned', 'pending_confirmation', 'confirmed'])
            ->with(['pdl', 'schedule'])
            ->orderBy('assigned_at')
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
            ->with(['pdl', 'schedule'])
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
            ->with(['pdl', 'schedule'])
            ->orderByDesc('assigned_at')
            ->get()
            ->map(fn ($v) => $this->visitPayload($v));

        return response()->json(['visits' => $visits->values()]);
    }

    /**
     * POST /api/visit-requests — the visitor requests one available slot for
     * a verified PDL relationship. Created as `assigned` (awaiting Record
     * Officer review); the visitor cannot confirm it themselves.
     */
    public function storeVisitRequest(Request $request, VisitAssignmentService $assignments, ScheduleAvailabilityService $availability): JsonResponse
    {
        $visitor = $this->currentVisitor($request);

        $data = $request->validate([
            'relationshipId' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'startTime' => ['required', 'date_format:H:i'],
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
            $data['startTime']
        );

        return response()->json($this->visitPayload($visitRequest), 201);
    }

    /**
     * Visitor confirms a staff-assigned visit (pending_confirmation only).
     * `assigned` is a visitor-submitted request awaiting staff review and can
     * only be approved by a Record Officer.
     */
    public function confirm(Request $request, VisitRequest $visitRequest): JsonResponse
    {
        $this->authorizeOwnership($request, $visitRequest);

        if ($visitRequest->status === 'assigned') {
            throw ValidationException::withMessages(['status' => 'This visit request is awaiting review by the facility and cannot be confirmed yet.']);
        }

        if ($visitRequest->status !== 'pending_confirmation') {
            throw ValidationException::withMessages(['status' => 'This visit can no longer be confirmed.']);
        }

        $visitRequest->update(['status' => 'confirmed', 'confirmed_at' => now()]);

        Notification::notify(
            $request->user()->account_id,
            'visit_confirmed',
            'Visit Confirmed',
            'Your attendance has been recorded. Arrive 15 minutes early with valid ID.',
            'visit_requests',
            $visitRequest->visit_request_id
        );

        AuditLog::record('update', 'visit_requests', $visitRequest->visit_request_id,
            'Visitor confirmed attendance via mobile app', Module::CODE_VISIT_SCHEDULING);

        return response()->json($this->visitPayload($visitRequest->fresh(['pdl', 'schedule'])));
    }

    public function decline(Request $request, VisitRequest $visitRequest): JsonResponse
    {
        $this->authorizeOwnership($request, $visitRequest);

        // `declined` is only for a staff-assigned visit; a request awaiting
        // staff review (`assigned`) is approved or rejected by staff.
        if ($visitRequest->status !== 'pending_confirmation') {
            throw ValidationException::withMessages(['status' => 'This visit can no longer be declined.']);
        }

        $visitRequest->update([
            'status' => 'declined',
            'cancelled_at' => now(),
            'cancellation_reason' => $request->input('reason', 'Visitor unable to attend (declined via mobile app)'),
        ]);

        $visitRequest->loadMissing('schedule');
        $visitRequest->schedule?->releaseSlot();

        AuditLog::record('update', 'visit_requests', $visitRequest->visit_request_id,
            'Visitor declined assigned visit via mobile app', Module::CODE_VISIT_SCHEDULING);

        return response()->json($this->visitPayload($visitRequest->fresh(['pdl', 'schedule'])));
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

        $visitRequest->loadMissing(['pdl', 'schedule', 'qrCode', 'checkin']);

        // Already checked in / QR consumed — do not re-issue a gate pass.
        if ($visitRequest->checkin && $visitRequest->checkin->status === 'checked_in') {
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

        $schedule = $visitRequest->schedule;

        return response()->json([
            'qrToken' => $qr->qr_token,
            'expiresAt' => optional($qr->expires_at)->toIso8601String(),
            'referenceNumber' => $this->referenceNumber($visitRequest),
            'schedule' => [
                'scheduledAt' => $schedule ? $schedule->schedule_date->format('Y-m-d') . 'T' . $this->normalizeTime((string) $schedule->time_slot_start) . '+08:00' : null,
                'endAt' => $schedule ? $schedule->schedule_date->format('Y-m-d') . 'T' . $this->normalizeTime((string) $schedule->time_slot_end) . '+08:00' : null,
                'dateDisplay' => $schedule?->schedule_date?->format('F j, Y'),
                'timeLabel' => ($schedule && $schedule->time_slot_start && $schedule->time_slot_end)
                    ? $this->formatTimeLabel((string) $schedule->time_slot_start) . ' - ' . $this->formatTimeLabel((string) $schedule->time_slot_end)
                    : null,
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
        $visitRequest->loadMissing(['visitor.idDocuments', 'relationship', 'eligibilityAssessment', 'qrCode', 'checkin']);

        $visitor = $visitRequest->visitor;
        $idVerified = $visitor?->idDocuments->firstWhere('verification_status', 'verified');
        $idUploaded = $visitor?->idDocuments->first();
        $relationship = $visitRequest->relationship;
        $eligibility = $visitRequest->eligibilityAssessment;
        $qr = $visitRequest->qrCode;
        $checkin = $visitRequest->checkin;

        $steps = [
            ['id' => 'account_created', 'title' => 'Account Created', 'description' => 'Visitor portal account was created and activated.', 'occurredAt' => $visitor?->created_at],
            ['id' => 'documents_submitted', 'title' => 'Documents Submitted', 'description' => 'Required relationship and identification documents were uploaded.', 'occurredAt' => $idUploaded?->uploaded_at],
            ['id' => 'identity_verified', 'title' => 'Identity Verified', 'description' => 'Government-issued ID was reviewed and verified by the facility.', 'occurredAt' => $idVerified?->verified_at],
            ['id' => 'relationship_verified', 'title' => 'Relationship Verified', 'description' => 'Relationship to the PDL was confirmed against submitted records.', 'occurredAt' => $relationship?->verification_status === 'verified' ? $relationship->verified_at : null],
            ['id' => 'visitor_eligible', 'title' => 'Visitor Eligible', 'description' => 'You are cleared for visitation under current facility policy.', 'occurredAt' => $eligibility?->overall_result === 'eligible' ? $eligibility->assessed_at : null],
            ['id' => 'schedule_assigned', 'title' => 'Schedule Assigned', 'description' => 'An officer assigned your visit date and time. No self-booking is required.', 'occurredAt' => $visitRequest->assigned_at],
            ['id' => 'attendance_confirmed', 'title' => 'Attendance Confirmed', 'description' => 'You confirmed attendance for the assigned visit window.', 'occurredAt' => $visitRequest->confirmed_at],
            ['id' => 'qr_generated', 'title' => 'QR Generated', 'description' => 'Entry QR pass was issued for gate and front desk presentation.', 'occurredAt' => $qr?->generated_at],
            ['id' => 'checked_in', 'title' => 'Checked In', 'description' => 'Check-in was recorded at the facility visitor entrance.', 'occurredAt' => $checkin?->check_in_time],
            ['id' => 'checked_out', 'title' => 'Checked Out', 'description' => 'Check-out was recorded at the end of your visit session.', 'occurredAt' => $checkin?->check_out_time],
            ['id' => 'visit_completed', 'title' => 'Visit Completed', 'description' => 'Visit session closed. Thank you for following facility rules.', 'occurredAt' => $visitRequest->status === 'completed' ? ($checkin?->check_out_time ?? $visitRequest->updated_at) : null],
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
        if ($isTerminal) {
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
            'relationships' => fn ($q) => $q->with('pdl')->orderByDesc('created_at'),
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

    private function visitPayload(VisitRequest $v): array
    {
        $schedule = $v->schedule;
        $start = $schedule?->time_slot_start;
        $end = $schedule?->time_slot_end;
        $date = $schedule?->schedule_date;

        $scheduledAt = null;
        $endAt = null;
        if ($date && $start) {
            $scheduledAt = $date->format('Y-m-d') . 'T' . $this->normalizeTime($start) . '+08:00';
        }
        if ($date && $end) {
            $endAt = $date->format('Y-m-d') . 'T' . $this->normalizeTime($end) . '+08:00';
        }

        return [
            'id' => (string) $v->visit_request_id,
            'scheduleId' => (string) $v->visit_request_id,
            'scheduledAt' => $scheduledAt,
            'endAt' => $endAt,
            'dateDisplay' => $date?->format('F j, Y'),
            'timeLabel' => ($start && $end)
                ? $this->formatTimeLabel($start) . ' - ' . $this->formatTimeLabel($end)
                : null,
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
