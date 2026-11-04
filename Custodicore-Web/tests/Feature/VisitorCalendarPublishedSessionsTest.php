<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FacilityVisitationRule;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * The visitor calendar offers only sessions the Record Officer created and
 * opened in Manage Visitor Slots — never every date the weekly rules allow —
 * and POST /api/visit-requests enforces the same rule.
 *
 * "Now" is Wednesday 2026-10-07 10:00 Asia/Manila. The fixture PDL is
 * non_drug_related with Friday 09:00 and 13:00 and Sunday 09:00 rules; the
 * fixture already has one published session: Fri 2026-10-09 09:00.
 */
class VisitorCalendarPublishedSessionsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FIXTURE_FRIDAY = '2026-10-09';
    private const OPENED_FRIDAY = '2026-10-16';
    private const UNPUBLISHED_FRIDAY = '2026-11-27';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        foreach ([['fri', '13:00:00', '16:30:00'], ['sun', '09:00:00', '11:30:00']] as [$day, $start, $end]) {
            FacilityVisitationRule::create([
                'pdl_classification' => 'non_drug_related',
                'day_of_week' => $day,
                'time_slot_start' => $start,
                'time_slot_end' => $end,
                'max_capacity' => 30,
                'effective_from' => '2024-01-01',
            ]);
        }
    }

    // ------------------------------------------------------------ Helpers

    /** The Record Officer creates a session in Manage Visitor Slots. */
    private function officerCreates(string $date, string $session = 'morning', int $capacity = 10, bool $open = true): VisitSchedule
    {
        $this->actingAs($this->recordOfficerAccount)->post(route('visitor-slots.store'), [
            'schedule_date' => $date,
            'pdl_classification' => 'non_drug_related',
            'session' => $session,
            'max_capacity' => $capacity,
            'is_open' => $open ? '1' : '0',
        ])->assertSessionHasNoErrors();

        return VisitSchedule::whereDate('schedule_date', $date)
            ->where('time_slot_start', $session === 'morning' ? '09:00:00' : '13:00:00')
            ->sole();
    }

    /** @return array<string, mixed> the visitor's availability for the fixture PDL */
    private function calendar(string $from = '2026-10-07', string $to = '2026-12-07'): array
    {
        Sanctum::actingAs($this->visitorAccount);

        return $this->getJson("/api/schedules/availability?from={$from}&to={$to}")
            ->assertOk()
            ->json('relationships.0');
    }

    private function slot(string $date, string $start): array
    {
        $day = collect($this->calendar($date, $date)['days'])->firstWhere('date', $date);

        return collect($day['slots'])->firstWhere('startTime', $start);
    }

    private function request(string $date, array $startTimes = ['09:00'], ?Account $account = null, ?VisitorPdlRelationship $relationship = null): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($account ?? $this->visitorAccount);

        return $this->postJson('/api/visit-requests', [
            'relationshipId' => ($relationship ?? $this->relationship)->relationship_id,
            'date' => $date,
            'startTimes' => $startTimes,
        ]);
    }

    /** @return array{0: Account, 1: VisitorPdlRelationship} a second approved visitor of the fixture PDL */
    private function otherVisitor(): array
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'ana.reyes',
            'email' => 'ana.reyes@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $account->forceFill(['email_verified_at' => now()])->save();
        $visitor = VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => 'Ana C. Reyes',
            'date_of_birth' => '1988-05-02',
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

        return [$account, $relationship];
    }

    // -------------------------------------------------------------- Tests

    public function test_only_dates_the_record_officer_opened_are_green(): void
    {
        $this->officerCreates(self::OPENED_FRIDAY);

        $green = collect($this->calendar()['days'])->where('available', true)->pluck('date')->all();

        // The fixture's published Friday and the newly opened one — not every
        // Friday/Sunday the weekly rules allow.
        $this->assertSame([self::FIXTURE_FRIDAY, self::OPENED_FRIDAY], $green);

        $opened = $this->slot(self::OPENED_FRIDAY, '09:00');
        $this->assertTrue($opened['available']);
        $this->assertSame(10, $opened['slotsRemaining']);
        $this->assertNotNull($opened['scheduleId']);

        // Its afternoon was not opened, so it stays unavailable.
        $this->assertSame('not_published', $this->slot(self::OPENED_FRIDAY, '13:00')['reason']);
    }

    public function test_future_rule_day_without_a_published_session_is_unavailable(): void
    {
        $day = collect($this->calendar('2026-11-20', '2026-11-30')['days'])->firstWhere('date', self::UNPUBLISHED_FRIDAY);

        $this->assertFalse($day['available']);
        $this->assertFalse($day['wholeDay']['available']);
        foreach ($day['slots'] as $slot) {
            $this->assertFalse($slot['available']);
            $this->assertSame('not_published', $slot['reason']);
            $this->assertSame(0, $slot['slotsRemaining']);
            $this->assertNull($slot['scheduleId']);
        }
    }

    public function test_manual_request_for_an_unpublished_date_is_rejected_without_side_effects(): void
    {
        $this->request(self::UNPUBLISHED_FRIDAY, ['09:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'startTime' => 'This visiting session has not been opened by the facility. Choose an available date.',
            ]);
        // Whole day too.
        $this->request(self::UNPUBLISHED_FRIDAY, ['09:00', '13:00'])->assertUnprocessable();

        $this->assertSame(0, VisitRequest::count());
        $this->assertFalse(VisitSchedule::whereDate('schedule_date', self::UNPUBLISHED_FRIDAY)->exists(), 'A request never creates a session.');
    }

    public function test_whole_day_needs_both_sessions_published(): void
    {
        // Only the morning is opened: the whole day cannot be requested.
        $this->officerCreates(self::OPENED_FRIDAY, 'morning');

        $this->request(self::OPENED_FRIDAY, ['09:00', '13:00'])->assertUnprocessable();
        $this->assertSame(0, VisitRequest::count());

        $this->officerCreates(self::OPENED_FRIDAY, 'afternoon');
        $this->request(self::OPENED_FRIDAY, ['09:00', '13:00'])->assertCreated();
    }

    public function test_session_created_closed_is_not_selectable(): void
    {
        $this->officerCreates(self::OPENED_FRIDAY, 'morning', 10, open: false);

        $slot = $this->slot(self::OPENED_FRIDAY, '09:00');
        $this->assertFalse($slot['available']);
        $this->assertSame('closed', $slot['reason']);

        $this->request(self::OPENED_FRIDAY)->assertUnprocessable()->assertJsonValidationErrors(['startTime']);
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_closing_a_published_session_stops_new_requests(): void
    {
        $schedule = $this->officerCreates(self::OPENED_FRIDAY);
        $this->assertTrue($this->slot(self::OPENED_FRIDAY, '09:00')['available']);

        $this->actingAs($this->recordOfficerAccount)
            ->patch(route('visitor-slots.status', $schedule->schedule_id), ['is_open' => '0'])
            ->assertSessionHasNoErrors();

        $slot = $this->slot(self::OPENED_FRIDAY, '09:00');
        $this->assertFalse($slot['available']);
        $this->assertSame('closed', $slot['reason']);
        $this->assertNotContains(self::OPENED_FRIDAY, collect($this->calendar()['days'])->where('available', true)->pluck('date')->all());

        $this->request(self::OPENED_FRIDAY)->assertUnprocessable()->assertJsonValidationErrors(['startTime']);
        $this->assertSame(0, VisitRequest::count());
        $this->assertSame(0, (int) $schedule->fresh()->slots_taken);
    }

    public function test_fully_booked_session_is_not_selectable_or_requestable(): void
    {
        $schedule = $this->officerCreates(self::OPENED_FRIDAY, 'morning', 1);
        [$otherAccount, $otherRelationship] = $this->otherVisitor();
        $this->request(self::OPENED_FRIDAY, ['09:00'], $otherAccount, $otherRelationship)->assertCreated();

        $slot = $this->slot(self::OPENED_FRIDAY, '09:00');
        $this->assertFalse($slot['available']);
        $this->assertSame('full', $slot['reason']);
        $this->assertSame(0, $slot['slotsRemaining']);

        $this->request(self::OPENED_FRIDAY)->assertUnprocessable()->assertJsonValidationErrors(['startTime']);
        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(1, (int) $schedule->fresh()->slots_taken);
    }

    public function test_valid_request_for_an_opened_session_is_accepted_and_awaits_review(): void
    {
        $schedule = $this->officerCreates(self::OPENED_FRIDAY, 'morning', 3);

        $id = $this->request(self::OPENED_FRIDAY)->assertCreated()->json('id');

        $visit = VisitRequest::findOrFail($id);
        $this->assertSame('assigned', $visit->status, 'Still goes to Record Officer review.');
        $this->assertSame($schedule->schedule_id, $visit->schedule_id);
        $this->assertSame(1, (int) $schedule->fresh()->slots_taken);
        $this->assertSame(2, $this->slot(self::OPENED_FRIDAY, '09:00')['slotsRemaining']);
    }

    public function test_session_end_is_judged_in_manila_time(): void
    {
        $this->officerCreates(self::OPENED_FRIDAY);

        // 03:29 UTC is 11:29 Manila: the 09:00–11:30 session is still open.
        $this->travelTo(CarbonImmutable::parse(self::OPENED_FRIDAY.' 03:29:00', 'UTC'));
        $this->assertTrue($this->slot(self::OPENED_FRIDAY, '09:00')['available']);

        // 03:30 UTC is 11:30 Manila: it has ended and cannot be requested.
        $this->travelTo(CarbonImmutable::parse(self::OPENED_FRIDAY.' 03:30:00', 'UTC'));
        $this->assertSame('ended', $this->slot(self::OPENED_FRIDAY, '09:00')['reason']);
        $this->request(self::OPENED_FRIDAY)->assertUnprocessable();
        $this->assertSame(0, VisitRequest::count());
    }
}
