<?php

namespace Tests\Feature;

use App\Models\QrCode;
use App\Models\VisitCheckin;
use App\Models\VisitorId;
use App\Services\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Regressions found in Phase 5 end-to-end testing:
 * - Record Officer visitor page (assign form) failed to compile.
 * - Front Desk QR check-out scan rejected the (already used) QR.
 * Plus QR states that must never allow check-in.
 */
class StaffWorkflowRegressionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
    }

    private function confirmedVisitWithQr(): array
    {
        // Assign the day before the visit, so the default confirmation
        // window (visit.confirmation_window_hours = 48, from now) is still
        // open on the visit morning — whatever day the suite runs on. The
        // schedule date is the next Friday from the real date, so assigning
        // at the real "now" could be up to 7 days before it.
        $this->travelTo($this->schedule->schedule_date->copy()->subDay()->setTime(10, 0));
        $visit = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $this->assertTrue($visit->confirmation_deadline->gt($this->schedule->schedule_date->copy()->setTime(9, 30)));

        // QR passes are only valid on the visit day.
        $this->travelTo($this->schedule->schedule_date->copy()->setTime(9, 30));

        Sanctum::actingAs($this->visitorAccount);
        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")->assertOk();
        $token = $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertOk()->json('qrToken');

        return [$visit, $token];
    }

    public function test_record_officer_visitor_page_renders_assign_form(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->get("/visitors/{$this->visitor->visitor_id}")
            ->assertOk()
            ->assertSee('assignVisitForm', false)
            ->assertSee('schedulesByClassification', false)
            ->assertSee((string) $this->schedule->schedule_id, false);
    }

    public function test_front_desk_checkout_scan_finds_active_checkin_for_used_qr(): void
    {
        [$visit, $token] = $this->confirmedVisitWithQr();
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'x.png',
            'verification_status' => 'verified',
        ]);

        $this->actingAs($this->frontDeskAccount, 'web');

        // Before check-in, a checkout scan is refused.
        $this->postJson('/front-desk/checkin-checkout/scan', ['qr_token' => $token, 'mode' => 'checkout'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This visitor is not currently checked in.');

        $this->postJson('/front-desk/checkin-checkout/scan', ['qr_token' => $token])->assertOk();
        $this->postJson('/front-desk/checkin-checkout/confirm', [
            'qr_token' => $token,
            'id_surrendered_type' => 'national_id',
            'id_match_status' => 'matched',
        ])->assertOk();

        $checkin = VisitCheckin::where('visit_request_id', $visit->visit_request_id)->firstOrFail();

        // Check-in mode still refuses the used QR (no double check-in)…
        $this->postJson('/front-desk/checkin-checkout/scan', ['qr_token' => $token])->assertStatus(422);

        // …but the exit scan returns the active check-in.
        $this->postJson('/front-desk/checkin-checkout/scan', ['qr_token' => $token, 'mode' => 'checkout'])
            ->assertOk()
            ->assertJsonPath('checkin_id', $checkin->checkin_id)
            ->assertJsonPath('checkin.status', 'checked_in')
            ->assertJsonPath('visitor.name', 'Maria D. Santos');

        $this->post("/front-desk/checkin-checkout/{$checkin->checkin_id}/check-out")->assertRedirect();
        $this->assertSame('completed', $visit->fresh()->status);

        // After check-out the exit scan is refused again.
        $this->postJson('/front-desk/checkin-checkout/scan', ['qr_token' => $token, 'mode' => 'checkout'])
            ->assertStatus(422);
    }

    public function test_checked_in_visitor_keeps_the_same_pass_for_check_out_after_it_expires(): void
    {
        [$visit, $token] = $this->confirmedVisitWithQr();

        $this->actingAs($this->frontDeskAccount, 'web');
        $this->postJson('/front-desk/checkin-checkout/confirm', [
            'qr_token' => $token,
            'id_surrendered_type' => 'national_id',
            'id_match_status' => 'matched',
        ])->assertOk();

        $qr = QrCode::where('visit_request_id', $visit->visit_request_id)->firstOrFail();
        $this->assertSame('used', $qr->status);

        // Past the 180-minute expiry, still inside: the app gets the same
        // pass back, and the QR row is left as it was.
        $this->travel(181)->minutes();

        Sanctum::actingAs($this->visitorAccount);
        $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")
            ->assertOk()
            ->assertJsonPath('qrToken', $token)
            ->assertJsonPath('checkedIn', true);

        $after = $qr->fresh();
        $this->assertSame($token, $after->qr_token);
        $this->assertSame('used', $after->status);
        $this->assertTrue($qr->expires_at->equalTo($after->expires_at));
        $this->assertSame(1, QrCode::where('visit_request_id', $visit->visit_request_id)->count());

        // The expired pass still finds the visitor at the exit…
        $this->actingAs($this->frontDeskAccount, 'web');
        $checkin = VisitCheckin::where('visit_request_id', $visit->visit_request_id)->firstOrFail();
        $this->postJson('/front-desk/checkin-checkout/scan', ['qr_token' => $token, 'mode' => 'checkout'])
            ->assertOk()
            ->assertJsonPath('checkin_id', $checkin->checkin_id);

        // …but cannot check the visitor in again.
        $this->postJson('/front-desk/checkin-checkout/scan', ['qr_token' => $token])->assertStatus(422);
        $this->postJson('/front-desk/checkin-checkout/confirm', [
            'qr_token' => $token,
            'id_surrendered_type' => 'national_id',
            'id_match_status' => 'matched',
        ])->assertStatus(422);
        $this->assertSame(1, VisitCheckin::where('visit_request_id', $visit->visit_request_id)->count());

        $this->post("/front-desk/checkin-checkout/{$checkin->checkin_id}/check-out")->assertRedirect();
        $this->assertSame('completed', $visit->fresh()->status);

        // After the final check-out the pass is gone again.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertStatus(409);
    }

    public function test_expired_qr_cannot_check_in(): void
    {
        [, $token] = $this->confirmedVisitWithQr();
        $this->travel(181)->minutes(); // qr.expiry default is 180 minutes

        $this->actingAs($this->frontDeskAccount, 'web');
        $this->postJson('/front-desk/checkin-checkout/scan', ['qr_token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This QR code has expired.');
        $this->assertSame('expired', QrCode::where('qr_token', $token)->value('status'));
    }

    public function test_cancelled_visit_qr_cannot_check_in(): void
    {
        [$visit, $token] = $this->confirmedVisitWithQr();
        $visit->update(['status' => 'cancelled']);

        $this->actingAs($this->frontDeskAccount, 'web');
        $this->postJson('/front-desk/checkin-checkout/scan', ['qr_token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This visit is not confirmed.');

        Sanctum::actingAs($this->visitorAccount);
        $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertStatus(409);
    }

    public function test_visitor_cannot_use_staff_pages(): void
    {
        $this->actingAs($this->visitorAccount, 'web');
        $this->get("/visitors/{$this->visitor->visitor_id}")->assertForbidden();
        $this->get('/front-desk/checkin-checkout')->assertForbidden();
    }
}
