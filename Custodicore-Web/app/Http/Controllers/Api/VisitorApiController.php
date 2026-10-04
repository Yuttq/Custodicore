<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Notification;
use App\Models\QrCode;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    public function history(Request $request): JsonResponse
    {
        $visitor = $this->currentVisitor($request);

        $visits = VisitRequest::where('visitor_id', $visitor->visitor_id)
            ->with(['pdl', 'schedule'])
            ->orderByDesc('assigned_at')
            ->get()
            ->map(fn ($v) => $this->visitPayload($v));

        return response()->json(['visits' => $visits->values()]);
    }

    public function confirm(Request $request, VisitRequest $visitRequest): JsonResponse
    {
        $this->authorizeOwnership($request, $visitRequest);

        if (! in_array($visitRequest->status, ['assigned', 'pending_confirmation'])) {
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

        if (! in_array($visitRequest->status, ['assigned', 'pending_confirmation'])) {
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

        if ($visitRequest->status !== 'confirmed') {
            throw ValidationException::withMessages(['status' => 'A QR pass is only available once your visit is confirmed.']);
        }

        $expiryMinutes = (int) \App\Models\SystemSetting::value('qr.expiry_minutes', '180');
        $qr = QrCode::generateFor($visitRequest, $expiryMinutes);

        $visitRequest->loadMissing(['pdl', 'schedule']);
        $schedule = $visitRequest->schedule;

        return response()->json([
            'qrToken' => $qr->qr_token,
            'expiresAt' => optional($qr->expires_at)->toIso8601String(),
            'referenceNumber' => $this->referenceNumber($visitRequest),
            'schedule' => [
                'scheduledAt' => $schedule ? $schedule->schedule_date->format('Y-m-d') . 'T' . $schedule->time_slot_start : null,
                'endAt' => $schedule ? $schedule->schedule_date->format('Y-m-d') . 'T' . $schedule->time_slot_end : null,
                'dateDisplay' => $schedule?->schedule_date?->format('M j, Y'),
                'timeLabel' => $schedule ? substr($schedule->time_slot_start, 0, 5) . ' – ' . substr($schedule->time_slot_end, 0, 5) : null,
                'pdlName' => $visitRequest->pdl?->full_name,
                'facilityName' => 'BJMP Facility — Main',
            ],
            'scheduleId' => (string) $visitRequest->visit_request_id,
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

        $steps = array_map(function ($step, $i) use ($lastCompletedIndex, $isTerminal) {
            if ($step['occurredAt']) {
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
                'occurredAt' => $step['occurredAt'] ? $step['occurredAt']->toIso8601String() : null,
                'officerNote' => null,
            ];
        }, $steps, array_keys($steps));

        return response()->json(['steps' => $steps]);
    }

    public function storeDocument(Request $request): JsonResponse
    {
        $visitor = $this->currentVisitor($request);

        $data = $request->validate([
            'documentType' => ['required', 'string'],
            'idNumber' => ['nullable', 'string', 'max:50'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $idTypeMap = array_flip([
            'national_id' => 'National ID', 'drivers_license' => "Driver's License", 'passport' => 'Passport',
            'voters_id' => "Voter's ID", 'philhealth_id' => 'PhilHealth ID', 'umid' => 'UMID',
        ]);
        $idType = in_array($data['documentType'], VisitorId::TYPES, true) ? $data['documentType'] : 'national_id';

        $path = $request->file('file')->store('visitor-ids', 'public');

        $document = VisitorId::create([
            'visitor_id' => $visitor->visitor_id,
            'id_type' => $idType,
            'id_number' => $data['idNumber'] ?? 'PENDING',
            'file_path' => $path,
            'verification_status' => 'pending',
        ]);

        return response()->json([
            'id' => $document->visitor_id_doc_id,
            'documentType' => $document->id_type,
            'status' => $document->verification_status,
        ], 201);
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
