<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\QrCode;
use App\Models\VisitCheckin;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * `php artisan visits:expire`:
 *   pending_confirmation -> cancelled  once confirmation_deadline is strictly past
 *   confirmed            -> no_show    once the visit date is before today
 *
 * "Now" is pinned to Wednesday 2026-10-07 10:00 Asia/Manila. The fixture
 * schedule is Fri 2026-10-09 09:00 (capacity 2); a staff assignment made
 * "now" gets the default 48h deadline, Fri 2026-10-09 10:00.
 */
class VisitExpiryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const DEADLINE = '2026-10-09 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, 'Asia/Manila'));
    }

    private function staffAssignedVisit(?VisitorProfile $visitor = null, ?VisitorPdlRelationship $relationship = null): VisitRequest
    {
        return app(VisitAssignmentService::class)->assign([
            'visitor_id' => ($visitor ?? $this->visitor)->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => ($relationship ?? $this->relationship)->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
    }

    private function confirmedVisit(?VisitorProfile $visitor = null, ?VisitorPdlRelationship $relationship = null): VisitRequest
    {
        return app(VisitAssignmentService::class)->confirmAssignedVisit($this->staffAssignedVisit($visitor, $relationship));
    }

    /** A second verified visitor for the same PDL, so the schedule can be filled. */
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

    /** A visit row in an arbitrary status, bypassing the service (no seat reserved). */
    private function visitWithStatus(string $status, array $overrides = []): VisitRequest
    {
        return VisitRequest::create(array_merge([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
            'status' => $status,
            'confirmation_deadline' => now()->addHours(48),
        ], $overrides));
    }

    private function runExpiry(int $expired, int $noShow): void
    {
        $this->artisan('visits:expire')
            ->expectsOutput("Expired pending confirmations: {$expired}")
            ->expectsOutput("Marked no-show: {$noShow}")
            ->assertSuccessful();
    }

    // ------------------------------------------------ Pending confirmation

    public function test_expired_pending_confirmation_becomes_cancelled(): void
    {
        $visit = $this->staffAssignedVisit();
        $this->at('2026-10-09 10:00:01');

        $this->runExpiry(1, 0);

        $visit->refresh();
        $this->assertSame('cancelled', $visit->status);
        $this->assertNotSame('declined', $visit->status);
        $this->assertNotNull($visit->cancelled_at);
        $this->assertSame('The confirmation deadline passed without a response.', $visit->cancellation_reason);
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
        $this->assertTrue(Notification::where('account_id', $this->visitorAccount->account_id)
            ->where('title', 'Visit Cancelled — Not Confirmed')
            ->where('related_record_id', $visit->visit_request_id)
            ->exists());
        $this->assertTrue(AuditLog::where('record_type', 'visit_requests')
            ->where('record_id', $visit->visit_request_id)
            ->where('description', 'like', 'System cancelled assigned visit%confirmation deadline passed')
            ->exists());
    }

    public function test_expiry_releases_exactly_one_seat_and_reopens_a_full_schedule(): void
    {
        [$otherVisitor, $otherRelationship] = $this->otherVisitor();
        $expiring = $this->staffAssignedVisit();
        $kept = $this->confirmedVisit($otherVisitor, $otherRelationship);
        $this->assertSame(2, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame('full', $this->schedule->fresh()->status);

        $this->at('2026-10-09 10:00:01');
        $this->runExpiry(1, 0);

        $this->assertSame('cancelled', $expiring->fresh()->status);
        $this->assertSame('confirmed', $kept->fresh()->status);
        $schedule = $this->schedule->fresh();
        $this->assertSame(1, (int) $schedule->slots_taken);
        $this->assertSame('open', $schedule->status);
    }

    public function test_running_expiry_twice_does_not_release_another_seat(): void
    {
        [$otherVisitor, $otherRelationship] = $this->otherVisitor();
        $expiring = $this->staffAssignedVisit();
        $this->confirmedVisit($otherVisitor, $otherRelationship);
        $this->at('2026-10-09 10:00:01');

        $this->runExpiry(1, 0);
        $this->runExpiry(0, 0);

        $this->assertSame('cancelled', $expiring->fresh()->status);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame(1, Notification::where('related_record_id', $expiring->visit_request_id)
            ->where('title', 'Visit Cancelled — Not Confirmed')->count());
        $this->assertSame(1, AuditLog::where('record_id', $expiring->visit_request_id)
            ->where('description', 'like', 'System cancelled%')->count());
    }

    public function test_pending_confirmation_before_or_at_the_deadline_is_untouched(): void
    {
        $visit = $this->staffAssignedVisit();

        $this->at('2026-10-08 18:00:00');
        $this->runExpiry(0, 0);
        $this->assertSame('pending_confirmation', $visit->fresh()->status);

        // Exactly at the deadline is not yet past it.
        $this->at(self::DEADLINE);
        $this->runExpiry(0, 0);
        $this->assertSame('pending_confirmation', $visit->fresh()->status);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_pending_confirmation_without_a_deadline_is_untouched(): void
    {
        $visit = $this->staffAssignedVisit();
        VisitRequest::whereKey($visit->visit_request_id)->update(['confirmation_deadline' => null]);
        $this->at('2026-10-20 12:00:00');

        $this->runExpiry(0, 0);

        $this->assertSame('pending_confirmation', $visit->fresh()->status);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
        $this->assertFalse(app(VisitAssignmentService::class)->expirePendingConfirmation($visit->visit_request_id));
    }

    public function test_answered_or_closed_visits_are_not_expired(): void
    {
        $this->schedule->update(['slots_taken' => 1]);
        $visits = collect(['declined', 'cancelled', 'confirmed', 'assigned', 'completed'])
            ->mapWithKeys(fn ($status) => [$status => $this->visitWithStatus($status)]);
        $this->at('2026-10-09 10:00:01');

        $this->runExpiry(0, 0);

        foreach ($visits as $status => $visit) {
            $this->assertSame($status, $visit->fresh()->status);
            $this->assertFalse(app(VisitAssignmentService::class)->expirePendingConfirmation($visit->visit_request_id));
        }
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_visit_confirmed_after_being_selected_for_expiry_is_not_cancelled(): void
    {
        $visit = $this->staffAssignedVisit();
        $service = app(VisitAssignmentService::class);
        $this->at('2026-10-09 10:00:01');
        $ids = $service->overduePendingConfirmationIds();
        $this->assertEquals([$visit->visit_request_id], $ids->all());

        // A racing process answers the visit between selection and the lock.
        VisitRequest::whereKey($visit->visit_request_id)->update(['status' => 'confirmed', 'confirmed_at' => now()]);

        $this->assertFalse($service->expirePendingConfirmation($visit->visit_request_id));
        $this->assertSame('confirmed', $visit->fresh()->status);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_expired_visit_keeps_its_staff_assigned_timeline(): void
    {
        $visit = $this->staffAssignedVisit();
        $this->at('2026-10-09 10:00:01');
        $this->runExpiry(1, 0);

        $this->assertNotNull($visit->fresh()->confirmation_deadline, 'Origin marker is preserved.');

        Sanctum::actingAs($this->visitorAccount);
        $steps = collect($this->getJson("/api/schedules/{$visit->visit_request_id}/timeline")
            ->assertOk()->json('steps'))->keyBy('id');

        $this->assertArrayHasKey('schedule_assigned', $steps);
        $this->assertArrayNotHasKey('request_submitted', $steps);
        $this->assertArrayNotHasKey('request_rejected', $steps);
        $this->assertArrayNotHasKey('visit_declined', $steps);
        $this->assertSame('visit_cancelled', $steps->keys()->last());
        $this->assertSame('The confirmation deadline passed without a response.', $steps['visit_cancelled']['description']);
    }

    // ------------------------------------------------------------- No-show

    public function test_past_confirmed_visit_becomes_no_show_and_keeps_its_seat(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('2026-10-10 00:00:01');

        $this->runExpiry(0, 1);

        $this->assertSame('no_show', $visit->fresh()->status);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken, 'A no-show keeps its seat.');
        $this->assertTrue(AuditLog::where('record_type', 'visit_requests')
            ->where('record_id', $visit->visit_request_id)
            ->where('description', 'like', 'System marked visit as no-show%')
            ->exists());
    }

    public function test_approved_visitor_request_also_becomes_no_show(): void
    {
        $service = app(VisitAssignmentService::class);
        $visit = $service->approveVisitorRequest($service->submitVisitorRequest(
            $this->visitor, $this->relationship, CarbonImmutable::parse('2026-10-09', 'Asia/Manila'), '09:00'
        ));
        $this->at('2026-10-12 08:00:00');

        $this->runExpiry(0, 1);

        $this->assertSame('no_show', $visit->fresh()->status);
        $this->assertNull($visit->fresh()->confirmation_deadline);
    }

    public function test_todays_confirmed_visit_stays_confirmed_even_after_its_slot_ends(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('2026-10-09 23:59:59');

        $this->runExpiry(0, 0);

        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_future_confirmed_visit_stays_confirmed(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('2026-10-08 12:00:00');

        $this->runExpiry(0, 0);

        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_past_visit_that_was_checked_in_is_not_marked_no_show(): void
    {
        $visit = $this->confirmedVisit();
        $qr = QrCode::create([
            'visit_request_id' => $visit->visit_request_id,
            'qr_token' => Str::random(64),
            'generated_at' => now(),
            'status' => 'used',
        ]);
        VisitCheckin::create([
            'visit_request_id' => $visit->visit_request_id,
            'qr_code_id' => $qr->qr_code_id,
            'check_in_time' => CarbonImmutable::parse('2026-10-09 09:05:00', 'Asia/Manila'),
            'status' => 'checked_in',
        ]);
        $this->at('2026-10-10 08:00:00');

        $this->runExpiry(0, 0);

        $this->assertSame('confirmed', $visit->fresh()->status);
        $this->assertFalse(app(VisitAssignmentService::class)->markNoShow($visit->visit_request_id));
    }

    public function test_completed_cancelled_declined_and_other_visits_are_not_marked_no_show(): void
    {
        $visits = collect(['completed', 'cancelled', 'declined', 'assigned'])
            ->mapWithKeys(fn ($status) => [$status => $this->visitWithStatus($status, ['confirmation_deadline' => null])]);
        $this->at('2026-10-12 08:00:00');

        $this->runExpiry(0, 0);

        foreach ($visits as $status => $visit) {
            $this->assertSame($status, $visit->fresh()->status);
            $this->assertFalse(app(VisitAssignmentService::class)->markNoShow($visit->visit_request_id));
        }
    }

    public function test_running_no_show_twice_changes_nothing_the_second_time(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('2026-10-10 08:00:00');

        $this->runExpiry(0, 1);
        $updatedAt = $visit->fresh()->updated_at;
        $this->runExpiry(0, 0);

        $this->assertSame('no_show', $visit->fresh()->status);
        $this->assertEquals($updatedAt, $visit->fresh()->updated_at);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame(1, AuditLog::where('record_id', $visit->visit_request_id)
            ->where('description', 'like', 'System marked visit as no-show%')->count());
    }

    // -------------------------------------------------------------- Mixed

    public function test_one_run_handles_both_transitions(): void
    {
        [$otherVisitor, $otherRelationship] = $this->otherVisitor();
        $missed = $this->confirmedVisit();
        $unanswered = $this->staffAssignedVisit($otherVisitor, $otherRelationship);
        $this->at('2026-10-10 08:00:00');

        $this->runExpiry(1, 1);

        $this->assertSame('no_show', $missed->fresh()->status);
        $this->assertSame('cancelled', $unanswered->fresh()->status);
        $schedule = $this->schedule->fresh();
        $this->assertSame(1, (int) $schedule->slots_taken);
        $this->assertSame('open', $schedule->status);
    }
}
