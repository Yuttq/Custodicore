<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\QrCode;
use App\Models\Role;
use App\Models\VisitCheckin;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Front Desk sidebar badges: visitors inside (Check-In / Check-Out), walk-ins
 * waiting for a decision (Walk-in Visitors) and today's approved visits not
 * arrived yet (Visit Schedule). Shown only when there is something.
 */
class FrontDeskNavBadgesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-19 15:00'));
    }

    private function visit(): VisitRequest
    {
        return VisitRequest::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
            'status' => 'confirmed',
        ]);
    }

    private function checkIn(VisitRequest $visit): void
    {
        $qr = QrCode::create([
            'visit_request_id' => $visit->visit_request_id,
            'qr_token' => Str::random(64),
            'generated_at' => now(),
            'status' => 'used',
        ]);

        VisitCheckin::create([
            'visit_request_id' => $visit->visit_request_id,
            'qr_code_id' => $qr->qr_code_id,
            'check_in_time' => now(),
            'check_in_officer_id' => $this->frontDeskAccount->staffProfile->staff_id,
            'verification_method' => 'qr_scan',
            'status' => 'checked_in',
        ]);
    }

    private function walkIn(string $status): VisitorProfile
    {
        $account = Account::create([
            'role_id' => Role::where('role_name', 'Visitor')->firstOrFail()->role_id,
            'username' => 'walkin.'.Str::random(8),
            'password_hash' => Hash::make(Str::random(64)),
            'status' => 'active',
        ]);

        return VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => 'Lourdes Reyes',
            'date_of_birth' => '1970-05-02',
            'gender' => 'female',
            'contact_number' => 'N/A',
            'verification_status' => $status,
            'registered_via' => 'walk_in',
        ]);
    }

    public function test_no_badges_when_there_is_nothing_to_do(): void
    {
        $this->actingAs($this->frontDeskAccount)
            ->getJson(route('frontdesk.nav-badges'))
            ->assertOk()
            ->assertExactJson(['checkin' => null, 'walk-ins' => null, 'schedule' => null]);

        $this->get(route('frontdesk.dashboard'))->assertOk()->assertDontSee('cc-admin-badge" title', false);
    }

    public function test_badges_count_what_the_gate_still_has_to_do(): void
    {
        // Today's schedule: one visitor inside, two still to arrive.
        $this->schedule->update(['schedule_date' => today()->toDateString()]);
        $this->checkIn($this->visit());
        $this->visit();
        $this->visit();
        // One walk-in waiting for a decision; a rejected one does not count.
        $this->walkIn('pending');
        $this->walkIn('rejected');

        $badges = $this->actingAs($this->frontDeskAccount)->getJson(route('frontdesk.nav-badges'))->assertOk()->json();

        $this->assertStringContainsString('1 visitor is inside now', $badges['checkin']);
        $this->assertStringContainsString('>1</span>', $badges['checkin']);
        $this->assertStringContainsString('1 walk-in is waiting for your decision', $badges['walk-ins']);
        $this->assertStringContainsString(e(route('frontdesk.walk-ins.index', ['status' => 'pending'])), $badges['walk-ins']);
        $this->assertStringContainsString('2 approved visits today, not arrived yet', $badges['schedule']);
        $this->assertStringContainsString('>2</span>', $badges['schedule']);

        // The sidebar shows them on every Front Desk page.
        $this->get(route('frontdesk.attendance-log'))->assertOk()
            ->assertSeeInOrder(['Check-In / Check-Out', '1 visitor is inside now', 'Walk-in Visitors', '1 walk-in is waiting', 'Visit Schedule', '2 approved visits today'], false);
    }

    public function test_only_front_desk_officers_get_the_badges(): void
    {
        $this->actingAs($this->recordOfficerAccount)->getJson(route('frontdesk.nav-badges'))->assertForbidden();
        $this->actingAs($this->visitorAccount)->getJson(route('frontdesk.nav-badges'))->assertForbidden();
    }
}
