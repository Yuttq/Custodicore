<?php

namespace Tests\Feature;

use App\Models\CellBlock;
use App\Models\FacilityVisitationRule;
use App\Models\Pdl;
use App\Models\PdlLegalRecord;
use App\Services\NavBadges;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Records Officer sidebar badges: real counts of work still to do, shown
 * only when there is something, refreshed by GET /nav-badges.
 */
class NavBadgesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday; the fixture's Friday morning session is published.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->completeRequirements();
        $this->withoutVite();
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    private function badges(): array
    {
        return app(NavBadges::class)->all();
    }

    public function test_nothing_to_do_shows_no_badges(): void
    {
        $this->assertSame([], array_filter($this->badges()));
    }

    public function test_unpublished_sessions_in_the_next_week_warn_on_manage_visitor_slots(): void
    {
        // A Sunday afternoon session exists in the weekly rules but is not published.
        FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related', 'day_of_week' => 'sun',
            'time_slot_start' => '13:00:00', 'time_slot_end' => '16:30:00', 'max_capacity' => 30, 'effective_from' => '2024-01-01',
        ]);

        $this->assertSame(['kind' => 'warn', 'value' => 1], array_intersect_key($this->badges()['slots'], ['kind' => 0, 'value' => 0]));
        $this->get('/dashboard')->assertSee('class="nav-count is-warn"', false)->assertSee('not published yet, so visitors cannot book it');
    }

    public function test_dorms_over_capacity_warn_on_cell_blocks(): void
    {
        $dorm = CellBlock::create(['name' => 'Dorm 9', 'capacity' => 1, 'designation' => 'any']);
        $this->pdl->update(['cell_block' => 'Dorm 9']);
        $this->assertNull($this->badges()['cells']);

        Pdl::create([
            'pdl_number' => 'PDL-X-2', 'full_name' => 'Second Occupant', 'date_of_birth' => '1990-01-01', 'gender' => 'male',
            'classification' => 'non_drug_related', 'cell_block' => $dorm->name, 'admission_date' => '2024-01-01',
            'custody_status' => 'active', 'registered_by' => $this->pdl->registered_by,
        ]);
        $this->assertSame('1 dorm is over the recommended capacity', $this->badges()['cells']['title']);
    }

    public function test_hearings_this_week_show_on_pdl_records(): void
    {
        $record = PdlLegalRecord::create([
            'pdl_id' => $this->pdl->pdl_id, 'case_number' => 'CR-1', 'offense_code' => 'theft', 'offense' => 'Theft',
            'case_status' => PdlLegalRecord::PENDING, 'recorded_by' => $this->recordOfficerAccount->staffProfile->staff_id,
        ]);
        $record->hearings()->create(['hearing_at' => now()->addDays(3)->setTime(9, 0)]);
        $record->hearings()->create(['hearing_at' => now()->addDays(30)]); // too far ahead

        $this->assertSame(['kind' => 'info', 'value' => 1], array_intersect_key($this->badges()['pdls'], ['kind' => 0, 'value' => 0]));

        $record->update(['case_status' => PdlLegalRecord::DISMISSED]);
        $this->assertNull($this->badges()['pdls']);
    }

    public function test_requests_count_until_decided_and_todays_visits_show_on_upcoming(): void
    {
        $visit = app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor, $this->relationship, CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'), '09:00'
        );
        $this->assertSame(1, $this->badges()['visitors']['value']);
        $this->assertSame(1, $this->badges()['review']['value']);

        app(VisitAssignmentService::class)->approveVisitorRequest($visit);
        $this->assertNull($this->badges()['visitors']);
        $this->assertNull($this->badges()['review']);
        $this->assertNull($this->badges()['upcoming']);

        $this->travelTo(CarbonImmutable::parse(self::FRIDAY . ' 07:00:00', 'Asia/Manila'));
        $this->assertSame('1 confirmed visit today', $this->badges()['upcoming']['title']);
    }

    public function test_endpoint_returns_rendered_badges_for_record_officers_only(): void
    {
        app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor, $this->relationship, CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'), '09:00'
        );

        $this->getJson('/nav-badges')->assertOk()
            ->assertJsonPath('review', '<span class="nav-count" title="1 waiting for review" data-href="' . route('review-queue') . '">1</span>')
            ->assertJsonPath('cells', null);

        $this->actingAs($this->frontDeskAccount, 'web')->getJson('/nav-badges')->assertForbidden();
    }

    public function test_upcoming_visits_does_not_count_visits_waiting_for_the_visitor(): void
    {
        // A staff-assigned visit the visitor hasn't confirmed: not "who is coming today".
        app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id, 'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id, 'schedule_id' => $this->schedule->schedule_id,
        ]);

        $this->assertNull($this->badges()['upcoming']);
    }
}
