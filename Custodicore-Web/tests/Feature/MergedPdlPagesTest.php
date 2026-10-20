<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Pdl;
use App\Models\QrCode;
use App\Models\VisitCheckin;
use App\Services\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Custody History and Visitation Tracking were merged into the PDL pages:
 * - each PDL profile has a Custody History timeline (+ Activity Log filter)
 * - the PDL list has a Last Visit column and a Visits filter
 * The old URLs redirect.
 */
class MergedPdlPagesTest extends TestCase
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

    private function otherPdl(): Pdl
    {
        return Pdl::create([
            'pdl_number' => 'PDL-OTHER-1',
            'full_name' => 'Never Visited',
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => $this->pdl->registered_by,
        ]);
    }

    private function checkIn(\Carbon\Carbon $at): void
    {
        $visit = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $qr = QrCode::create([
            'visit_request_id' => $visit->visit_request_id,
            'qr_token' => 'tok-' . $visit->visit_request_id,
            'generated_at' => $at,
            'expires_at' => $at->copy()->addHours(4),
            'status' => 'used',
        ]);
        VisitCheckin::create([
            'visit_request_id' => $visit->visit_request_id,
            'qr_code_id' => $qr->qr_code_id,
            'check_in_time' => $at,
            'status' => 'checked_in',
        ]);
    }

    public function test_old_pages_redirect_and_are_gone_from_the_sidebar(): void
    {
        $this->get('/custody-history')->assertRedirect(route('activity-log.index', ['category' => 'custody']));
        $this->get('/visitation-tracking')->assertRedirect(route('pdl.index', ['visits' => 'stale']));

        $this->get('/dashboard')->assertOk()->assertDontSee('>Custody History<', false)->assertDontSee('Visitation Tracking');
    }

    public function test_pdl_profile_shows_its_custody_history(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web');
        AuditLog::record('update', 'pdl_custody_status', $this->pdl->pdl_id, 'Custody status: active → transferred', \App\Models\Module::CODE_PDL_MANAGEMENT);
        AuditLog::record('update', 'pdl_custody_status', $this->otherPdl()->pdl_id, 'Cell/Block: Dorm 1 → Dorm 9', \App\Models\Module::CODE_PDL_MANAGEMENT);

        $this->get("/pdls/{$this->pdl->pdl_id}")
            ->assertOk()
            ->assertSee('Custody History')
            ->assertSee('Custody status: active → transferred')
            ->assertSee('Admitted to the facility')
            ->assertDontSee('Cell/Block: Dorm 1 → Dorm 9');

        $this->get('/activity-log?category=custody')->assertOk()->assertSee('Custody status: active → transferred');
    }

    public function test_pdl_list_shows_last_visit_and_filters_by_visits(): void
    {
        $never = $this->otherPdl();
        $this->checkIn(now()->subDays(3));

        $this->get('/pdls')->assertOk()
            ->assertSee('Last Visit')
            ->assertSee(now()->subDays(3)->format('M d, Y'))
            ->assertSee('Never visited');

        $this->get('/pdls?visits=never')->assertSee($never->full_name)->assertDontSee($this->pdl->full_name);
        $this->get('/pdls?visits=stale')->assertSee($never->full_name)->assertDontSee($this->pdl->full_name);
    }

    public function test_an_old_visit_counts_as_stale(): void
    {
        $this->checkIn(now()->subDays(45));

        $this->get('/pdls?visits=stale')->assertSee($this->pdl->full_name);
        $this->get('/pdls?visits=never')->assertDontSee($this->pdl->full_name);
    }
}
