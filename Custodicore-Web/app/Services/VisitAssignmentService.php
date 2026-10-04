<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\EligibilityAssessment;
use App\Models\Module;
use App\Models\Notification;
use App\Models\Pdl;
use App\Models\SystemSetting;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record Officer visit assignment — creates a visit_request at
 * pending_confirmation, reserves schedule capacity, and notifies the visitor.
 */
class VisitAssignmentService
{
    /** Statuses that still occupy a schedule slot. */
    public const CAPACITY_STATUSES = [
        'assigned',
        'pending_confirmation',
        'confirmed',
        'completed',
        'no_show',
    ];

    /**
     * @param  array{visitor_id:int,pdl_id:int,relationship_id:int,schedule_id:int,confirmation_deadline?:string|null}  $input
     */
    public function assign(array $input): VisitRequest
    {
        $visitor = VisitorProfile::with('account')->find($input['visitor_id']);
        $pdl = Pdl::with('activeRestrictions')->find($input['pdl_id']);
        $relationship = VisitorPdlRelationship::find($input['relationship_id']);
        $schedule = VisitSchedule::with('rule')->find($input['schedule_id']);

        $this->assertVisitorAssignable($visitor);
        $this->assertPdlAssignable($pdl);
        $this->assertRelationshipAssignable($relationship, $visitor, $pdl);
        $this->assertScheduleAssignable($schedule, $pdl);
        $this->assertNoDuplicate($visitor, $pdl, $schedule);

        $deadline = $this->resolveDeadline($input['confirmation_deadline'] ?? null);

        return DB::transaction(function () use ($visitor, $pdl, $relationship, $schedule, $deadline) {
            $schedule = VisitSchedule::whereKey($schedule->schedule_id)->lockForUpdate()->first();

            if (! $schedule->hasOpenSlots() || $this->occupiedSlots($schedule) >= $schedule->max_capacity) {
                throw ValidationException::withMessages([
                    'schedule_id' => 'This schedule is full. Choose another available slot.',
                ]);
            }

            $visitRequest = VisitRequest::create([
                'visitor_id' => $visitor->visitor_id,
                'pdl_id' => $pdl->pdl_id,
                'relationship_id' => $relationship->relationship_id,
                'schedule_id' => $schedule->schedule_id,
                'status' => 'pending_confirmation',
                'confirmation_deadline' => $deadline,
            ]);

            $schedule->reserveSlot();

            EligibilityAssessment::assessFor($visitRequest->fresh([
                'visitor.idDocuments',
                'visitor.flags',
                'pdl.restrictions',
                'relationship',
            ]));

            $scheduleLabel = $this->scheduleLabel($schedule);
            Notification::notify(
                $visitor->account_id,
                'visits',
                'Visit Assigned — Confirmation Required',
                "A visit with {$pdl->full_name} has been assigned for {$scheduleLabel}. Please confirm or decline in the app.",
                'visit_requests',
                $visitRequest->visit_request_id
            );

            AuditLog::record(
                'create',
                'visit_requests',
                $visitRequest->visit_request_id,
                "Record Officer assigned visit for {$visitor->full_name} → {$pdl->full_name} ({$scheduleLabel})",
                Module::CODE_VISIT_SCHEDULING
            );

            return $visitRequest->fresh(['visitor', 'pdl', 'relationship', 'schedule', 'eligibilityAssessment']);
        });
    }

    public function occupiedSlots(VisitSchedule $schedule): int
    {
        return VisitRequest::where('schedule_id', $schedule->schedule_id)
            ->whereIn('status', self::CAPACITY_STATUSES)
            ->count();
    }

    private function assertVisitorAssignable(?VisitorProfile $visitor): void
    {
        if (! $visitor) {
            throw ValidationException::withMessages(['visitor_id' => 'Visitor not found.']);
        }

        $account = $visitor->account;
        if (! $account || $account->status !== 'active') {
            throw ValidationException::withMessages([
                'visitor_id' => 'This visitor account is not active and cannot be assigned a visit.',
            ]);
        }

        if ($visitor->verification_status === 'rejected') {
            throw ValidationException::withMessages([
                'visitor_id' => 'This visitor was rejected and cannot be assigned a visit.',
            ]);
        }
    }

    private function assertPdlAssignable(?Pdl $pdl): void
    {
        if (! $pdl) {
            throw ValidationException::withMessages(['pdl_id' => 'PDL not found.']);
        }

        if ($pdl->custody_status !== 'active') {
            throw ValidationException::withMessages([
                'pdl_id' => 'Only PDLs with active custody status can receive visit assignments.',
            ]);
        }

        if ($pdl->activeRestrictions->isNotEmpty()) {
            throw ValidationException::withMessages([
                'pdl_id' => 'This PDL has an active restriction and is not eligible for visitation.',
            ]);
        }
    }

    private function assertRelationshipAssignable(
        ?VisitorPdlRelationship $relationship,
        VisitorProfile $visitor,
        Pdl $pdl
    ): void {
        if (! $relationship) {
            throw ValidationException::withMessages(['relationship_id' => 'Relationship not found.']);
        }

        if (
            (int) $relationship->visitor_id !== (int) $visitor->visitor_id
            || (int) $relationship->pdl_id !== (int) $pdl->pdl_id
        ) {
            throw ValidationException::withMessages([
                'relationship_id' => 'The selected relationship does not belong to this visitor and PDL.',
            ]);
        }

        if ($relationship->verification_status === 'rejected') {
            throw ValidationException::withMessages([
                'relationship_id' => 'This relationship was rejected and cannot be used for a visit assignment.',
            ]);
        }

        // Existing relationship policy lives in priority_tier / relationship_type
        // enums on visitor_pdl_relationships — do not invent a parallel policy.
        if (! in_array($relationship->priority_tier, ['high_priority', 'requires_verification'], true)) {
            throw ValidationException::withMessages([
                'relationship_id' => 'This relationship is not allowed under the facility relationship policy.',
            ]);
        }
    }

    private function assertScheduleAssignable(?VisitSchedule $schedule, Pdl $pdl): void
    {
        if (! $schedule) {
            throw ValidationException::withMessages(['schedule_id' => 'Schedule not found.']);
        }

        if ($schedule->status !== 'open') {
            throw ValidationException::withMessages([
                'schedule_id' => 'This schedule is not open for new assignments.',
            ]);
        }

        if (! $schedule->hasOpenSlots()) {
            throw ValidationException::withMessages([
                'schedule_id' => 'This schedule has no remaining capacity.',
            ]);
        }

        $rule = $schedule->rule;
        if (! $rule) {
            throw ValidationException::withMessages([
                'schedule_id' => 'This schedule has no visitation rule attached.',
            ]);
        }

        if ($rule->pdl_classification !== $pdl->classification) {
            throw ValidationException::withMessages([
                'schedule_id' => 'This schedule does not match the PDL classification visitation policy.',
            ]);
        }

        $date = $schedule->schedule_date;
        if ($rule->effective_from && $date->lt($rule->effective_from)) {
            throw ValidationException::withMessages([
                'schedule_id' => 'This schedule is outside the allowed visitation period.',
            ]);
        }

        if ($rule->effective_to && $date->gt($rule->effective_to)) {
            throw ValidationException::withMessages([
                'schedule_id' => 'This schedule is outside the allowed visitation period.',
            ]);
        }

        if ($date->lt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'schedule_id' => 'Cannot assign a visit to a past schedule date.',
            ]);
        }
    }

    private function assertNoDuplicate(VisitorProfile $visitor, Pdl $pdl, VisitSchedule $schedule): void
    {
        $exists = VisitRequest::where('visitor_id', $visitor->visitor_id)
            ->where('pdl_id', $pdl->pdl_id)
            ->where('schedule_id', $schedule->schedule_id)
            ->whereIn('status', self::CAPACITY_STATUSES)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'schedule_id' => 'This visitor is already assigned to this PDL on the selected schedule.',
            ]);
        }
    }

    private function resolveDeadline(mixed $raw): Carbon
    {
        if ($raw) {
            return Carbon::parse($raw);
        }

        $hours = (int) SystemSetting::value('visit.confirmation_window_hours', '48');

        return now()->addHours(max($hours, 1));
    }

    private function scheduleLabel(VisitSchedule $schedule): string
    {
        $date = $schedule->schedule_date?->format('M j, Y') ?? 'unknown date';
        $start = substr((string) $schedule->time_slot_start, 0, 5);
        $end = substr((string) $schedule->time_slot_end, 0, 5);

        return "{$date} {$start}–{$end}";
    }
}
