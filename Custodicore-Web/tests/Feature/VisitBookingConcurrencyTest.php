<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FacilityVisitationRule;
use App\Models\StaffProfile;
use App\Models\SystemSetting;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Per-visitor serialization of visit creation (VisitAssignmentService::
 * lockVisitor): the weekly limit and the one-visit-per-PDL-per-day rule
 * span several schedule rows, so both creating paths lock the visitor row
 * first, then the schedule rows, and run those checks after the locks.
 *
 * SQLite runs one transaction at a time and ignores FOR UPDATE, so these
 * tests cannot show two transactions blocking each other. What they do show:
 * the visitor lock is the first statement of each creating transaction
 * (query order), and a competing visit committed before the checks run is
 * always seen (races injected at transaction start). Real blocking on
 * MariaDB/InnoDB is not covered here.
 *
 * "Now" is pinned to Wednesday 2026-10-07 10:00 Asia/Manila. The fixture
 * PDL is non_drug_related: Fri 2026-10-09 09:00 has a schedule row
 * (capacity 2); Fri 13:00 and Sun 09:00 have rules but no rows.
 */
class VisitBookingConcurrencyTest extends TestCase
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
        $this->afternoonSchedule = VisitSchedule::create([
            'rule_id' => $afternoonRule->rule_id,
            'schedule_date' => self::FRIDAY,
            'time_slot_start' => '13:00:00',
            'time_slot_end' => '16:30:00',
            'max_capacity' => 30,
            'slots_taken' => 0,
            'status' => 'open',
        ]);
    }

    // ------------------------------------------------------------ Helpers

    private function request(string $date, string $start, ?VisitorProfile $visitor = null, ?VisitorPdlRelationship $relationship = null): VisitRequest
    {
        return app(VisitAssignmentService::class)->submitVisitorRequest(
            $visitor ?? $this->visitor,
            $relationship ?? $this->relationship,
            CarbonImmutable::parse($date, 'Asia/Manila'),
            $start
        );
    }

    private function assign(VisitSchedule $schedule): VisitRequest
    {
        return app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $schedule->schedule_id,
        ]);
    }

    private function setWeeklyLimit(int $limit): void
    {
        SystemSetting::create([
            'setting_key' => 'visit.max_per_week',
            'setting_value' => (string) $limit,
            'updated_by' => StaffProfile::first()->staff_id,
        ]);
    }

    /**
     * Runs $race once, right after a visitor request read slot availability
     * — its last step before the creating transaction. $race commits a
     * competing visit the way a parallel request would.
     */
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

    /**
     * Runs $race once, right after assign() reads the confirmation window
     * setting — its last read before the creating transaction, after all of
     * its pre-transaction checks.
     */
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

    /** @return array<string, mixed> the exception's errors, failing if $call does not throw */
    private function validationErrors(\Closure $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Expected a ValidationException.');
    }

    /**
     * SQL statements run inside the first transaction $call opens, in order.
     *
     * @return array<int, string>
     */
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

    private function firstIndex(array $statements, \Closure $matches): int
    {
        foreach ($statements as $i => $sql) {
            if ($matches($sql)) {
                return $i;
            }
        }
        $this->fail('Expected statement not found in: '.implode("\n", $statements));
    }

    private function assertVisitorLockedFirst(array $statements): void
    {
        $this->assertMatchesRegularExpression(
            '/^select \* from "visitor_profiles" where "visitor_profiles"\."visitor_id" = \?/',
            $statements[0],
            'The first statement of the transaction is the visitor row lock.'
        );

        $scheduleLock = $this->firstIndex($statements, fn ($sql) => str_starts_with($sql, 'select * from "visit_schedules" where "visit_schedules"."schedule_id" = ?'));
        $duplicateCheck = $this->firstIndex($statements, fn ($sql) => str_starts_with($sql, 'select exists(select * from "visit_requests"'));
        $weeklyCount = $this->firstIndex($statements, fn ($sql) => str_contains($sql, 'COUNT(*) as aggregate') && str_contains($sql, '"visit_requests"."visitor_id"'));

        $this->assertGreaterThan(0, $scheduleLock, 'Visitor lock before schedule lock.');
        $this->assertGreaterThan($scheduleLock, $duplicateCheck, 'Duplicate check after the locks.');
        $this->assertGreaterThan($scheduleLock, $weeklyCount, 'Weekly count after the locks.');
    }

    // ------------------------------------------------- Lock order / scope

    public function test_visitor_request_locks_the_visitor_first_then_schedules_then_checks(): void
    {
        $statements = $this->statementsOfFirstTransaction(fn () => $this->request(self::FRIDAY, '09:00'));

        $this->assertVisitorLockedFirst($statements);
    }

    public function test_whole_day_request_locks_the_visitor_before_both_schedules(): void
    {
        $statements = $this->statementsOfFirstTransaction(fn () => app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor, $this->relationship, CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'), ['09:00', '13:00']
        ));

        $this->assertVisitorLockedFirst($statements);
        $scheduleLocks = array_keys(array_filter($statements, fn ($sql) => str_starts_with($sql, 'select * from "visit_schedules" where "visit_schedules"."schedule_id" = ?')));
        $this->assertCount(2, $scheduleLocks);
        $this->assertGreaterThan(0, min($scheduleLocks));
    }

    public function test_staff_assignment_locks_the_visitor_first_and_checks_duplicates_inside_the_transaction(): void
    {
        $statements = $this->statementsOfFirstTransaction(fn () => $this->assign($this->schedule));

        $this->assertVisitorLockedFirst($statements);
    }

    // ---------------------------------------- Weekly limit, same visitor

    public function test_two_requests_by_the_same_visitor_for_different_sessions_cannot_exceed_the_weekly_limit(): void
    {
        $this->setWeeklyLimit(1);

        // The Sunday request commits while the Friday request is between its
        // availability read (which saw no visit that week) and its checks.
        $this->raceBeforeVisitorRequestTransaction(fn () => $this->request(self::SUNDAY, '09:00'));

        $errors = $this->validationErrors(fn () => $this->request(self::FRIDAY, '09:00'));

        $this->assertSame([VisitAssignmentService::WEEKLY_LIMIT_MESSAGE], $errors['schedule_id']);
        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(self::SUNDAY, VisitRequest::sole()->schedule->schedule_date->toDateString());
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken, 'The refused request reserved no seat.');
    }

    public function test_staff_assignment_and_a_visitor_request_cannot_together_exceed_the_weekly_limit(): void
    {
        $this->setWeeklyLimit(1);
        $this->raceBeforeAssignTransaction(fn () => $this->request(self::SUNDAY, '09:00'));

        $errors = $this->validationErrors(fn () => $this->assign($this->schedule));

        $this->assertSame([VisitAssignmentService::WEEKLY_LIMIT_MESSAGE], $errors['schedule_id']);
        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_the_weekly_limit_still_allows_visits_up_to_the_limit(): void
    {
        $this->setWeeklyLimit(2);
        $this->raceBeforeVisitorRequestTransaction(fn () => $this->request(self::SUNDAY, '09:00'));

        $this->request(self::FRIDAY, '09:00');

        $this->assertSame(2, VisitRequest::count());
    }

    // ------------------------------------------- Same visitor, PDL, day

    public function test_racing_morning_and_afternoon_requests_for_the_same_pdl_create_one_visit(): void
    {
        $this->raceBeforeVisitorRequestTransaction(fn () => $this->request(self::FRIDAY, '13:00'));

        $errors = $this->validationErrors(fn () => $this->request(self::FRIDAY, '09:00'));

        $this->assertSame(['You already have a visit with this PDL on this date.'], $errors['schedule_id']);
        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame(1, (int) $this->afternoonSchedule->fresh()->slots_taken);
    }

    public function test_staff_assignment_rejects_a_same_day_visit_created_after_its_prechecks(): void
    {
        // The visitor's own afternoon request lands after assign() validated
        // its input but before its transaction: only the in-transaction
        // duplicate check can catch it.
        $this->raceBeforeAssignTransaction(fn () => $this->request(self::FRIDAY, '13:00'));

        $errors = $this->validationErrors(fn () => $this->assign($this->schedule));

        $this->assertSame(['This visitor already has a visit with this PDL on the selected date.'], $errors['schedule_id']);
        $this->assertSame(1, VisitRequest::count());
        $this->assertSame('assigned', VisitRequest::sole()->status);
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_staff_assignment_duplicate_is_still_rejected_through_the_web_form(): void
    {
        $this->assign($this->schedule);

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->afternoonSchedule->schedule_id,
            ])
            ->assertSessionHasErrors(['schedule_id' => 'This visitor already has a visit with this PDL on the selected date.']);

        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(0, (int) $this->afternoonSchedule->fresh()->slots_taken);
    }

    public function test_different_visitors_are_not_serialized_against_each_others_limits(): void
    {
        $this->setWeeklyLimit(1);
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'ana',
            'email' => 'ana@example.com',
            'password_hash' => 'x',
            'status' => 'active',
        ]);
        $other = VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => 'Ana Visitor',
            'date_of_birth' => '1980-01-01',
            'gender' => 'female',
            'contact_number' => '09170000000',
            'verification_status' => 'verified',
        ]);
        $otherRelationship = VisitorPdlRelationship::create([
            'visitor_id' => $other->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'immediate_family',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]);

        $this->raceBeforeVisitorRequestTransaction(fn () => $this->request(self::FRIDAY, '09:00', $other, $otherRelationship));
        $this->request(self::FRIDAY, '09:00');

        $this->assertSame(2, VisitRequest::count());
        $this->assertSame(2, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame('full', $this->schedule->fresh()->status);
    }
}
