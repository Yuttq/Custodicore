<?php

namespace Tests\Feature;

use App\Models\FacilityVisitationRule;
use App\Models\Pdl;
use App\Models\StaffProfile;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Models\WalkInAssessment;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use App\Services\WalkInService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * One visitor may visit only ONE PDL per calendar day, whatever other
 * verified relationships they have. Checked by every visit-creating path
 * (visitor request, staff assign/assignSessions, walk-in) under the visitor
 * lock (VisitAssignmentService::assertVisitorMayBook), and reported by
 * availability as `daily_limit`.
 *
 * "Now" is pinned to Wednesday 2026-10-07 10:00 Asia/Manila. Both PDLs are
 * non_drug_related, so they share the Friday 09:00 and 13:00 and Sunday
 * 09:00 sessions, all published.
 *
 * Like VisitBookingConcurrencyTest, the race tests inject a competing
 * visit between a request's pre-checks and its transaction; SQLite cannot
 * show two transactions blocking each other.
 */
class OnePdlPerDayTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';
    private const SUNDAY = '2026-10-11';

    private VisitSchedule $afternoonSchedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();

        $afternoonRule = FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'fri',
            'time_slot_start' => '13:00:00',
            'time_slot_end' => '16:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);
        FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'sun',
            'time_slot_start' => '09:00:00',
            'time_slot_end' => '11:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);
        $this->publishSessions('2026-10-01', '2026-11-30');
        $this->afternoonSchedule = VisitSchedule::where('rule_id', $afternoonRule->rule_id)
            ->whereDate('schedule_date', self::FRIDAY)->sole();
    }

    // ------------------------------------------------------------ Helpers

    /** A second verified relationship of the fixture visitor, with another PDL. */
    private function otherRelationship(): VisitorPdlRelationship
    {
        $pdl = Pdl::create([
            'pdl_number' => 'PDL-TEST-002',
            'full_name' => 'Second PDL',
            'date_of_birth' => '1991-01-01',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);

        return VisitorPdlRelationship::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $pdl->pdl_id,
            'relationship_type' => 'immediate_family',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]);
    }

    /** @param  string|array<int, string>  $starts */
    private function request(VisitorPdlRelationship $relationship, string $date, string|array $starts): VisitRequest
    {
        return app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor, $relationship, CarbonImmutable::parse($date, 'Asia/Manila'), $starts
        );
    }

    private function assign(VisitorPdlRelationship $relationship, VisitSchedule $schedule): VisitRequest
    {
        return app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $relationship->pdl_id,
            'relationship_id' => $relationship->relationship_id,
            'schedule_id' => $schedule->schedule_id,
        ]);
    }

    /** @return array<string, mixed> */
    private function validationErrors(\Closure $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Expected a ValidationException.');
    }

    /** The day as the visitor calendar reports it for $relationship. */
    private function day(VisitorPdlRelationship $relationship, string $date): array
    {
        $day = CarbonImmutable::parse($date, 'Asia/Manila');

        return app(ScheduleAvailabilityService::class)
            ->forRelationships($this->visitor, collect([$relationship->fresh()]), $day, $day)[0]['days'][0];
    }

    /** Runs $race once, right after a visitor request read slot availability. */
    private function raceBeforeVisitorRequestTransaction(\Closure $race): void
    {
        $this->app->instance(ScheduleAvailabilityService::class, new class($race) extends ScheduleAvailabilityService {
            private bool $ran = false;

            public function __construct(private \Closure $race)
            {
            }

            public function slotFor(VisitorProfile $visitor, VisitorPdlRelationship $relationship, CarbonImmutable $date, string $startTime): ?array
            {
                $slot = parent::slotFor($visitor, $relationship, $date, $startTime);
                if (! $this->ran) {
                    $this->ran = true;
                    ($this->race)();
                }

                return $slot;
            }
        });
    }

    /** Runs $race once, right after assign() reads the confirmation window setting. */
    private function raceBeforeAssignTransaction(\Closure $race): void
    {
        $ran = false;
        DB::listen(function (QueryExecuted $query) use (&$ran, $race) {
            if (! $ran && str_contains($query->sql, '"system_settings"') && in_array('visit.confirmation_window_hours', $query->bindings, true)) {
                $ran = true;
                $race();
            }
        });
    }

    /** @return array<int, string> SQL run inside the first transaction $call opens, in order */
    private function statementsOfFirstTransaction(\Closure $call): array
    {
        $log = [];
        $began = false;
        Event::listen(TransactionBeginning::class, function () use (&$log, &$began) {
            if (! $began) {
                $began = true;
                $log[] = 'BEGIN';
            }
        });
        DB::listen(function (QueryExecuted $query) use (&$log, &$began) {
            if ($began) {
                $log[] = $query->sql;
            }
        });

        $call();

        $this->assertSame('BEGIN', $log[0] ?? null, 'The call opened a transaction.');

        return array_slice($log, 1);
    }

    private function assertVisitorLockFirstThenChecks(array $statements): void
    {
        $this->assertMatchesRegularExpression(
            '/^select \* from "visitor_profiles" where "visitor_profiles"\."visitor_id" = \?/',
            $statements[0],
            'The first statement of the transaction is the visitor row lock.'
        );

        $index = function (\Closure $matches) use ($statements): int {
            foreach ($statements as $i => $sql) {
                if ($matches($sql)) {
                    return $i;
                }
            }
            $this->fail('Expected statement not found in: '.implode("\n", $statements));
        };

        $this->assertGreaterThan(0, $index(fn ($sql) => str_starts_with($sql, 'select exists(select * from "visit_requests"')), 'Daily check after the lock.');
        $this->assertGreaterThan(0, $index(fn ($sql) => str_contains($sql, 'COUNT(*) as aggregate') && str_contains($sql, '"visit_requests"."visitor_id"')), 'Weekly count after the lock.');
    }

    // ---------------------------------------------------- Visitor requests

    public function test_visitor_request_for_another_pdl_on_the_same_day_is_refused(): void
    {
        $other = $this->otherRelationship();
        $this->request($this->relationship, self::FRIDAY, '09:00');

        $errors = $this->validationErrors(fn () => $this->request($other, self::FRIDAY, '13:00'));

        $this->assertSame([VisitAssignmentService::ONE_PDL_PER_DAY_MESSAGE], $errors['schedule_id']);
        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(0, (int) $this->afternoonSchedule->fresh()->slots_taken, 'The refused request reserved no seat.');
    }

    public function test_visitor_request_for_another_pdl_is_refused_through_the_api(): void
    {
        $other = $this->otherRelationship();
        $this->request($this->relationship, self::FRIDAY, '09:00');
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson('/api/visit-requests', [
            'relationshipId' => $other->relationship_id,
            'date' => self::FRIDAY,
            'startTime' => '13:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['schedule_id' => VisitAssignmentService::ONE_PDL_PER_DAY_MESSAGE]);

        $this->assertSame(1, VisitRequest::count());
    }

    public function test_whole_day_request_for_another_pdl_on_the_same_day_is_refused(): void
    {
        $other = $this->otherRelationship();
        $this->request($this->relationship, self::FRIDAY, '13:00');

        $errors = $this->validationErrors(fn () => $this->request($other, self::FRIDAY, ['09:00', '13:00']));

        $this->assertSame([VisitAssignmentService::ONE_PDL_PER_DAY_MESSAGE], $errors['schedule_id']);
        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_a_staff_assigned_visit_blocks_a_request_for_another_pdl_that_day(): void
    {
        $other = $this->otherRelationship();
        $this->assertSame('pending_confirmation', $this->assign($this->relationship, $this->schedule)->status);

        $errors = $this->validationErrors(fn () => $this->request($other, self::FRIDAY, '13:00'));

        $this->assertSame([VisitAssignmentService::ONE_PDL_PER_DAY_MESSAGE], $errors['schedule_id']);
    }

    // ------------------------------------------------------- Staff paths

    public function test_staff_assign_for_another_pdl_on_the_same_day_is_refused(): void
    {
        $other = $this->otherRelationship();
        $this->request($this->relationship, self::FRIDAY, '09:00');

        $errors = $this->validationErrors(fn () => $this->assign($other, $this->afternoonSchedule));

        $this->assertSame([VisitAssignmentService::STAFF_ONE_PDL_PER_DAY_MESSAGE], $errors['schedule_id']);
        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(0, (int) $this->afternoonSchedule->fresh()->slots_taken);
    }

    public function test_staff_assign_sessions_for_another_pdl_on_the_same_day_is_refused(): void
    {
        $other = $this->otherRelationship();
        $this->request($this->relationship, self::FRIDAY, '09:00');

        $errors = $this->validationErrors(fn () => app(VisitAssignmentService::class)->assignSessions(
            $this->visitor, $other, CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'), ['13:00']
        ));

        $this->assertStringContainsString('another PDL on this date', $errors['schedule_id'][0]);
        $this->assertSame(1, VisitRequest::count());
    }

    public function test_staff_assign_form_shows_the_other_pdls_day_as_unavailable(): void
    {
        $other = $this->otherRelationship();
        $this->request($this->relationship, self::FRIDAY, '09:00');

        $days = collect(\App\Http\Controllers\VisitAssignmentController::assignableDays($this->visitor, collect([$other->fresh('pdl.activeRestrictions')]))[$other->relationship_id]);
        $friday = $days->firstWhere('date', self::FRIDAY);

        $this->assertFalse($friday['available']);
        $this->assertSame(['Already visiting another PDL that day'], array_values(array_unique(array_column($friday['sessions'], 'reason'))));
    }

    // ------------------------------------------------ What is still allowed

    public function test_another_pdl_on_a_different_day_is_allowed(): void
    {
        $other = $this->otherRelationship();
        $this->request($this->relationship, self::FRIDAY, '09:00');

        $this->assertSame('assigned', $this->request($other, self::SUNDAY, '09:00')->status);
        $this->assertSame(2, VisitRequest::count());
    }

    public function test_a_rejected_visit_does_not_block_another_pdl_that_day(): void
    {
        $other = $this->otherRelationship();
        $first = $this->request($this->relationship, self::FRIDAY, '09:00');
        app(VisitAssignmentService::class)->rejectVisitorRequest($first, 'Not this day.');

        $this->assertSame('assigned', $this->request($other, self::FRIDAY, '13:00')->status);
    }

    public function test_a_declined_visit_does_not_block_another_pdl_that_day(): void
    {
        $other = $this->otherRelationship();
        $first = $this->assign($this->relationship, $this->schedule);
        app(VisitAssignmentService::class)->declineAssignedVisit($first, 'Cannot come.');

        $this->assertSame('pending_confirmation', $this->assign($other, $this->afternoonSchedule)->status);
    }

    public function test_whole_day_with_one_pdl_is_still_one_visit(): void
    {
        $visit = $this->request($this->relationship, self::FRIDAY, ['09:00', '13:00']);

        $this->assertSame(2, $visit->sessions()->count());
        $this->assertSame(1, VisitRequest::count());
    }

    // ------------------------------------------------------ Availability

    public function test_availability_marks_the_other_pdl_unavailable_that_day(): void
    {
        $other = $this->otherRelationship();
        $this->request($this->relationship, self::FRIDAY, '09:00');

        $friday = $this->day($other, self::FRIDAY);
        $this->assertFalse($friday['available']);
        foreach ($friday['slots'] as $slot) {
            $this->assertSame(ScheduleAvailabilityService::REASON_DAILY_LIMIT, $slot['reason'], $slot['startTime']);
        }
        $this->assertSame(ScheduleAvailabilityService::REASON_DAILY_LIMIT, $friday['wholeDay']['reason']);

        // The booked PDL's own afternoon is still "already scheduled" (part of that visit).
        $this->assertSame(
            ScheduleAvailabilityService::REASON_ALREADY_SCHEDULED,
            collect($this->day($this->relationship, self::FRIDAY)['slots'])->firstWhere('startTime', '13:00')['reason']
        );

        // Another day is unaffected.
        $this->assertTrue($this->day($other, self::SUNDAY)['available']);
    }

    // ------------------------------------------------------- Concurrency

    public function test_racing_requests_for_two_pdls_on_the_same_day_create_one_visit(): void
    {
        $other = $this->otherRelationship();
        // The PDL A request commits while the PDL B request is between its
        // availability read (which saw no visit that day) and its checks.
        $this->raceBeforeVisitorRequestTransaction(fn () => $this->request($this->relationship, self::FRIDAY, '09:00'));

        $errors = $this->validationErrors(fn () => $this->request($other, self::FRIDAY, '13:00'));

        $this->assertSame([VisitAssignmentService::ONE_PDL_PER_DAY_MESSAGE], $errors['schedule_id']);
        $this->assertSame($this->pdl->pdl_id, VisitRequest::sole()->pdl_id);
        $this->assertSame(0, (int) $this->afternoonSchedule->fresh()->slots_taken);
    }

    public function test_staff_assignment_rejects_another_pdls_visit_created_after_its_prechecks(): void
    {
        $other = $this->otherRelationship();
        $this->raceBeforeAssignTransaction(fn () => $this->request($this->relationship, self::FRIDAY, '09:00'));

        $errors = $this->validationErrors(fn () => $this->assign($other, $this->afternoonSchedule));

        $this->assertSame([VisitAssignmentService::STAFF_ONE_PDL_PER_DAY_MESSAGE], $errors['schedule_id']);
        $this->assertSame($this->pdl->pdl_id, VisitRequest::sole()->pdl_id);
    }

    // ----------------------------------------------- Walk-in lock order

    private function approvedWalkInAssessment(): void
    {
        WalkInAssessment::create(['visitor_id' => $this->visitor->visitor_id, 'visit_purpose' => 'Family visit'])
            ->update(['decision' => 'approved']);
    }

    public function test_walk_in_booking_locks_the_visitor_before_any_read_in_its_transaction(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 08:00:00', 'Asia/Manila'));
        $this->completeRequirements();
        $this->approvedWalkInAssessment();

        $statements = $this->statementsOfFirstTransaction(fn () => app(WalkInService::class)->bookVisit(
            $this->visitor, $this->relationship->fresh(), ['09:00']
        ));

        $this->assertVisitorLockFirstThenChecks($statements);
        $this->assertSame('confirmed', VisitRequest::sole()->status);
    }

    public function test_walk_in_approval_locks_the_visitor_first_and_books_the_chosen_session(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 08:00:00', 'Asia/Manila'));
        $this->completeRequirements();
        // The Front Desk chose Sunday's session; approving books exactly that.
        WalkInAssessment::create([
            'visitor_id' => $this->visitor->visitor_id,
            'visit_purpose' => 'Family visit',
            'pdl_id' => $this->pdl->pdl_id,
            'requested_date' => self::SUNDAY,
            'requested_start_times' => ['09:00'],
        ]);

        $statements = $this->statementsOfFirstTransaction(fn () => app(WalkInService::class)->assess(
            $this->visitor,
            ['decision' => 'approved', 'walk_in_reason' => 'No phone to book in the app', 'remarks' => null],
            StaffProfile::first()->staff_id,
        ));

        $this->assertVisitorLockFirstThenChecks($statements);
        $visit = VisitRequest::sole();
        $this->assertSame('confirmed', $visit->status);
        $this->assertTrue($visit->is_walk_in);
        $this->assertSame(self::SUNDAY, $visit->schedule->schedule_date->toDateString());
        $this->assertSame(1, (int) $visit->schedule->fresh()->slots_taken, 'The walk-in takes a seat.');
    }
}
