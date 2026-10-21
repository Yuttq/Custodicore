<?php

namespace Tests\Feature;

use App\Models\CellBlock;
use App\Models\Pdl;
use App\Models\VisitorFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * - Visitor flags have a duration and stop counting once it passes.
 * - Summary cards link to the matching list (e.g. Pending Verification → Review Queue).
 */
class FlagDurationAndClickableStatsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    private function flag(string $duration = '30')
    {
        return $this->post("/visitors/{$this->visitor->visitor_id}/flags", [
            'flag_type' => 'rule_violation',
            'description' => 'Brought a phone into the visiting area.',
            'duration' => $duration,
        ]);
    }

    public function test_flag_records_how_long_it_lasts(): void
    {
        $this->flag('30')->assertSessionHas('success', fn ($m) => str_contains($m, 'Flagged until Nov 20, 2026 · 30 days left'));

        $flag = VisitorFlag::sole();
        $this->assertSame('2026-11-20', $flag->expires_at->toDateString());

        $this->get("/visitors/{$this->visitor->visitor_id}")->assertSee('Flagged until Nov 20, 2026 · 30 days left');
        $this->get('/visitors')->assertSee('Until Nov 20, 2026');
    }

    public function test_flag_can_last_until_resolved_which_is_also_the_default(): void
    {
        $this->flag('forever')->assertSessionHasErrors('duration');
        $this->flag('')->assertSessionHasNoErrors(); // no duration sent → until resolved (original behaviour)

        $this->assertNull(VisitorFlag::sole()->expires_at);
        $this->assertSame('Flagged until resolved', VisitorFlag::sole()->durationLabel());
    }

    public function test_expired_flag_stops_counting(): void
    {
        $this->flag('7');
        $this->assertTrue($this->visitor->activeFlags()->exists());

        $this->travel(8)->days();

        $flag = VisitorFlag::sole();
        $this->assertFalse($flag->isActive());
        $this->assertSame('Expired Oct 28, 2026', $flag->durationLabel());
        $this->assertFalse($this->visitor->activeFlags()->exists());
        $this->get("/visitors/{$this->visitor->visitor_id}")->assertSee('EXPIRED');

        // A new flag of the same type is allowed again once the old one expired.
        $this->flag('30')->assertSessionHasNoErrors();
    }

    public function test_stat_cards_link_to_their_lists(): void
    {
        $this->get('/visitors')->assertOk()
            ->assertSee('href="' . route('account-reviews.index') . '"', false)   // Pending Verification → Review Queue
            ->assertSee('href="' . route('eligibility.index') . '"', false)
            ->assertSee('href="' . route('visitor.index', ['flagged' => 1]) . '"', false);

        $this->get('/dashboard')->assertOk()
            ->assertSee('href="' . route('pdl.index', ['status' => 'active']) . '"', false)
            ->assertSee('href="' . route('pdl.index', ['visits' => 'never']) . '"', false);

        $this->get('/pdls')->assertSee('href="' . route('pdl.index', ['status' => 'deceased']) . '"', false);
    }

    public function test_flagged_and_over_capacity_filters(): void
    {
        $this->flag('30');
        $this->get('/visitors?flagged=1')->assertOk()->assertSee($this->visitor->full_name)->assertSee('Showing only visitors with an active flag');

        $over = CellBlock::create(['name' => 'Dorm 9', 'capacity' => 1, 'designation' => 'any']);
        CellBlock::create(['name' => 'Dorm 8', 'capacity' => 10, 'designation' => 'any']);
        foreach ([1, 2] as $i) {
            Pdl::create([
                'pdl_number' => "PDL-OV-{$i}", 'full_name' => "Over {$i}", 'date_of_birth' => '1990-01-01', 'gender' => 'male',
                'classification' => 'drug_related', 'cell_block' => 'Dorm 9', 'admission_date' => '2024-01-01',
                'custody_status' => 'active', 'registered_by' => $this->pdl->registered_by,
            ]);
        }

        $this->get('/cell-blocks?over=1')->assertOk()->assertSee('Dorm 9')->assertDontSee('Dorm 8')->assertSee('click to show all');
    }
}
