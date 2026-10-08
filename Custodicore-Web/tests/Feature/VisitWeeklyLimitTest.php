<?php

namespace Tests\Feature;

use App\Models\FacilityVisitationRule;
use App\Models\Pdl;
use App\Models\StaffProfile;
use App\Models\SystemSetting;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * `visit.max_per_week`: a visitor may hold at most N visits in capacity
 * statuses (assigned, pending_confirmation, confirmed, completed, no_show),
 * across all PDLs, per Monday–Sunday week of visit_schedules.schedule_date.
 * Enforced in VisitAssignmentService for both assign() and
 * submitVisitorRequest().
 *
 * "Now" is pinned to Wednesday 2026-10-07 10:00 Asia/Manila. The target slot
 * is the fixture schedule Fri 2026-10-09 09:00, in the week Mon 2026-10-05 –
 * Sun 2026-10-11. The fixtures seed no `visit.max_per_week` row, so tests
 * that don't set one run against the default of 2.
 */
class VisitWeeklyLimitTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    private const LIMIT_MESSAGE = 'You have reached the maximum number of visits allowed for this calendar week.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
    }

    private function setLimit(string $value): void
    {
        SystemSetting::create([
            'setting_key' => 'visit.max_per_week',
            'setting_value' => $value,
            'updated_by' => StaffProfile::first()->staff_id,
        ]);
    }

    /** A schedule row on $date with a matching weekday rule (distinct from the fixture slot). */
    private function scheduleOn(string $date, string $start = '13:00:00'): VisitSchedule
    {
        $day = strtolower(CarbonImmutable::parse($date)->format('D'));
        $rule = FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => $day,
            'time_slot_start' => $start,
            'time_slot_end' => '16:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);

        return VisitSchedule::create([
            'rule_id' => $rule->rule_id,
            'schedule_date' => $date,
            'time_slot_start' => $start,
            'time_slot_end' => '16:30:00',
            'max_capacity' => 30,
            'slots_taken' => 0,
            'status' => 'open',
        ]);
    }

    /** An existing visit row for the fixture visitor, bypassing the service. */
    private function existingVisit(
        string $date,
        string $status = 'confirmed',
        ?Pdl $pdl = null,
        ?VisitorPdlRelationship $relationship = null
    ): VisitRequest {
        return VisitRequest::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => ($pdl ?? $this->pdl)->pdl_id,
            'relationship_id' => ($relationship ?? $this->relationship)->relationship_id,
            'schedule_id' => $this->scheduleOn($date)->schedule_id,
            'status' => $status,
            'confirmation_deadline' => null,
        ]);
    }

    private function submit(): VisitRequest
    {
        return app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor,
            $this->relationship,
            CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'),
            '09:00'
        );
    }

    private function assign(?VisitSchedule $schedule = null): VisitRequest
    {
        return app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => ($schedule ?? $this->schedule)->schedule_id,
        ]);
    }

    private function assertBlocked(callable $create): void
    {
        $before = VisitRequest::count();
        $slotsTaken = $this->schedule->fresh()->slots_taken;

        try {
            $create();
            $this->fail('Expected the weekly visit limit to block this visit.');
        } catch (ValidationException $e) {
            $this->assertSame([self::LIMIT_MESSAGE], $e->errors()['schedule_id'] ?? null);
        }

        $this->assertSame($before, VisitRequest::count(), 'No visit request is created.');
        $this->assertSame($slotsTaken, $this->schedule->fresh()->slots_taken, 'No seat is reserved.');
    }

    // ------------------------------------------------------- Both paths

    public function test_visitor_at_limit_cannot_submit_a_visit_request(): void
    {
        $this->existingVisit('2026-10-05');
        $this->existingVisit('2026-10-11');

        $this->assertBlocked(fn () => $this->submit());
    }

    public function test_visitor_at_limit_is_rejected_through_the_api_with_422(): void
    {
        $this->existingVisit('2026-10-05');
        $this->existingVisit('2026-10-11');
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::FRIDAY,
            'startTime' => '09:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['schedule_id' => self::LIMIT_MESSAGE]);
    }

    public function test_staff_cannot_assign_a_visit_to_a_visitor_at_limit(): void
    {
        $this->existingVisit('2026-10-05');
        $this->existingVisit('2026-10-11');

        $this->assertBlocked(fn () => $this->assign());
    }

    // ------------------------------------------------------- Default limit

    public function test_default_limit_of_two_allows_two_visits_then_blocks_the_third(): void
    {
        $this->assertNull(SystemSetting::value('visit.max_per_week'), 'No setting row: the default applies.');

        $this->existingVisit('2026-10-05');
        $this->assertSame('assigned', $this->submit()->status, 'Second visit of the week is allowed.');

        // Third visit, on another day of the same week. (A second Friday
        // session would belong to the Friday visit, not be a new visit.)
        $sunday = $this->scheduleOn('2026-10-11');
        $this->assertBlocked(fn () => $this->assign($sunday));
    }

    public function test_seeded_limit_of_two_is_enforced(): void
    {
        $this->setLimit('2');
        $this->existingVisit('2026-10-05');
        $this->existingVisit('2026-10-11');

        $this->assertBlocked(fn () => $this->submit());
    }

    // ------------------------------------------------------- What counts

    public function test_cancelled_visits_do_not_count(): void
    {
        $this->existingVisit('2026-10-05');
        $this->existingVisit('2026-10-06', 'cancelled');
        $this->existingVisit('2026-10-11', 'cancelled');

        $this->assertSame('assigned', $this->submit()->status);
    }

    public function test_declined_visits_do_not_count(): void
    {
        $this->existingVisit('2026-10-05');
        $this->existingVisit('2026-10-06', 'declined');
        $this->existingVisit('2026-10-11', 'declined');

        $this->assertSame('pending_confirmation', $this->assign()->status);
    }

    public function test_completed_visits_count(): void
    {
        $this->existingVisit('2026-10-05', 'completed');
        $this->existingVisit('2026-10-06', 'completed');

        $this->assertBlocked(fn () => $this->submit());
    }

    public function test_no_show_visits_count(): void
    {
        $this->existingVisit('2026-10-05', 'no_show');
        $this->existingVisit('2026-10-06', 'no_show');

        $this->assertBlocked(fn () => $this->assign());
    }

    public function test_pending_and_assigned_visits_count(): void
    {
        $this->existingVisit('2026-10-08', 'assigned');
        $this->existingVisit('2026-10-10', 'pending_confirmation');

        $this->assertBlocked(fn () => $this->submit());
    }

    public function test_visits_for_a_different_pdl_count_toward_the_same_visitor(): void
    {
        $otherPdl = Pdl::create([
            'pdl_number' => 'PDL-TEST-002',
            'full_name' => 'Other PDL',
            'date_of_birth' => '1991-01-01',
            'gender' => 'female',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);
        $otherRelationship = VisitorPdlRelationship::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $otherPdl->pdl_id,
            'relationship_type' => 'immediate_family',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]);

        $this->existingVisit('2026-10-05', 'confirmed', $otherPdl, $otherRelationship);
        $this->existingVisit('2026-10-11', 'confirmed', $otherPdl, $otherRelationship);

        $this->assertBlocked(fn () => $this->submit());
    }

    // ------------------------------------------------------- Week boundaries

    public function test_visits_in_the_previous_or_following_week_do_not_count(): void
    {
        $this->existingVisit('2026-10-04'); // Sunday of the previous week
        $this->existingVisit('2026-10-12'); // Monday of the following week
        $this->existingVisit('2026-10-16');

        $this->assertSame('assigned', $this->submit()->status);
    }

    public function test_week_is_decided_by_schedule_date_not_assigned_at(): void
    {
        // Assigned this week, but scheduled next week: does not count.
        $this->existingVisit('2026-10-13')->forceFill(['assigned_at' => now()])->save();
        $this->existingVisit('2026-10-14')->forceFill(['assigned_at' => now()])->save();

        $this->assertSame('assigned', $this->submit()->status);
    }

    public function test_visit_assigned_weeks_ago_counts_in_the_week_it_is_scheduled(): void
    {
        $this->existingVisit('2026-10-05')->forceFill(['assigned_at' => now()->subWeeks(3)])->save();
        $this->existingVisit('2026-10-11')->forceFill(['assigned_at' => now()->subWeeks(3)])->save();

        $this->assertBlocked(fn () => $this->assign());
    }

    // ------------------------------------------------------- Setting values

    public function test_zero_or_negative_limit_allows_no_visits(): void
    {
        $this->setLimit('0');
        $this->assertBlocked(fn () => $this->submit());

        SystemSetting::where('setting_key', 'visit.max_per_week')->update(['setting_value' => '-1']);
        $this->assertBlocked(fn () => $this->assign());
    }

    public function test_invalid_limit_falls_back_to_two(): void
    {
        $this->setLimit('not-a-number');
        $this->existingVisit('2026-10-05');

        $this->assertSame('assigned', $this->submit()->status);

        $sunday = $this->scheduleOn('2026-10-11');
        $this->assertBlocked(fn () => $this->assign($sunday));
    }

    public function test_higher_limit_allows_more_visits(): void
    {
        $this->setLimit('3');
        $this->existingVisit('2026-10-05');
        $this->existingVisit('2026-10-11');

        $this->assertSame('assigned', $this->submit()->status);
    }
}
