<?php

namespace Tests\Feature;

use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * GET /api/visits/upcoming returns the earliest assigned / pending /
 * confirmed visit dated today or later (Asia/Manila), matching the mobile
 * pickNextVisit rule. Past visits not yet closed by `visits:expire` are skipped.
 *
 * "Now" is pinned to Wednesday 2026-10-07 10:00 Asia/Manila; the fixture
 * schedule is Fri 2026-10-09 09:00. Extra schedules are also Fridays so they
 * match the fixture's Friday rule.
 */
class VisitorUpcomingVisitTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        Sanctum::actingAs($this->visitorAccount);
    }

    private function fridaySchedule(string $date): VisitSchedule
    {
        return VisitSchedule::create([
            'rule_id' => $this->rule->rule_id,
            'schedule_date' => $date,
            'time_slot_start' => '09:00:00',
            'time_slot_end' => '11:30:00',
            'max_capacity' => 2,
            'slots_taken' => 0,
            'status' => 'open',
        ]);
    }

    /** A visit row in an arbitrary status, bypassing the service (no seat reserved). */
    private function visitOn(VisitSchedule $schedule, string $status): VisitRequest
    {
        return VisitRequest::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $schedule->schedule_id,
            'status' => $status,
            'confirmation_deadline' => now()->addHours(48),
        ]);
    }

    public function test_past_visit_awaiting_expiry_is_skipped(): void
    {
        // Created first, so the old assigned_at ordering would have picked it.
        $this->visitOn($this->fridaySchedule('2026-10-02'), 'confirmed');
        $future = $this->visitOn($this->schedule, 'confirmed');

        $this->getJson('/api/visits/upcoming')
            ->assertOk()
            ->assertJsonPath('id', (string) $future->visit_request_id)
            ->assertJsonPath('scheduledAt', '2026-10-09T09:00:00+08:00');
    }

    public function test_earliest_visit_date_wins_over_earliest_assignment(): void
    {
        $this->visitOn($this->fridaySchedule('2026-10-16'), 'confirmed');
        $earlier = $this->visitOn($this->schedule, 'pending_confirmation');

        $this->getJson('/api/visits/upcoming')
            ->assertOk()
            ->assertJsonPath('id', (string) $earlier->visit_request_id)
            ->assertJsonPath('status', 'pending_confirmation');
    }

    public function test_visit_today_is_included_after_its_slot_ends(): void
    {
        $visit = $this->visitOn($this->schedule, 'assigned');
        $this->travelTo(CarbonImmutable::parse('2026-10-09 15:00:00', 'Asia/Manila'));

        $this->getJson('/api/visits/upcoming')
            ->assertOk()
            ->assertJsonPath('id', (string) $visit->visit_request_id);
    }

    public function test_returns_null_when_only_past_or_closed_visits_exist(): void
    {
        $this->visitOn($this->fridaySchedule('2026-10-02'), 'confirmed');
        $this->visitOn($this->fridaySchedule('2026-09-25'), 'pending_confirmation');
        $this->visitOn($this->schedule, 'cancelled');
        $this->visitOn($this->fridaySchedule('2026-10-16'), 'declined');

        $this->getJson('/api/visits/upcoming')
            ->assertOk()
            ->assertExactJson(['visit' => null]);
    }
}
