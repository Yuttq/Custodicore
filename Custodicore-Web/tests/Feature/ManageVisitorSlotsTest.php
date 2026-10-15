<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FacilityVisitationRule;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\SystemSetting;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use App\Services\VisitSlotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Manage Visitor Slots (Record Officer): dated session capacity, open/close,
 * bookings, weekly default capacity — and that booking respects it.
 *
 * "Now" is pinned to Wednesday 2026-10-07 10:00 Asia/Manila. The fixture PDL
 * is non_drug_related (Fri/Sun). Fri 2026-10-09 09:00 has a visit_schedules
 * row (capacity 2); Fri 13:00 and Sun 09:00 have rules but no rows.
 */
class ManageVisitorSlotsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';
    private const SUNDAY = '2026-10-11';
    private const NEXT_FRIDAY = '2026-10-16';

    private FacilityVisitationRule $afternoonRule;
    private FacilityVisitationRule $sundayRule;
    private Account $wardenAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        $this->afternoonRule = FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'fri',
            'time_slot_start' => '13:00:00',
            'time_slot_end' => '16:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);
        $this->sundayRule = FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'sun',
            'time_slot_start' => '09:00:00',
            'time_slot_end' => '11:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);

        $this->wardenAccount = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'w.cruz',
            'email' => 'w.cruz@bjmp.gov.ph',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
    }

    // ------------------------------------------------------------ Helpers

    private function createSession(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->recordOfficerAccount)->post(route('visitor-slots.store'), array_merge([
            'schedule_date' => self::NEXT_FRIDAY,
            'pdl_classification' => 'non_drug_related',
            'session' => 'morning',
            'max_capacity' => 12,
            'is_open' => '1',
        ], $overrides));
    }

    private function updateSession(VisitSchedule $schedule, int|string $capacity, bool $open = true): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->recordOfficerAccount)->put(route('visitor-slots.update', $schedule->schedule_id), [
            'max_capacity' => $capacity,
            'is_open' => $open ? '1' : '0',
        ]);
    }

    /** POST /api/visit-requests as $account for the Friday morning fixture slot. */
    private function book(Account $account, VisitorPdlRelationship $relationship, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($account);

        return $this->postJson('/api/visit-requests', array_merge([
            'relationshipId' => $relationship->relationship_id,
            'date' => self::FRIDAY,
            'startTime' => '09:00',
        ], $overrides));
    }

    private function bookViaService(VisitorProfile $visitor, VisitorPdlRelationship $relationship, string $date = self::FRIDAY, string $start = '09:00'): VisitRequest
    {
        return app(VisitAssignmentService::class)->submitVisitorRequest(
            $visitor, $relationship, CarbonImmutable::parse($date, 'Asia/Manila'), $start
        );
    }

    /** @return array{0: Account, 1: VisitorProfile, 2: VisitorPdlRelationship} */
    private function makeVisitor(string $username): array
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => $username,
            'email' => "{$username}@example.com",
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $account->forceFill(['email_verified_at' => now()])->save();
        $visitor = VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => ucfirst($username).' Visitor',
            'date_of_birth' => '1980-01-01',
            'gender' => 'female',
            'contact_number' => '09170000000',
            'verification_status' => 'verified',
        ]);
        $relationship = VisitorPdlRelationship::create([
            'visitor_id' => $visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'immediate_family',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]);

        return [$account, $visitor, $relationship];
    }

    /** The Friday morning slot as GET /api/schedules/availability reports it to the fixture visitor. */
    private function availabilitySlot(string $date = self::FRIDAY, string $start = '09:00'): array
    {
        Sanctum::actingAs($this->visitorAccount);

        return collect($this->getJson("/api/schedules/availability?from={$date}&to={$date}")
            ->assertOk()
            ->json('relationships.0.days.0.slots'))->firstWhere('startTime', $start);
    }

    /**
     * Runs $race once, right after a booking request reads slot availability
     * and before it takes the schedule lock — the window a concurrent
     * request or staff change would land in.
     */
    private function bindAvailabilityRace(\Closure $race): void
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

    // ------------------------------------------------------------- Access

    public function test_record_officer_sees_manage_visitor_slots_page_and_navigation(): void
    {
        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor-slots.index'))
            ->assertOk()
            ->assertSee('Manage Visitor Slots')
            ->assertSee('Default Capacity per Weekly Session')
            ->assertSee('Fri, Oct 9, 2026');

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('dashboard'))
            ->assertSee(route('visitor-slots.index'), false);
    }

    public function test_unauthorized_users_cannot_view_create_or_modify_slots(): void
    {
        $before = $this->schedule->only(['max_capacity', 'status', 'slots_taken']);
        $store = route('visitor-slots.store');
        $body = [
            'schedule_date' => self::NEXT_FRIDAY, 'pdl_classification' => 'non_drug_related',
            'session' => 'morning', 'max_capacity' => 5, 'is_open' => '1',
        ];
        $calls = [
            ['get', route('visitor-slots.index'), []],
            ['get', route('visitor-slots.show', $this->schedule->schedule_id), []],
            ['post', $store, $body],
            ['put', route('visitor-slots.update', $this->schedule->schedule_id), ['max_capacity' => 99, 'is_open' => '1']],
            ['patch', route('visitor-slots.status', $this->schedule->schedule_id), ['is_open' => '0']],
            ['put', route('visitor-slots.rules.update', $this->rule->rule_id), ['max_capacity' => 99]],
        ];

        foreach ($calls as [$method, $url, $data]) {
            $this->$method($url, $data)->assertRedirect(route('login'));

            // Visitors (who also have accounts), Front Desk (view-only on
            // scheduling) and the Warden (read-only outside user management).
            foreach ([$this->visitorAccount, $this->frontDeskAccount, $this->wardenAccount] as $account) {
                $this->actingAs($account)->$method($url, $data)->assertForbidden();
            }
            $this->app['auth']->forgetGuards();
        }

        // Nor is there a visitor API to change capacity.
        Sanctum::actingAs($this->visitorAccount);
        $this->putJson('/api/schedules/'.$this->schedule->schedule_id, ['max_capacity' => 99])->assertNotFound();

        $this->assertSame($before, $this->schedule->fresh()->only(['max_capacity', 'status', 'slots_taken']));
        $this->assertSame(2, (int) $this->rule->fresh()->max_capacity);
        $this->assertSame(1, VisitSchedule::count());
    }

    // ------------------------------------------------------------- Create

    public function test_record_officer_creates_a_session_with_capacity(): void
    {
        $response = $this->createSession()->assertSessionHasNoErrors();

        $schedule = VisitSchedule::whereDate('schedule_date', self::NEXT_FRIDAY)->sole();
        $response->assertRedirect(route('visitor-slots.show', $schedule->schedule_id))->assertSessionHas('success');

        $this->assertSame($this->rule->rule_id, (int) $schedule->rule_id);
        $this->assertSame('09:00:00', substr($schedule->time_slot_start, 0, 8));
        $this->assertSame('11:30:00', substr($schedule->time_slot_end, 0, 8));
        $this->assertSame(12, (int) $schedule->max_capacity);
        $this->assertSame(0, (int) $schedule->slots_taken);
        $this->assertSame('open', $schedule->status);

        $this->assertTrue(AuditLog::where('record_type', 'visit_schedules')
            ->where('record_id', $schedule->schedule_id)
            ->where('action_type', 'create')
            ->where('account_id', $this->recordOfficerAccount->account_id)
            ->exists());

        // Visitors see the new capacity.
        $slot = $this->availabilitySlot(self::NEXT_FRIDAY);
        $this->assertTrue($slot['available']);
        $this->assertSame(12, $slot['capacity']);
        $this->assertSame(12, $slot['slotsRemaining']);
        $this->assertSame((string) $schedule->schedule_id, $slot['scheduleId']);
    }

    public function test_afternoon_session_uses_the_afternoon_rule(): void
    {
        $this->createSession(['schedule_date' => self::FRIDAY, 'session' => 'afternoon', 'max_capacity' => 8])
            ->assertSessionHasNoErrors();

        $schedule = VisitSchedule::where('rule_id', $this->afternoonRule->rule_id)->sole();
        $this->assertSame(self::FRIDAY, $schedule->schedule_date->toDateString());
        $this->assertSame('13:00', substr($schedule->time_slot_start, 0, 5));
        $this->assertSame(8, (int) $schedule->max_capacity);
    }

    public function test_session_can_be_created_closed(): void
    {
        $this->createSession(['is_open' => '0'])->assertSessionHasNoErrors();

        $this->assertSame('closed', VisitSchedule::whereDate('schedule_date', self::NEXT_FRIDAY)->sole()->status);
        $slot = $this->availabilitySlot(self::NEXT_FRIDAY);
        $this->assertFalse($slot['available']);
        $this->assertSame('closed', $slot['reason']);
    }

    public function test_an_existing_session_cannot_be_created_twice(): void
    {
        $this->createSession(['schedule_date' => self::FRIDAY])->assertSessionHasErrors('schedule_date');

        $this->assertSame(1, VisitSchedule::count());
        $this->assertSame(2, (int) $this->schedule->fresh()->max_capacity);
    }

    public function test_invalid_capacities_are_rejected(): void
    {
        foreach ([0, -5, 1001, 'abc', '2.5', ''] as $capacity) {
            $this->createSession(['max_capacity' => $capacity])->assertSessionHasErrors('max_capacity');
            $this->updateSession($this->schedule, $capacity)->assertSessionHasErrors('max_capacity');
            $this->actingAs($this->recordOfficerAccount)
                ->put(route('visitor-slots.rules.update', $this->rule->rule_id), ['max_capacity' => $capacity])
                ->assertSessionHasErrors('max_capacity');
        }

        $this->assertSame(1, VisitSchedule::count());
        $this->assertSame(2, (int) $this->schedule->fresh()->max_capacity);
        $this->assertSame(2, (int) $this->rule->fresh()->max_capacity);
    }

    public function test_invalid_dates_and_sessions_are_rejected(): void
    {
        // [input overrides, field expected to carry the error]
        $cases = [
            [['schedule_date' => '2026-10-02'], 'schedule_date'],                       // past Friday
            [['schedule_date' => '16/10/2026'], 'schedule_date'],                       // wrong format
            [['schedule_date' => '2026-02-30'], 'schedule_date'],                       // not a real date
            [['schedule_date' => ''], 'schedule_date'],
            [['schedule_date' => '2027-10-15'], 'schedule_date'],                       // beyond the 365-day horizon
            [['schedule_date' => '2026-10-14'], 'session'],                             // Wednesday: no visiting rule
            [['pdl_classification' => 'drug_related'], 'session'],                      // Friday is not a drug-related day
            [['session' => 'evening'], 'session'],
            [['pdl_classification' => 'everyone'], 'pdl_classification'],
        ];

        foreach ($cases as [$overrides, $field]) {
            $this->createSession($overrides)->assertSessionHasErrors($field);
        }

        $this->assertSame(1, VisitSchedule::count());
    }

    // Fixture rules: non-drug-related Fri 09:00, Fri 13:00, Sun 09:00; no drug-related rules.

    public function test_a_weekday_without_visiting_names_the_configured_visiting_days(): void
    {
        $this->createSession(['schedule_date' => '2026-10-15', 'session' => 'afternoon']) // Thursday
            ->assertSessionHasErrors([
                'session' => 'Non-drug-related PDLs have no visiting on Thursdays. Their configured visiting days are Friday and Sunday.',
            ]);
        $this->createSession(['schedule_date' => '2026-10-15', 'session' => 'morning'])
            ->assertSessionHasErrors([
                'session' => 'Non-drug-related PDLs have no visiting on Thursdays. Their configured visiting days are Friday and Sunday.',
            ]);

        $this->assertSame(1, VisitSchedule::count());
    }

    public function test_listed_visiting_days_come_from_the_weekly_rules(): void
    {
        FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related', 'day_of_week' => 'wed',
            'time_slot_start' => '09:00:00', 'time_slot_end' => '11:30:00',
            'max_capacity' => 30, 'effective_from' => '2024-01-01',
        ]);
        // Not in effect on the requested date, so not listed.
        FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related', 'day_of_week' => 'mon',
            'time_slot_start' => '09:00:00', 'time_slot_end' => '11:30:00',
            'max_capacity' => 30, 'effective_from' => '2024-01-01', 'effective_to' => '2025-12-31',
        ]);

        $this->createSession(['schedule_date' => '2026-10-15']) // Thursday
            ->assertSessionHasErrors([
                'session' => 'Non-drug-related PDLs have no visiting on Thursdays. Their configured visiting days are Wednesday, Friday and Sunday.',
            ]);
    }

    public function test_a_classification_without_any_rules_says_so(): void
    {
        $this->createSession(['pdl_classification' => 'drug_related']) // Friday
            ->assertSessionHasErrors([
                'session' => 'Drug-related PDLs have no visiting on Fridays. No visiting days are currently configured for them.',
            ]);
        $this->assertSame(1, VisitSchedule::count());
    }

    public function test_a_visiting_day_without_the_chosen_session_keeps_a_session_specific_error(): void
    {
        // Sunday has only a morning rule.
        $this->createSession(['schedule_date' => self::SUNDAY, 'session' => 'afternoon'])
            ->assertSessionHasErrors([
                'session' => 'There is no afternoon visiting session for non-drug-related PDLs on Sundays. Sessions configured that day: morning.',
            ]);
        $this->assertSame(0, VisitSchedule::where('rule_id', $this->sundayRule->rule_id)->count());

        // The configured session that day is still accepted.
        $this->createSession(['schedule_date' => self::SUNDAY, 'session' => 'morning'])->assertSessionHasNoErrors();
    }

    public function test_a_rule_outside_its_effective_dates_cannot_be_scheduled(): void
    {
        $this->rule->update(['effective_to' => '2026-10-10']);

        $this->createSession(['schedule_date' => self::NEXT_FRIDAY])->assertSessionHasErrors('session');
        $this->assertSame(1, VisitSchedule::count());

        // Every Friday rule has ended by then: not "no visiting on Fridays".
        $this->afternoonRule->update(['effective_to' => '2026-10-10']);
        $this->createSession(['schedule_date' => self::NEXT_FRIDAY])->assertSessionHasErrors([
            'session' => 'Visiting for non-drug-related PDLs on Fridays is not in effect on Oct 16, 2026.',
        ]);
    }

    // ------------------------------------------------------------- Update

    public function test_capacity_can_be_increased_and_reopens_a_full_session(): void
    {
        [, $visitor2, $relationship2] = $this->makeVisitor('ana');
        $this->bookViaService($this->visitor, $this->relationship);
        $this->bookViaService($visitor2, $relationship2);
        $this->assertSame('full', $this->schedule->fresh()->status);

        $this->updateSession($this->schedule, 5)->assertSessionHasNoErrors()->assertSessionHas('success');

        $schedule = $this->schedule->fresh();
        $this->assertSame(5, (int) $schedule->max_capacity);
        $this->assertSame(2, (int) $schedule->slots_taken);
        $this->assertSame('open', $schedule->status);
        $this->assertTrue(AuditLog::where('record_type', 'visit_schedules')
            ->where('record_id', $schedule->schedule_id)
            ->where('description', 'like', '%capacity 2 → 5%')
            ->exists());

        [$account3, , $relationship3] = $this->makeVisitor('bea');
        $this->book($account3, $relationship3)->assertCreated();
        $this->assertSame(3, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_capacity_can_be_reduced_down_to_but_not_below_active_bookings(): void
    {
        $this->schedule->update(['max_capacity' => 10]);
        [, $visitor2, $relationship2] = $this->makeVisitor('ana');
        [, $visitor3, $relationship3] = $this->makeVisitor('bea');
        $this->bookViaService($this->visitor, $this->relationship);
        $this->bookViaService($visitor2, $relationship2);
        $this->bookViaService($visitor3, $relationship3);

        $this->updateSession($this->schedule, 2)->assertSessionHasErrors([
            'max_capacity' => 'This session has 3 active bookings, so its capacity cannot be lower than 3.',
        ]);
        $this->assertSame(10, (int) $this->schedule->fresh()->max_capacity);

        $this->updateSession($this->schedule, 3)->assertSessionHasNoErrors();
        $schedule = $this->schedule->fresh();
        $this->assertSame(3, (int) $schedule->max_capacity);
        $this->assertSame('full', $schedule->status);

        $this->assertSame(3, VisitRequest::where('status', 'assigned')->count(), 'No booking is cancelled by a capacity change.');
    }

    public function test_cancelled_and_declined_bookings_release_capacity(): void
    {
        $this->schedule->update(['max_capacity' => 5]);
        [, $visitor2, $relationship2] = $this->makeVisitor('ana');
        [, $visitor3, $relationship3] = $this->makeVisitor('bea');
        $rejected = $this->bookViaService($this->visitor, $this->relationship);
        $this->bookViaService($visitor2, $relationship2);

        // A staff-assigned visit the visitor then declines.
        $declined = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $visitor3->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $relationship3->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $this->assertSame(3, (int) $this->schedule->fresh()->slots_taken);

        app(VisitAssignmentService::class)->rejectVisitorRequest($rejected, 'PDL unavailable.');
        app(VisitAssignmentService::class)->declineAssignedVisit($declined, 'Cannot attend.');

        $schedule = $this->schedule->fresh();
        $this->assertSame(1, (int) $schedule->slots_taken);
        $summary = app(VisitSlotService::class)->summaries(collect([$schedule]))[$schedule->schedule_id];
        $this->assertSame(1, $summary['booked']);
        $this->assertSame(4, $summary['remaining']);

        // Only the one seat-holding booking limits the reduction.
        $this->updateSession($this->schedule, 1)->assertSessionHasNoErrors();
        $this->assertSame('full', $this->schedule->fresh()->status);
    }

    public function test_remaining_capacity_counts_only_seat_holding_statuses(): void
    {
        $this->schedule->update(['max_capacity' => 10]);
        foreach (VisitAssignmentService::CAPACITY_STATUSES as $i => $status) {
            [, $v, $r] = $this->makeVisitor("holds{$i}");
            VisitRequest::create([
                'visitor_id' => $v->visitor_id, 'pdl_id' => $this->pdl->pdl_id, 'relationship_id' => $r->relationship_id,
                'schedule_id' => $this->schedule->schedule_id, 'status' => $status,
            ]);
        }
        foreach (['cancelled', 'declined'] as $i => $status) {
            [, $v, $r] = $this->makeVisitor("released{$i}");
            VisitRequest::create([
                'visitor_id' => $v->visitor_id, 'pdl_id' => $this->pdl->pdl_id, 'relationship_id' => $r->relationship_id,
                'schedule_id' => $this->schedule->schedule_id, 'status' => $status,
            ]);
        }

        $summary = app(VisitSlotService::class)->summaries(collect([$this->schedule->fresh()]))[$this->schedule->schedule_id];
        $this->assertSame(['capacity' => 10, 'booked' => 5, 'held' => 5, 'remaining' => 5, 'accepting' => true], $summary);

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor-slots.show', $this->schedule->schedule_id))
            ->assertOk()
            ->assertSee('Bookings (7)')
            ->assertSee('Awaiting visitor confirmation')
            ->assertSee('Declined by visitor')
            ->assertSee('Released');

        // The visitor calendar agrees.
        $this->assertSame(5, $this->availabilitySlot()['slotsRemaining']);
    }

    public function test_full_session_rejects_additional_bookings(): void
    {
        $this->bookViaService($this->visitor, $this->relationship);
        $this->updateSession($this->schedule, 1)->assertSessionHasNoErrors();
        $this->assertSame('full', $this->schedule->fresh()->status);

        [$account2, $visitor2, $relationship2] = $this->makeVisitor('ana');
        $this->book($account2, $relationship2)->assertUnprocessable()->assertJsonValidationErrors('startTime');

        // Staff assignment respects it too.
        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visits.assign', $visitor2->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $relationship2->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ])
            ->assertSessionHasErrors('schedule_id');

        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_closing_a_session_blocks_new_bookings_and_keeps_existing_ones(): void
    {
        $existing = $this->bookViaService($this->visitor, $this->relationship);

        $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor-slots.index'))
            ->patch(route('visitor-slots.status', $this->schedule->schedule_id), ['is_open' => '0'])
            ->assertRedirect(route('visitor-slots.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame('closed', $this->schedule->fresh()->status);
        $this->assertSame(2, (int) $this->schedule->fresh()->max_capacity, 'Closing keeps the capacity.');

        [$account2, $visitor2, $relationship2] = $this->makeVisitor('ana');
        $this->book($account2, $relationship2)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['startTime' => 'This visiting slot is closed. Choose another available slot.']);

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visits.assign', $visitor2->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $relationship2->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ])
            ->assertSessionHasErrors('schedule_id');

        $slot = $this->availabilitySlot();
        $this->assertSame('closed', $slot['reason']);
        $this->assertSame(0, $slot['slotsRemaining']);

        // The existing booking is untouched and can still be approved.
        $this->assertSame('assigned', $existing->fresh()->status);
        $this->makeEligibleForApproval($existing);
        app(VisitAssignmentService::class)->approveVisitorRequest($existing);
        $this->assertSame('confirmed', $existing->fresh()->status);
        $this->assertSame(1, VisitRequest::count());
    }

    public function test_a_released_seat_on_a_closed_session_keeps_it_closed(): void
    {
        $visit = $this->bookViaService($this->visitor, $this->relationship);
        app(VisitSlotService::class)->setOpen($this->schedule, false);

        app(VisitAssignmentService::class)->rejectVisitorRequest($visit, 'Reason.');

        $schedule = $this->schedule->fresh();
        $this->assertSame('closed', $schedule->status);
        $this->assertSame(0, (int) $schedule->slots_taken);
    }

    public function test_reopened_session_accepts_bookings_again(): void
    {
        app(VisitSlotService::class)->setOpen($this->schedule, false);

        $this->actingAs($this->recordOfficerAccount)
            ->patch(route('visitor-slots.status', $this->schedule->schedule_id), ['is_open' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('open', $this->schedule->fresh()->status);

        $this->book($this->visitorAccount, $this->relationship)->assertCreated();
    }

    public function test_reopening_a_session_with_every_seat_booked_marks_it_full(): void
    {
        [, $visitor2, $relationship2] = $this->makeVisitor('ana');
        $this->bookViaService($this->visitor, $this->relationship);
        $this->bookViaService($visitor2, $relationship2);
        app(VisitSlotService::class)->setOpen($this->schedule, false);

        app(VisitSlotService::class)->setOpen($this->schedule, true);

        $this->assertSame('full', $this->schedule->fresh()->status);
    }

    public function test_past_session_cannot_be_changed(): void
    {
        $past = VisitSchedule::create([
            'rule_id' => $this->rule->rule_id,
            'schedule_date' => '2026-10-02',
            'time_slot_start' => '09:00:00',
            'time_slot_end' => '11:30:00',
            'max_capacity' => 2,
            'slots_taken' => 0,
            'status' => 'open',
        ]);

        $this->updateSession($past, 10)->assertSessionHasErrors('schedule_date');
        $this->actingAs($this->recordOfficerAccount)
            ->patch(route('visitor-slots.status', $past->schedule_id), ['is_open' => '0'])
            ->assertSessionHasErrors('schedule_date');

        $this->assertSame(['max_capacity' => 2, 'status' => 'open'], $past->fresh()->only(['max_capacity', 'status']));

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor-slots.show', $past->schedule_id))
            ->assertOk()
            ->assertSee('already ended')
            ->assertDontSee('Save Changes');
    }

    public function test_session_cannot_be_changed_or_created_after_its_end_time_today(): void
    {
        // Friday 11:29: the morning session (09:00–11:30) is still running.
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 11:29:00', 'Asia/Manila'));
        $this->updateSession($this->schedule, 5)->assertSessionHasNoErrors();

        // 11:30 sharp: ended (same boundary as the visitor calendar's `ended`).
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 11:30:00', 'Asia/Manila'));
        $this->updateSession($this->schedule, 9)->assertSessionHasErrors([
            'schedule_date' => 'This session has already ended and can no longer be changed.',
        ]);
        $this->actingAs($this->recordOfficerAccount)
            ->patch(route('visitor-slots.status', $this->schedule->schedule_id), ['is_open' => '0'])
            ->assertSessionHasErrors('schedule_date');
        $this->assertSame(['max_capacity' => 5, 'status' => 'open'], $this->schedule->fresh()->only(['max_capacity', 'status']));

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor-slots.index', ['from' => self::FRIDAY]))
            ->assertOk()
            ->assertSee('>View<', false)
            ->assertDontSee(route('visitor-slots.status', $this->schedule->schedule_id), false);

        // Today's afternoon session has not ended: it can still be created and edited.
        $this->createSession(['schedule_date' => self::FRIDAY, 'session' => 'afternoon', 'max_capacity' => 6])
            ->assertSessionHasNoErrors();
        $afternoon = VisitSchedule::where('rule_id', $this->afternoonRule->rule_id)->sole();
        $this->updateSession($afternoon, 8)->assertSessionHasNoErrors();

        // After 16:30 the afternoon is over too, and an ended session cannot be created.
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 16:30:00', 'Asia/Manila'));
        $this->updateSession($afternoon, 9)->assertSessionHasErrors('schedule_date');
        $afternoon->delete();
        $this->createSession(['schedule_date' => self::FRIDAY, 'session' => 'afternoon'])
            ->assertSessionHasErrors(['session' => "Today's afternoon session has already ended."]);
        $this->assertSame(0, VisitSchedule::where('rule_id', $this->afternoonRule->rule_id)->count());
    }

    public function test_open_close_keeps_a_capacity_saved_concurrently_by_another_officer(): void
    {
        // Officer A clicks Close; officer B's capacity change (2 -> 10) lands
        // after A's request loaded the session but before A takes the lock.
        $this->onNextTransaction(fn () => app(VisitSlotService::class)->update($this->schedule, 10, true));

        $closed = app(VisitSlotService::class)->setOpen($this->schedule, false);

        $this->assertSame(10, (int) $closed->max_capacity);
        $this->assertSame(['max_capacity' => 10, 'status' => 'closed'], $this->schedule->fresh()->only(['max_capacity', 'status']));

        // Same for reopening.
        $this->onNextTransaction(fn () => app(VisitSlotService::class)->update($this->schedule, 4, false));
        app(VisitSlotService::class)->setOpen($this->schedule, true);
        $this->assertSame(['max_capacity' => 4, 'status' => 'open'], $this->schedule->fresh()->only(['max_capacity', 'status']));
    }

    public function test_close_and_reopen_never_bypass_capacity_when_seats_exceed_capacity(): void
    {
        // Legacy data: more seat-holding bookings than the stored capacity.
        [, $visitor2, $relationship2] = $this->makeVisitor('ana');
        $this->bookViaService($this->visitor, $this->relationship);
        $this->bookViaService($visitor2, $relationship2);
        $this->schedule->update(['max_capacity' => 1]);

        // Closing is never blocked by the capacity floor...
        app(VisitSlotService::class)->setOpen($this->schedule, false);
        $this->assertSame('closed', $this->schedule->fresh()->status);

        // ...and reopening keeps capacity and derives `full`, not `open`.
        app(VisitSlotService::class)->setOpen($this->schedule, true);
        $this->assertSame(['max_capacity' => 1, 'status' => 'full'], $this->schedule->fresh()->only(['max_capacity', 'status']));

        [$account3, , $relationship3] = $this->makeVisitor('bea');
        $this->book($account3, $relationship3)->assertUnprocessable();
        $this->assertSame(2, VisitRequest::count());

        // Setting a capacity explicitly still enforces the floor.
        $this->updateSession($this->schedule, 1)->assertSessionHasErrors('max_capacity');
    }

    /** Runs $race once, when the next database transaction begins (before its first statement). */
    private function onNextTransaction(\Closure $race): void
    {
        $ran = false;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$ran, $race) {
            if (! $ran) {
                $ran = true;
                $race();
            }
        });
    }

    public function test_saving_resets_a_stale_seat_counter_to_real_bookings(): void
    {
        // e.g. seeded demo data: the counter says full, nobody is booked.
        $this->schedule->update(['slots_taken' => 2, 'status' => 'full']);
        $summary = app(VisitSlotService::class)->summaries(collect([$this->schedule->fresh()]))[$this->schedule->schedule_id];
        $this->assertSame(0, $summary['booked']);
        $this->assertSame(0, $summary['remaining'], 'Until saved, remaining follows what booking enforces.');

        $this->updateSession($this->schedule, 2)->assertSessionHasNoErrors();

        $schedule = $this->schedule->fresh();
        $this->assertSame(0, (int) $schedule->slots_taken);
        $this->assertSame('open', $schedule->status);
    }

    // -------------------------------------------------------- Concurrency

    public function test_last_seat_taken_concurrently_rejects_the_second_booking(): void
    {
        $this->updateSession($this->schedule, 1)->assertSessionHasNoErrors();
        [, $visitor2, $relationship2] = $this->makeVisitor('ana');

        // Both requests see one seat left; the other one books it first.
        $this->bindAvailabilityRace(fn () => $this->bookViaService($visitor2, $relationship2));

        $this->book($this->visitorAccount, $this->relationship)->assertUnprocessable();

        $this->assertSame(1, VisitRequest::count());
        $this->assertSame($visitor2->visitor_id, (int) VisitRequest::sole()->visitor_id);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame('full', $this->schedule->fresh()->status);
    }

    public function test_capacity_reduced_while_a_booking_is_in_flight_rejects_it(): void
    {
        $this->schedule->update(['max_capacity' => 5]);
        [, $visitor2, $relationship2] = $this->makeVisitor('ana');
        $this->bookViaService($visitor2, $relationship2);

        $this->bindAvailabilityRace(fn () => app(VisitSlotService::class)->update($this->schedule, 1, true));

        $this->book($this->visitorAccount, $this->relationship)->assertUnprocessable();

        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(['max_capacity' => 1, 'slots_taken' => 1, 'status' => 'full'],
            $this->schedule->fresh()->only(['max_capacity', 'slots_taken', 'status']));
    }

    public function test_session_closed_while_a_booking_is_in_flight_rejects_it(): void
    {
        $this->bindAvailabilityRace(fn () => app(VisitSlotService::class)->setOpen($this->schedule, false));

        $this->book($this->visitorAccount, $this->relationship)->assertUnprocessable();

        $this->assertSame(0, VisitRequest::count());
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_capacity_cannot_be_reduced_below_a_booking_made_after_the_page_was_loaded(): void
    {
        $this->schedule->update(['max_capacity' => 5]);
        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor-slots.show', $this->schedule->schedule_id))
            ->assertSee("Capacity can't go below the 0 active bookings", false);

        // Two visitors book while the officer's form is open.
        [, $visitor2, $relationship2] = $this->makeVisitor('ana');
        $this->bookViaService($this->visitor, $this->relationship);
        $this->bookViaService($visitor2, $relationship2);

        // The booked count is re-read under the row lock, not taken from the form.
        $this->updateSession($this->schedule, 1)->assertSessionHasErrors('max_capacity');
        $this->assertSame(5, (int) $this->schedule->fresh()->max_capacity);
    }

    // ----------------------------------------------------- Default capacity

    public function test_default_rule_capacity_applies_to_new_sessions_only(): void
    {
        $this->actingAs($this->recordOfficerAccount)
            ->put(route('visitor-slots.rules.update', $this->afternoonRule->rule_id), ['max_capacity' => 7])
            ->assertRedirect(route('visitor-slots.index'))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->recordOfficerAccount)
            ->put(route('visitor-slots.rules.update', $this->rule->rule_id), ['max_capacity' => 40])
            ->assertSessionHasNoErrors();

        $this->assertSame(7, (int) $this->afternoonRule->fresh()->max_capacity);
        $this->assertSame(2, (int) $this->schedule->fresh()->max_capacity, 'The existing Friday morning session keeps its capacity.');

        // A date with no session row yet shows, and books at, the new default.
        $slot = $this->availabilitySlot(self::FRIDAY, '13:00');
        $this->assertSame(7, $slot['capacity']);
        $this->assertNull($slot['scheduleId']);

        $this->book($this->visitorAccount, $this->relationship, ['startTime' => '13:00'])->assertCreated();
        $this->assertSame(7, (int) VisitSchedule::where('rule_id', $this->afternoonRule->rule_id)->sole()->max_capacity);

        $this->assertTrue(AuditLog::where('record_type', 'facility_visitation_rules')
            ->where('record_id', $this->afternoonRule->rule_id)
            ->where('description', 'like', '%30 → 7%')
            ->exists());
    }

    // ------------------------------------------- Unchanged existing rules

    public function test_weekly_visit_limit_is_unchanged_by_slot_management(): void
    {
        SystemSetting::create([
            'setting_key' => 'visit.max_per_week',
            'setting_value' => '1',
            'updated_by' => StaffProfile::first()->staff_id,
        ]);
        $this->bookViaService($this->visitor, $this->relationship);

        // Plenty of room on Sunday, same Mon–Sun week.
        $this->createSession(['schedule_date' => self::SUNDAY, 'max_capacity' => 50])->assertSessionHasNoErrors();
        $sunday = VisitSchedule::where('rule_id', $this->sundayRule->rule_id)->sole();
        $this->updateSession($sunday, 100)->assertSessionHasNoErrors();

        $slot = $this->availabilitySlot(self::SUNDAY);
        $this->assertSame('weekly_limit', $slot['reason']);
        $this->assertSame(100, $slot['slotsRemaining']);

        $this->book($this->visitorAccount, $this->relationship, ['date' => self::SUNDAY])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule_id' => VisitAssignmentService::WEEKLY_LIMIT_MESSAGE]);

        $this->assertSame('1', SystemSetting::value('visit.max_per_week'));
        $this->assertSame(1, VisitRequest::count());
    }

    public function test_staff_assignment_confirmation_and_qr_still_work_after_capacity_change(): void
    {
        $this->updateSession($this->schedule, 4)->assertSessionHasNoErrors();

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ])
            ->assertSessionHasNoErrors();
        $visit = VisitRequest::sole();
        $this->assertSame('pending_confirmation', $visit->status);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);

        Sanctum::actingAs($this->visitorAccount);
        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")->assertOk();
        $this->assertSame('confirmed', $visit->fresh()->status);
        $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertOk();

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor-slots.show', $this->schedule->schedule_id))
            ->assertOk()
            ->assertSee($this->visitor->full_name)
            ->assertSee('Confirmed');
    }
}
