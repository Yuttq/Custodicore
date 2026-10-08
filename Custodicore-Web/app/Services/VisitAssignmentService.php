<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\EligibilityAssessment;
use App\Models\FacilityVisitationRule;
use App\Models\Module;
use App\Models\Notification;
use App\Models\Pdl;
use App\Models\SystemSetting;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Models\VisitSession;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Visit requests have two distinct entry paths:
 *
 *   Staff assigns:    assign()               -> pending_confirmation -> visitor confirms (confirmed)
 *                                                                       or declines (declined)
 *   Visitor requests: submitVisitorRequest() -> assigned -> staff approves (confirmed) or rejects (cancelled)
 *
 * `assigned` is staff review only — the visitor API never confirms or
 * declines it. Both paths reserve schedule capacity on creation.
 *
 * A visit is one day with one PDL and covers one or more sessions
 * (visit_sessions): a visitor request may take the morning, the afternoon or
 * both (whole day); a staff assignment takes one schedule. The visit carries
 * the status lifecycle and counts once toward visit.max_per_week; each
 * session holds one seat on its schedule, reserved and released together.
 *
 * Time-based transitions (`php artisan visits:expire`):
 *   pending_confirmation -> cancelled  once confirmation_deadline passes (seats released)
 *   confirmed            -> no_show    once the visit date is before today, never checked in
 *   confirmed            -> completed  once the visit date is before today, entered and exited
 *                                       but never finally checked out (left at the midday break)
 */
class VisitAssignmentService
{
    public function __construct(
        private ScheduleAvailabilityService $availability
    ) {
    }

    /** Statuses that still occupy a schedule slot. */
    public const CAPACITY_STATUSES = [
        'assigned',
        'pending_confirmation',
        'confirmed',
        'completed',
        'no_show',
    ];

    public const WEEKLY_LIMIT_MESSAGE = 'You have reached the maximum number of visits allowed for this calendar week.';

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

            $this->assertWeeklyVisitLimit($visitor, $schedule);

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

    /**
     * A visitor's own request for one visit: one day with one or more of its
     * sessions ($startTimes, e.g. ['09:00', '13:00'] for the whole day). The
     * sessions form ONE visit — one weekly-limit count, one review, one QR —
     * and each reserves a seat on its own schedule. Every session must be
     * available exactly as GET /api/schedules/availability reports it; the
     * visit_schedules rows are created on demand. Creates the request as
     * `assigned` (awaiting Record Officer review).
     *
     * @param  string|array<int, string>  $startTimes
     */
    public function submitVisitorRequest(
        VisitorProfile $visitor,
        VisitorPdlRelationship $relationship,
        CarbonImmutable $date,
        string|array $startTimes
    ): VisitRequest {
        if ((int) $relationship->visitor_id !== (int) $visitor->visitor_id) {
            throw ValidationException::withMessages(['relationshipId' => 'Relationship not found.']);
        }

        if (! $relationship->isVerified()) {
            throw ValidationException::withMessages([
                'relationshipId' => 'This relationship has not been verified yet. Visits can only be requested for verified PDL relationships.',
            ]);
        }

        $startTimes = collect((array) $startTimes)->map(fn ($t) => substr((string) $t, 0, 5))->unique()->sort()->values();

        $slots = [];
        foreach ($startTimes as $startTime) {
            $slot = $this->availability->slotFor($visitor, $relationship, $date, $startTime);
            if (! $slot) {
                throw ValidationException::withMessages([
                    'startTime' => 'There is no visiting slot at this time on the selected date.',
                ]);
            }
            if (! $slot['available']) {
                [$field, $message] = $this->unavailableSlotError($slot['reason']);
                throw ValidationException::withMessages([$field => $message]);
            }
            $slots[] = $slot;
        }

        $visitor->loadMissing('account');
        $pdl = Pdl::with('activeRestrictions')->find($relationship->pdl_id);
        $this->assertVisitorAssignable($visitor);
        $this->assertPdlAssignable($pdl);
        $this->assertRelationshipAssignable($relationship, $visitor, $pdl);

        return DB::transaction(function () use ($visitor, $pdl, $relationship, $date, $slots) {
            // Locked in start-time order, so two requests for the same day
            // always take the schedule locks in the same order.
            $schedules = [];
            foreach ($slots as $slot) {
                $scheduleId = $slot['scheduleId']
                    ?? $this->createScheduleRow(FacilityVisitationRule::findOrFail($slot['ruleId']), $date);

                $schedule = VisitSchedule::with('rule')->whereKey($scheduleId)->lockForUpdate()->first();

                // Re-checked under the lock: the slot may have filled or
                // closed since availability was read.
                $this->assertScheduleAssignable($schedule, $pdl);
                if ($this->occupiedSlots($schedule) >= $schedule->max_capacity) {
                    throw ValidationException::withMessages([
                        'schedule_id' => 'This schedule is full. Choose another available slot.',
                    ]);
                }
                $schedules[] = $schedule;
            }

            $first = $schedules[0];
            $this->assertNoDuplicate(
                $visitor, $pdl, $first,
                'You already have a visit with this PDL on this date.'
            );
            $this->assertWeeklyVisitLimit($visitor, $first);

            $visitRequest = VisitRequest::create([
                'visitor_id' => $visitor->visitor_id,
                'pdl_id' => $pdl->pdl_id,
                'relationship_id' => $relationship->relationship_id,
                'schedule_id' => $first->schedule_id,
                'status' => 'assigned',
                'confirmation_deadline' => null,
            ]);

            foreach ($schedules as $schedule) {
                VisitSession::firstOrCreate([
                    'visit_request_id' => $visitRequest->visit_request_id,
                    'schedule_id' => $schedule->schedule_id,
                ]);
                $schedule->reserveSlot();
            }

            EligibilityAssessment::assessFor($visitRequest->fresh([
                'visitor.idDocuments',
                'visitor.flags',
                'pdl.restrictions',
                'relationship',
            ]));

            $scheduleLabel = $this->visitLabel($visitRequest);
            Notification::notify(
                $visitor->account_id,
                'visits',
                'Visit Request Submitted',
                "Your request to visit {$pdl->full_name} on {$scheduleLabel} was received and is awaiting review by the facility.",
                'visit_requests',
                $visitRequest->visit_request_id
            );

            AuditLog::record(
                'create',
                'visit_requests',
                $visitRequest->visit_request_id,
                "Visitor submitted visit request for {$visitor->full_name} → {$pdl->full_name} ({$scheduleLabel})",
                Module::CODE_VISIT_SCHEDULING
            );

            return $visitRequest->fresh(['visitor', 'pdl', 'relationship', 'schedule', 'eligibilityAssessment']);
        });
    }

    /**
     * Record Officer approves a visitor-submitted request: assigned -> confirmed.
     * The seat reserved at submission stays reserved.
     */
    public function approveVisitorRequest(VisitRequest $visitRequest): VisitRequest
    {
        return DB::transaction(function () use ($visitRequest) {
            $visitRequest = $this->lockReviewableRequest($visitRequest);
            $visitRequest->load(['visitor.account', 'pdl.activeRestrictions', 'relationship', 'schedule']);

            $this->assertVisitorAssignable($visitRequest->visitor);
            $this->assertPdlAssignable($visitRequest->pdl);

            if (! $visitRequest->relationship?->isVerified()) {
                throw ValidationException::withMessages([
                    'relationship_id' => 'The visitor\'s relationship to this PDL is no longer verified. Reject the request instead.',
                ]);
            }

            if ($visitRequest->schedule->schedule_date->lt(now()->startOfDay())) {
                throw ValidationException::withMessages([
                    'schedule_id' => 'This visit date has already passed. Reject the request instead.',
                ]);
            }

            $visitRequest->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            $scheduleLabel = $this->visitLabel($visitRequest);
            Notification::notify(
                $visitRequest->visitor->account_id,
                'visit_confirmed',
                'Visit Request Approved',
                "Your visit with {$visitRequest->pdl->full_name} on {$scheduleLabel} is confirmed. Arrive 15 minutes early with valid ID.",
                'visit_requests',
                $visitRequest->visit_request_id
            );

            AuditLog::record(
                'update',
                'visit_requests',
                $visitRequest->visit_request_id,
                "Record Officer approved visit request for {$visitRequest->visitor->full_name} → {$visitRequest->pdl->full_name} ({$scheduleLabel})",
                Module::CODE_VISIT_SCHEDULING
            );

            return $visitRequest->fresh(['visitor', 'pdl', 'schedule']);
        });
    }

    /**
     * Record Officer rejects a visitor-submitted request: assigned -> cancelled,
     * releasing the seats reserved at submission (every session).
     */
    public function rejectVisitorRequest(VisitRequest $visitRequest, string $reason): VisitRequest
    {
        return DB::transaction(function () use ($visitRequest, $reason) {
            $visitRequest = $this->lockReviewableRequest($visitRequest);

            $visitRequest->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->releaseSessionSlots($visitRequest);

            $visitRequest->load(['visitor', 'pdl']);
            $scheduleLabel = $this->visitLabel($visitRequest);
            Notification::notify(
                $visitRequest->visitor->account_id,
                'visits',
                'Visit Request Not Approved',
                "Your request to visit {$visitRequest->pdl->full_name} on {$scheduleLabel} was not approved. Reason: {$reason}",
                'visit_requests',
                $visitRequest->visit_request_id
            );

            AuditLog::record(
                'update',
                'visit_requests',
                $visitRequest->visit_request_id,
                "Record Officer rejected visit request for {$visitRequest->visitor->full_name} → {$visitRequest->pdl->full_name} ({$scheduleLabel}): {$reason}",
                Module::CODE_VISIT_SCHEDULING
            );

            return $visitRequest->fresh(['visitor', 'pdl', 'schedule']);
        });
    }

    /**
     * Visitor confirms a staff-assigned visit: pending_confirmation -> confirmed.
     * Refused once the confirmation deadline or the visit date has passed, or
     * when the visitor, PDL or relationship no longer meets the rules the
     * visit was assigned under.
     */
    public function confirmAssignedVisit(VisitRequest $visitRequest): VisitRequest
    {
        return DB::transaction(function () use ($visitRequest) {
            $visitRequest = $this->lockPendingConfirmation($visitRequest, 'confirm');
            $visitRequest->load(['visitor.account', 'pdl.activeRestrictions', 'relationship', 'schedule']);

            if ($visitRequest->confirmation_deadline?->isPast()) {
                throw ValidationException::withMessages([
                    'confirmation_deadline' => 'The deadline to confirm this visit has passed. Please contact the facility.',
                ]);
            }

            if ($visitRequest->schedule->schedule_date->lt(now()->startOfDay())) {
                throw ValidationException::withMessages([
                    'schedule_id' => 'This visit date has already passed and can no longer be confirmed.',
                ]);
            }

            $this->assertVisitorAssignable($visitRequest->visitor);
            $this->assertPdlAssignable($visitRequest->pdl);
            $this->assertRelationshipAssignable($visitRequest->relationship, $visitRequest->visitor, $visitRequest->pdl);

            $visitRequest->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            Notification::notify(
                $visitRequest->visitor->account_id,
                'visit_confirmed',
                'Visit Confirmed',
                'Your attendance has been recorded. Arrive 15 minutes early with valid ID.',
                'visit_requests',
                $visitRequest->visit_request_id
            );

            AuditLog::record('update', 'visit_requests', $visitRequest->visit_request_id,
                'Visitor confirmed attendance via mobile app', Module::CODE_VISIT_SCHEDULING);

            return $visitRequest->fresh(['pdl', 'schedule']);
        });
    }

    /**
     * Visitor declines a staff-assigned visit: pending_confirmation -> declined,
     * releasing its seats. The row lock and status re-check mean a repeated or
     * racing decline/confirm fails instead of releasing the seats twice.
     */
    public function declineAssignedVisit(VisitRequest $visitRequest, string $reason): VisitRequest
    {
        return DB::transaction(function () use ($visitRequest, $reason) {
            $visitRequest = $this->lockPendingConfirmation($visitRequest, 'decline');

            $visitRequest->update([
                'status' => 'declined',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->releaseSessionSlots($visitRequest);

            AuditLog::record('update', 'visit_requests', $visitRequest->visit_request_id,
                'Visitor declined assigned visit via mobile app', Module::CODE_VISIT_SCHEDULING);

            return $visitRequest->fresh(['pdl', 'schedule']);
        });
    }

    /** IDs of staff-assigned visits whose confirmation deadline has passed unanswered. */
    public function overduePendingConfirmationIds(): Collection
    {
        return VisitRequest::where('status', 'pending_confirmation')
            ->whereNotNull('confirmation_deadline')
            ->where('confirmation_deadline', '<', now())
            ->orderBy('visit_request_id')
            ->pluck('visit_request_id');
    }

    /**
     * pending_confirmation -> cancelled once the confirmation deadline has
     * passed, releasing its seat. Not `declined`: the visitor never answered.
     * The confirmation_deadline is kept so the visit still reads as
     * staff-assigned. Returns false (no change) when the visit was already
     * answered, expired by another run, or is not yet overdue.
     */
    public function expirePendingConfirmation(int $visitRequestId): bool
    {
        return DB::transaction(function () use ($visitRequestId) {
            $visitRequest = VisitRequest::whereKey($visitRequestId)->lockForUpdate()->first();

            if (
                ! $visitRequest
                || $visitRequest->status !== 'pending_confirmation'
                || ! $visitRequest->confirmation_deadline?->isPast()
            ) {
                return false;
            }

            $visitRequest->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'The confirmation deadline passed without a response.',
            ]);

            $this->releaseSessionSlots($visitRequest);

            $visitRequest->load(['visitor', 'pdl']);
            $scheduleLabel = $this->visitLabel($visitRequest);
            Notification::notify(
                $visitRequest->visitor->account_id,
                'visits',
                'Visit Cancelled — Not Confirmed',
                "Your assigned visit with {$visitRequest->pdl->full_name} on {$scheduleLabel} was cancelled because it was not confirmed before the deadline. Please contact the facility to reschedule.",
                'visit_requests',
                $visitRequest->visit_request_id
            );

            AuditLog::record('update', 'visit_requests', $visitRequest->visit_request_id,
                "System cancelled assigned visit for {$visitRequest->visitor->full_name} → {$visitRequest->pdl->full_name} ({$scheduleLabel}): confirmation deadline passed",
                Module::CODE_VISIT_SCHEDULING);

            return true;
        });
    }

    /**
     * IDs of confirmed visits whose schedule date is before today and that
     * were never checked in for any session. A visit that was checked in but
     * not checked out was attended, so it is left for the front desk to close.
     */
    public function missedConfirmedVisitIds(): Collection
    {
        return VisitRequest::where('status', 'confirmed')
            ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', '<', today()->toDateString()))
            ->whereDoesntHave('checkins', fn ($q) => $q->whereNotNull('check_in_time'))
            ->orderBy('visit_request_id')
            ->pluck('visit_request_id');
    }

    /**
     * confirmed -> no_show once the visit date has passed without a check-in.
     * The seats stay counted (no_show is a capacity status). Returns false
     * (no change) when the visit is no longer confirmed, is today or later,
     * or was checked in for any session.
     */
    public function markNoShow(int $visitRequestId): bool
    {
        return DB::transaction(function () use ($visitRequestId) {
            $visitRequest = VisitRequest::whereKey($visitRequestId)->lockForUpdate()->first();

            if (! $visitRequest || $visitRequest->status !== 'confirmed') {
                return false;
            }

            $visitRequest->load(['schedule', 'checkins', 'visitor', 'pdl']);

            if (
                ! $visitRequest->schedule?->schedule_date?->lt(today())
                || $visitRequest->checkins->contains(fn ($c) => $c->check_in_time !== null)
            ) {
                return false;
            }

            $visitRequest->update(['status' => 'no_show']);

            AuditLog::record('update', 'visit_requests', $visitRequest->visit_request_id,
                "System marked visit as no-show for {$visitRequest->visitor->full_name} → {$visitRequest->pdl->full_name} ({$this->visitLabel($visitRequest)}): not checked in on the visit date",
                Module::CODE_VISIT_SCHEDULING);

            return true;
        });
    }

    /**
     * IDs of confirmed visits dated before today that were entered and then
     * left for good: at least one check-in, nobody still inside. This is a
     * whole-day visitor who exited at the midday break and never came back
     * for the afternoon session (the final check-out completes it otherwise).
     */
    public function attendedUnclosedVisitIds(): Collection
    {
        return VisitRequest::where('status', 'confirmed')
            ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', '<', today()->toDateString()))
            ->whereHas('checkins', fn ($q) => $q->whereNotNull('check_in_time'))
            ->whereDoesntHave('checkins', fn ($q) => $q->where('status', 'checked_in'))
            ->orderBy('visit_request_id')
            ->pluck('visit_request_id');
    }

    /**
     * confirmed -> completed for a past visit that was attended and exited
     * but never reached its final check-out (see attendedUnclosedVisitIds).
     * The unused session's seat stays counted, like a no-show. The gate QR is
     * retired. Returns false (no change) when the visit no longer qualifies.
     */
    public function completeAttendedVisit(int $visitRequestId): bool
    {
        return DB::transaction(function () use ($visitRequestId) {
            $visitRequest = VisitRequest::whereKey($visitRequestId)->lockForUpdate()->first();

            if (! $visitRequest || $visitRequest->status !== 'confirmed') {
                return false;
            }

            $visitRequest->load(['schedule', 'checkins', 'qrCode', 'visitor', 'pdl']);

            if (
                ! $visitRequest->schedule?->schedule_date?->lt(today())
                || ! $visitRequest->checkins->contains(fn ($c) => $c->check_in_time !== null)
                || $visitRequest->checkins->contains(fn ($c) => $c->status === 'checked_in')
            ) {
                return false;
            }

            $visitRequest->update(['status' => 'completed']);
            if ($visitRequest->qrCode && $visitRequest->qrCode->status === 'active') {
                $visitRequest->qrCode->update(['status' => 'used']);
            }

            AuditLog::record('update', 'visit_requests', $visitRequest->visit_request_id,
                "System completed visit for {$visitRequest->visitor->full_name} → {$visitRequest->pdl->full_name} ({$this->visitLabel($visitRequest)}): visitor left and did not return for a later session",
                Module::CODE_VISIT_SCHEDULING);

            return true;
        });
    }

    /** Visit sessions on this schedule whose visit still holds a seat. */
    public function occupiedSlots(VisitSchedule $schedule): int
    {
        return VisitSession::query()
            ->join('visit_requests', 'visit_requests.visit_request_id', '=', 'visit_sessions.visit_request_id')
            ->where('visit_sessions.schedule_id', $schedule->schedule_id)
            ->whereIn('visit_requests.status', self::CAPACITY_STATUSES)
            ->count();
    }

    /** Frees the seat of every session of a visit that no longer holds one. */
    private function releaseSessionSlots(VisitRequest $visitRequest): void
    {
        VisitSchedule::whereIn('schedule_id', $visitRequest->sessions()->pluck('schedule_id'))
            ->orderBy('time_slot_start')
            ->lockForUpdate()
            ->get()
            ->each->releaseSlot();
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

    /**
     * One active visit per visitor, PDL and day: a second session that day is
     * part of the same visit, chosen when the visit is requested, never a
     * separate visit (which would count twice toward the weekly limit).
     */
    private function assertNoDuplicate(
        VisitorProfile $visitor,
        Pdl $pdl,
        VisitSchedule $schedule,
        string $message = 'This visitor already has a visit with this PDL on the selected date.'
    ): void {
        $exists = VisitRequest::where('visitor_id', $visitor->visitor_id)
            ->where('pdl_id', $pdl->pdl_id)
            ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', $schedule->schedule_date->toDateString()))
            ->whereIn('status', self::CAPACITY_STATUSES)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'schedule_id' => $message,
            ]);
        }
    }

    /**
     * `visit.max_per_week`: a visitor may hold at most N capacity-status
     * visits (across all PDLs) in one Monday–Sunday week of schedule_date.
     * The counting rules live in ScheduleAvailabilityService, which also
     * reports `weekly_limit` slots; this is the authoritative re-check.
     *
     * Runs inside the creating transaction, but the lock held there is on
     * the target schedule row only, so two concurrent requests by the same
     * visitor for different schedules in the same week can both pass.
     */
    private function assertWeeklyVisitLimit(VisitorProfile $visitor, VisitSchedule $schedule): void
    {
        $day = CarbonImmutable::parse($schedule->schedule_date->toDateString());
        $count = $this->availability->weeklyVisitCounts($visitor, $day, $day)[$this->availability->weekStart($day)] ?? 0;

        if ($count >= $this->availability->maxVisitsPerWeek()) {
            throw ValidationException::withMessages([
                'schedule_id' => self::WEEKLY_LIMIT_MESSAGE,
            ]);
        }
    }

    /** Locks a visit request that is still awaiting staff review (`assigned`). */
    private function lockReviewableRequest(VisitRequest $visitRequest): VisitRequest
    {
        $locked = VisitRequest::whereKey($visitRequest->visit_request_id)->lockForUpdate()->first();

        if (! $locked || $locked->status !== 'assigned') {
            throw ValidationException::withMessages([
                'status' => 'Only visit requests awaiting review can be approved or rejected.',
            ]);
        }

        return $locked;
    }

    /**
     * Locks a staff-assigned visit still awaiting the visitor's answer
     * (`pending_confirmation`). $action is 'confirm' or 'decline'.
     */
    private function lockPendingConfirmation(VisitRequest $visitRequest, string $action): VisitRequest
    {
        $locked = VisitRequest::whereKey($visitRequest->visit_request_id)->lockForUpdate()->first();

        if ($locked?->status === 'assigned' && $action === 'confirm') {
            throw ValidationException::withMessages([
                'status' => 'This visit request is awaiting review by the facility and cannot be confirmed yet.',
            ]);
        }

        if (! $locked || $locked->status !== 'pending_confirmation') {
            throw ValidationException::withMessages([
                'status' => $action === 'confirm'
                    ? 'This visit can no longer be confirmed.'
                    : 'This visit can no longer be declined.',
            ]);
        }

        return $locked;
    }

    /**
     * Creates the visit_schedules row for a rule's slot on $date. If another
     * request created it first (unique schedule_date + time_slot_start +
     * rule_id), uses that row instead. The insert runs in a savepoint so the
     * failed attempt does not abort the surrounding transaction.
     */
    private function createScheduleRow(FacilityVisitationRule $rule, CarbonImmutable $date): int
    {
        try {
            return DB::transaction(fn () => VisitSchedule::create([
                'rule_id' => $rule->rule_id,
                'schedule_date' => $date->toDateString(),
                'time_slot_start' => $rule->time_slot_start,
                'time_slot_end' => $rule->time_slot_end,
                'max_capacity' => $rule->max_capacity,
                'slots_taken' => 0,
                'status' => 'open',
            ])->schedule_id);
        } catch (UniqueConstraintViolationException) {
            return (int) VisitSchedule::where('rule_id', $rule->rule_id)
                ->whereDate('schedule_date', $date->toDateString())
                ->where('time_slot_start', $rule->time_slot_start)
                ->lockForUpdate()
                ->value('schedule_id');
        }
    }

    /** @return array{0:string,1:string} field + visitor-facing message for an unavailable slot */
    private function unavailableSlotError(?string $reason): array
    {
        return match ($reason) {
            ScheduleAvailabilityService::REASON_PAST => ['date', 'This date has already passed. Choose an upcoming visiting day.'],
            ScheduleAvailabilityService::REASON_ENDED => ['startTime', 'This visiting slot has already ended today. Choose a later slot.'],
            ScheduleAvailabilityService::REASON_NOT_ELIGIBLE => ['date', 'Visits with this PDL are not held on the selected day.'],
            ScheduleAvailabilityService::REASON_NOT_IN_EFFECT => ['date', 'The selected date is outside the allowed visitation period.'],
            ScheduleAvailabilityService::REASON_PDL_UNAVAILABLE => ['relationshipId', 'Visits with this PDL are not available at this time. Please contact the facility for details.'],
            ScheduleAvailabilityService::REASON_CLOSED => ['startTime', 'This visiting slot is closed. Choose another available slot.'],
            ScheduleAvailabilityService::REASON_ALREADY_SCHEDULED => ['startTime', 'You already have a visit with this PDL on this date.'],
            ScheduleAvailabilityService::REASON_FULL => ['startTime', 'This visiting slot is full. Choose another available slot.'],
            ScheduleAvailabilityService::REASON_WEEKLY_LIMIT => ['schedule_id', self::WEEKLY_LIMIT_MESSAGE],
            default => ['startTime', 'This visiting slot is not available.'],
        };
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

    /** "Oct 9, 2026 09:00–11:30", or "Oct 9, 2026 09:00–11:30 and 13:00–16:30" for a whole-day visit. */
    private function visitLabel(VisitRequest $visitRequest): string
    {
        $schedules = $visitRequest->sessionSchedules()->get();
        if ($schedules->isEmpty()) {
            return $visitRequest->schedule ? $this->scheduleLabel($visitRequest->schedule) : 'the scheduled date';
        }

        $date = $schedules->first()->schedule_date?->format('M j, Y') ?? 'unknown date';
        $ranges = $schedules->map(fn (VisitSchedule $s) => substr((string) $s->time_slot_start, 0, 5).'–'.substr((string) $s->time_slot_end, 0, 5));

        return $date.' '.$ranges->implode(' and ');
    }
}
