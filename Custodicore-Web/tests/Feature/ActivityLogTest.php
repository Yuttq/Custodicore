<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CellBlock;
use App\Models\VisitorId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Record Officer activity log: actions are recorded, each visitor shows its
 * own history, and the Activity Log page lists/filters everything.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    public function test_previously_unlogged_actions_are_recorded(): void
    {
        $this->post("/visitors/{$this->visitor->visitor_id}/flags", ['flag_type' => 'rule_violation', 'description' => 'Brought a phone into the visiting area.']);
        $this->post("/pdls/{$this->pdl->pdl_id}/legal-records", ['case_number' => 'CR-1', 'offense_code' => 'theft']);
        $this->post("/pdls/{$this->pdl->pdl_id}/restrictions", ['restriction_type' => 'quarantine', 'start_date' => now()->toDateString(), 'reason' => 'Medical quarantine']);

        foreach (['visitor_flags', 'pdl_legal_records', 'pdl_restrictions'] as $type) {
            $this->assertTrue(AuditLog::where('record_type', $type)->exists(), "{$type} not logged");
        }
        $this->assertSame($this->recordOfficerAccount->account_id, AuditLog::where('record_type', 'visitor_flags')->value('account_id'));
    }

    public function test_visitor_page_shows_that_visitors_history_only(): void
    {
        $id = VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id, 'id_type' => 'passport', 'id_number' => 'P1',
            'file_path' => 'visitor-ids/p.png', 'verification_status' => 'pending',
        ]);
        $this->post("/visitors/{$this->visitor->visitor_id}/ids/{$id->visitor_id_doc_id}/verify");
        // Something unrelated to this visitor.
        CellBlock::create(['name' => 'Dorm 9', 'capacity' => 5, 'designation' => 'any']);
        $this->post('/cell-blocks', ['name' => 'Dorm 10', 'capacity' => 5, 'designation' => 'any']);

        $this->get("/visitors/{$this->visitor->visitor_id}")
            ->assertOk()
            ->assertSee('Activity History')
            ->assertSee("Verified Passport for {$this->visitor->full_name}")
            ->assertSee('Registered in the CustodiCore app')
            ->assertDontSee('Added cell block Dorm 10');
    }

    public function test_activity_log_page_lists_and_filters(): void
    {
        $this->post("/visitors/{$this->visitor->visitor_id}/flags", ['flag_type' => 'rule_violation', 'description' => 'Brought a phone into the visiting area.']);
        $this->post('/cell-blocks', ['name' => 'Dorm 10', 'capacity' => 5, 'designation' => 'any']);

        $this->get('/activity-log')
            ->assertOk()
            ->assertSee("Flagged {$this->visitor->full_name}")
            ->assertSee('Added cell block Dorm 10')
            ->assertSee('Today')
            ->assertSee(route('visitor.show', $this->visitor->visitor_id), false);

        $this->get('/activity-log?category=cells')->assertSee('Added cell block Dorm 10')->assertDontSee("Flagged {$this->visitor->full_name}");
        $this->get('/activity-log?q=Dorm+10')->assertSee('Added cell block Dorm 10')->assertDontSee("Flagged {$this->visitor->full_name}");
        $this->get('/activity-log?who=me')->assertSee('Added cell block Dorm 10');
        $this->get('/activity-log?from=' . now()->addDay()->toDateString())->assertSee('No activity matches these filters.');
    }

    public function test_only_record_officers_can_open_the_activity_log(): void
    {
        $this->actingAs($this->frontDeskAccount, 'web')->get('/activity-log')->assertForbidden();
    }
}
