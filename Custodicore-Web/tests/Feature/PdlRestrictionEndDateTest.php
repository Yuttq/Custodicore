<?php

namespace Tests\Feature;

use App\Models\PdlRestriction;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * A restriction past its end date stops applying on its own (bookings and
 * the gate only count the dates it covers). The PDL page says so: "Ended",
 * not "Active", and there is nothing left to lift.
 */
class PdlRestrictionEndDateTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();
    }

    private function restriction(string $start, ?string $end): PdlRestriction
    {
        return PdlRestriction::withoutEvents(fn () => PdlRestriction::create([
            'pdl_id' => $this->pdl->pdl_id,
            'restriction_type' => 'quarantine',
            'status' => 'active',
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Medical quarantine',
            'imposed_by' => StaffProfile::first()->staff_id,
        ]));
    }

    public function test_a_restriction_past_its_end_date_shows_as_ended_without_a_lift_button(): void
    {
        $ended = $this->restriction('2026-09-01', '2026-10-06');

        $this->assertTrue($ended->hasEnded());
        $this->assertFalse($ended->isCurrent());
        $this->assertTrue($this->pdl->isVisitableOn('2026-10-07'));

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->get(route('pdl.show', $this->pdl->pdl_id))
            ->assertOk()
            ->assertSee('Ended')
            ->assertDontSee('Visitation blocked while a restriction is active')
            ->assertDontSee(route('pdl.restrictions.lift', [$this->pdl->pdl_id, $ended->restriction_id]), false);
    }

    public function test_a_restriction_ending_today_is_still_active_and_can_be_lifted(): void
    {
        $current = $this->restriction('2026-09-01', '2026-10-07');

        $this->assertTrue($current->isCurrent());
        $this->actingAs($this->recordOfficerAccount, 'web')
            ->get(route('pdl.show', $this->pdl->pdl_id))
            ->assertOk()
            ->assertSee('Visitation blocked while a restriction is active')
            ->assertSee(route('pdl.restrictions.lift', [$this->pdl->pdl_id, $current->restriction_id]), false);
    }

    public function test_an_ended_restriction_cannot_be_lifted(): void
    {
        $ended = $this->restriction('2026-09-01', '2026-10-06');

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->patch(route('pdl.restrictions.lift', [$this->pdl->pdl_id, $ended->restriction_id]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already ended on Oct 6, 2026'));

        $this->assertSame('active', $ended->fresh()->status);
    }

    public function test_an_open_ended_restriction_never_ends_by_itself(): void
    {
        $this->assertTrue($this->restriction('2026-09-01', null)->isCurrent());
    }

    // ------------------------------------------- Lifted at midnight, no officer

    public function test_a_restriction_ending_on_the_24th_is_lifted_at_midnight_when_the_25th_begins(): void
    {
        $restriction = $this->restriction('2026-11-10', '2026-11-24');
        $openEnded = $this->restriction('2026-11-10', null);

        // The 24th, late evening: still the last day it covers.
        $this->travelTo(CarbonImmutable::parse('2026-11-24 23:59:00', 'Asia/Manila'));
        $this->artisan('restrictions:lift-ended')->expectsOutput('Lifted ended restrictions: 0')->assertSuccessful();
        $this->assertSame('active', $restriction->fresh()->status);
        $this->assertFalse($this->pdl->fresh()->isVisitableOn('2026-11-24'));

        // Midnight, the 25th: lifted with no one pressing Lift.
        $this->travelTo(CarbonImmutable::parse('2026-11-25 00:00:00', 'Asia/Manila'));
        $this->artisan('restrictions:lift-ended')->expectsOutput('Lifted ended restrictions: 1')->assertSuccessful();

        $restriction->refresh();
        $this->assertSame('lifted', $restriction->status);
        $this->assertSame('2026-11-24', $restriction->end_date->toDateString(), 'Its end date is kept.');
        $this->assertFalse($restriction->coversDate('2026-11-25'));
        $this->assertSame('active', $openEnded->fresh()->status, 'No end date: stays until an officer lifts it.');
        $this->assertTrue(\App\Models\AuditLog::where('record_type', 'pdl_restrictions')
            ->where('record_id', $restriction->restriction_id)
            ->where('description', 'like', 'System lifted quarantine restriction%automatically: it ended on Nov 24, 2026')
            ->exists());

        // A second run changes nothing.
        $this->artisan('restrictions:lift-ended')->expectsOutput('Lifted ended restrictions: 0')->assertSuccessful();
    }

    public function test_lifting_runs_every_day_at_midnight(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'restrictions:lift-ended'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }
}
