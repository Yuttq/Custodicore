<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FacilityVisitationRule;
use App\Models\Notification;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * `php artisan visits:expire` also closes visitor-submitted requests that
 * nobody reviewed before their visit date:
 *   assigned -> cancelled  once the visit date is before today (seats released)
 *
 * "Now" is pinned to Wednesday 2026-10-07 10:00 Asia/Manila. Requests are
 * for Fri 2026-10-09: 09:00 (seeded schedule, capacity 2) and 13:00 (rule
 * only; the row is created on demand).
 */
class VisitReviewExpiryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'fri',
            'time_slot_start' => '13:00:00',
            'time_slot_end' => '16:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, 'Asia/Manila'));
    }

    private function submittedRequest(array $startTimes = ['09:00'], ?VisitorProfile $visitor = null, ?VisitorPdlRelationship $relationship = null): VisitRequest
    {
        return app(VisitAssignmentService::class)->submitVisitorRequest(
            $visitor ?? $this->visitor,
            $relationship ?? $this->relationship,
            CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'),
            $startTimes
        );
    }

    private function afternoonSchedule(): VisitSchedule
    {
        return VisitSchedule::whereDate('schedule_date', self::FRIDAY)->where('time_slot_start', '13:00:00')->firstOrFail();
    }

    /** @return array{0:VisitorProfile,1:VisitorPdlRelationship} */
    private function otherVisitor(): array
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'carlo.ramos',
            'email' => 'carlo.ramos@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $visitor = VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => 'Carlo J. Ramos',
            'date_of_birth' => '1979-11-02',
            'gender' => 'male',
            'contact_number' => '09285550199',
            'verification_status' => 'verified',
        ]);
        $relationship = VisitorPdlRelationship::create([
            'visitor_id' => $visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'legal_counsel',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]);

        return [$visitor, $relationship];
    }

    private function runExpiry(int $unreviewed, int $expired = 0, int $noShow = 0): void
    {
        $this->artisan('visits:expire')
            ->expectsOutput("Expired pending confirmations: {$expired}")
            ->expectsOutput("Expired unreviewed requests: {$unreviewed}")
            ->expectsOutput("Marked no-show: {$noShow}")
            ->assertSuccessful();
    }

    // ------------------------------------------------------------ Expiry

    public function test_past_dated_assigned_request_is_cancelled_and_releases_its_seat(): void
    {
        $visit = $this->submittedRequest();
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
        $this->at('2026-10-10 00:05:00');

        $this->runExpiry(1);

        $visit->refresh();
        $this->assertSame('cancelled', $visit->status);
        $this->assertNotNull($visit->cancelled_at);
        $this->assertNull($visit->confirmed_at);
        $this->assertNull($visit->confirmation_deadline, 'Still reads as a visitor request.');
        $this->assertSame(VisitAssignmentService::REVIEW_EXPIRED_REASON, $visit->cancellation_reason);
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_expiry_notifies_the_visitor_with_the_reason(): void
    {
        $visit = $this->submittedRequest();
        $this->at('2026-10-10 00:05:00');

        $this->runExpiry(1);

        $notification = Notification::where('account_id', $this->visitorAccount->account_id)
            ->where('title', 'Visit Request Expired')
            ->where('related_record_type', 'visit_requests')
            ->where('related_record_id', $visit->visit_request_id)
            ->sole();
        $this->assertSame(
            'Your request to visit Example PDL on Oct 9, 2026 09:00–11:30 expired because the visit date passed before the facility reviewed it. Please submit a new request for an upcoming date.',
            $notification->message
        );
    }

    public function test_expiry_is_audit_logged(): void
    {
        $visit = $this->submittedRequest();
        $this->at('2026-10-10 00:05:00');

        $this->runExpiry(1);

        $this->assertTrue(AuditLog::where('record_type', 'visit_requests')
            ->where('record_id', $visit->visit_request_id)
            ->where('action_type', 'update')
            ->where('description', 'System cancelled visit request for Maria D. Santos → Example PDL (Oct 9, 2026 09:00–11:30): visit date passed before review')
            ->exists());
    }

    public function test_whole_day_request_releases_every_session_seat(): void
    {
        $visit = $this->submittedRequest(['09:00', '13:00']);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame(1, (int) $this->afternoonSchedule()->slots_taken);
        $this->at('2026-10-10 08:00:00');

        $this->runExpiry(1);

        $this->assertSame('cancelled', $visit->fresh()->status);
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame(0, (int) $this->afternoonSchedule()->slots_taken);
    }

    public function test_expiry_reopens_a_full_schedule(): void
    {
        $this->schedule->update(['max_capacity' => 1]);
        $visit = $this->submittedRequest();
        $this->assertSame('full', $this->schedule->fresh()->status);
        $this->at('2026-10-10 08:00:00');

        $this->runExpiry(1);

        $this->assertSame('cancelled', $visit->fresh()->status);
        $this->assertSame('open', $this->schedule->fresh()->status);
    }

    // ------------------------------------------------------- Idempotency

    public function test_running_expiry_twice_changes_nothing_the_second_time(): void
    {
        [$otherVisitor, $otherRelationship] = $this->otherVisitor();
        $expiring = $this->submittedRequest();
        $kept = $this->submittedRequest(['09:00'], $otherVisitor, $otherRelationship);
        $this->makeEligibleForApproval($kept);
        app(VisitAssignmentService::class)->approveVisitorRequest($kept);
        $this->assertSame(2, (int) $this->schedule->fresh()->slots_taken);
        $this->at('2026-10-10 08:00:00');

        $this->runExpiry(1, 0, 1);
        $cancelledAt = $expiring->fresh()->cancelled_at;
        $this->runExpiry(0, 0, 0);

        $this->assertSame('cancelled', $expiring->fresh()->status);
        $this->assertEquals($cancelledAt, $expiring->fresh()->cancelled_at);
        $this->assertSame('no_show', $kept->fresh()->status, 'The approved visit is untouched by the new rule.');
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken, 'Only the expired request released a seat, once.');
        $this->assertSame(1, Notification::where('related_record_id', $expiring->visit_request_id)
            ->where('title', 'Visit Request Expired')->count());
        $this->assertSame(1, AuditLog::where('record_id', $expiring->visit_request_id)
            ->where('description', 'like', 'System cancelled visit request%')->count());
        $this->assertFalse(app(VisitAssignmentService::class)->expirePastDueAssignedRequest($expiring->visit_request_id));
    }

    public function test_request_reviewed_after_being_selected_for_expiry_is_left_alone(): void
    {
        $visit = $this->submittedRequest();
        $service = app(VisitAssignmentService::class);
        $this->at('2026-10-10 08:00:00');
        $this->assertEquals([$visit->visit_request_id], $service->pastDueAssignedRequestIds()->all());

        // A racing staff rejection commits between selection and the lock.
        $service->rejectVisitorRequest($visit, 'Facility closed that day.');
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);

        $this->assertFalse($service->expirePastDueAssignedRequest($visit->visit_request_id));
        $visit->refresh();
        $this->assertSame('cancelled', $visit->status);
        $this->assertSame('Facility closed that day.', $visit->cancellation_reason);
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken, 'No second release.');
        $this->assertFalse(Notification::where('related_record_id', $visit->visit_request_id)
            ->where('title', 'Visit Request Expired')->exists());
    }

    public function test_expired_request_can_no_longer_be_approved_or_rejected(): void
    {
        $visit = $this->submittedRequest();
        $this->at('2026-10-10 08:00:00');
        $this->runExpiry(1);

        $this->actingAs($this->recordOfficerAccount);
        $this->post(route('visitor.visit-requests.approve', [$visit->visitor_id, $visit->visit_request_id]))
            ->assertSessionHasErrors('status');
        $this->post(route('visitor.visit-requests.reject', [$visit->visitor_id, $visit->visit_request_id]), [
            'cancellation_reason' => 'Too late.',
        ])->assertSessionHasErrors('status');

        $this->assertSame(VisitAssignmentService::REVIEW_EXPIRED_REASON, $visit->fresh()->cancellation_reason);
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
    }

    // ------------------------------------------------ Visitor timeline

    /** GET /api/schedules/{id}/timeline as the visitor, keyed by step id. */
    private function timelineSteps(VisitRequest $visit): \Illuminate\Support\Collection
    {
        Sanctum::actingAs($this->visitorAccount);

        return collect($this->getJson("/api/schedules/{$visit->visit_request_id}/timeline")
            ->assertOk()->json('steps'))->keyBy('id');
    }

    public function test_expired_request_timeline_says_expired_not_rejected(): void
    {
        $visit = $this->submittedRequest();
        $this->at('2026-10-10 00:05:00');
        $this->runExpiry(1);

        $steps = $this->timelineSteps($visit);

        $this->assertSame('request_expired', $steps->keys()->last());
        $this->assertSame('Request Expired', $steps['request_expired']['title']);
        $this->assertSame(
            'Your visit request expired because the visit date passed before facility staff reviewed it. Please submit a new request for an upcoming date.',
            $steps['request_expired']['description']
        );
        $this->assertSame('completed', $steps['request_expired']['stepState']);
        $this->assertNotNull($steps['request_expired']['occurredAt']);
        $this->assertArrayNotHasKey('request_rejected', $steps);
        $this->assertArrayNotHasKey('visit_cancelled', $steps);
        $this->assertSame('pending', $steps['request_approved']['stepState']);
        $this->assertStringNotContainsString('rejected', strtolower($steps->pluck('description')->implode(' ')));
    }

    public function test_expired_request_reads_as_cancelled_with_its_reason_in_visit_history(): void
    {
        $visit = $this->submittedRequest();
        $this->at('2026-10-10 00:05:00');
        $this->runExpiry(1);

        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/visits/history')
            ->assertOk()
            ->assertJsonPath('visits.0.id', (string) $visit->visit_request_id)
            ->assertJsonPath('visits.0.status', 'cancelled')
            ->assertJsonPath('visits.0.cancellationReason', VisitAssignmentService::REVIEW_EXPIRED_REASON);
    }

    public function test_staff_rejection_timeline_is_still_worded_as_a_rejection(): void
    {
        $visit = $this->submittedRequest();
        $this->at('2026-10-10 00:05:00');
        app(VisitAssignmentService::class)->rejectVisitorRequest($visit, 'The visit date has passed.');

        $steps = $this->timelineSteps($visit);

        $this->assertSame('request_rejected', $steps->keys()->last());
        $this->assertSame(
            'Your visit request was rejected by facility staff. Reason: The visit date has passed.',
            $steps['request_rejected']['description']
        );
        $this->assertArrayNotHasKey('request_expired', $steps);
    }

    public function test_staff_cannot_reject_with_the_reserved_expiry_reason(): void
    {
        $visit = $this->submittedRequest();

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visit-requests.reject', [$visit->visitor_id, $visit->visit_request_id]), [
                'cancellation_reason' => VisitAssignmentService::REVIEW_EXPIRED_REASON,
            ])
            ->assertSessionHasErrors(['cancellation_reason' => 'Enter your own reason for rejecting this visit request.']);

        $this->assertSame('assigned', $visit->fresh()->status);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_staff_assigned_visit_cancelled_at_its_deadline_keeps_its_timeline(): void
    {
        $assigned = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $this->at('2026-10-10 08:00:00');
        $this->runExpiry(0, 1, 0);

        $steps = $this->timelineSteps($assigned);

        $this->assertSame('visit_cancelled', $steps->keys()->last());
        $this->assertArrayNotHasKey('request_expired', $steps);
        $this->assertArrayNotHasKey('request_rejected', $steps);
    }

    // -------------------------------------------------- Not expired

    public function test_future_dated_request_is_not_expired(): void
    {
        $visit = $this->submittedRequest();
        $this->at('2026-10-08 23:59:59');

        $this->runExpiry(0);

        $this->assertSame('assigned', $visit->fresh()->status);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
        $this->assertFalse(app(VisitAssignmentService::class)->expirePastDueAssignedRequest($visit->visit_request_id));
    }

    public function test_request_dated_today_is_not_expired_even_after_its_session_ended(): void
    {
        $visit = $this->submittedRequest();
        $this->at('2026-10-09 23:59:59');

        $this->runExpiry(0);

        $this->assertSame('assigned', $visit->fresh()->status);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_other_statuses_are_not_touched_by_the_new_rule(): void
    {
        $this->schedule->update(['slots_taken' => 5, 'max_capacity' => 10]);
        $visits = collect(['pending_confirmation', 'confirmed', 'declined', 'cancelled', 'completed', 'no_show'])
            ->mapWithKeys(fn ($status) => [$status => VisitRequest::create([
                'visitor_id' => $this->visitor->visitor_id,
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
                'status' => $status,
                'confirmation_deadline' => null,
            ])]);
        $this->at('2026-10-12 08:00:00');

        foreach ($visits as $visit) {
            $this->assertFalse(app(VisitAssignmentService::class)->expirePastDueAssignedRequest($visit->visit_request_id));
        }
        $this->assertSame([], app(VisitAssignmentService::class)->pastDueAssignedRequestIds()->all());

        // The existing rules still run: the confirmed visit becomes a no-show.
        $this->runExpiry(0, 0, 1);

        $this->assertSame('no_show', $visits['confirmed']->fresh()->status);
        foreach (['pending_confirmation', 'declined', 'cancelled', 'completed', 'no_show'] as $status) {
            $this->assertSame($status, $visits[$status]->fresh()->status);
        }
        $this->assertSame(5, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_overdue_staff_assignment_keeps_its_existing_expiry(): void
    {
        $assigned = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $this->at('2026-10-10 08:00:00');

        $this->runExpiry(0, 1, 0);

        $assigned->refresh();
        $this->assertSame('cancelled', $assigned->status);
        $this->assertSame('The confirmation deadline passed without a response.', $assigned->cancellation_reason);
        $this->assertFalse(Notification::where('related_record_id', $assigned->visit_request_id)
            ->where('title', 'Visit Request Expired')->exists());
    }
}
