<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\StaffProfile;
use App\Models\VisitCheckin;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Front desk check-in (QR and manual) and check-out record the signed-in
 * officer's own staff profile as check_in_officer_id / check_out_officer_id,
 * and are refused without side effects when the account has no staff
 * profile. The fixture's first staff profile is the Record Officer, so a
 * "first profile" fallback would be caught.
 */
class FrontDeskStaffAttributionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const NO_STAFF_PROFILE = 'Your account has no staff profile, so this action cannot be recorded. Ask an administrator to complete your staff profile.';

    private StaffProfile $deskOfficer;
    private Account $secondDeskAccount;
    private StaffProfile $secondDeskOfficer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        $this->deskOfficer = $this->frontDeskAccount->staffProfile;
        $this->secondDeskAccount = $this->frontDeskOfficer('j.ramos');
        $this->secondDeskOfficer = StaffProfile::create([
            'account_id' => $this->secondDeskAccount->account_id,
            'employee_number' => 'EMP-045',
            'full_name' => 'Jose L. Ramos',
            'position' => 'Front Desk Officer',
        ]);
        $this->assertNotSame((int) $this->deskOfficer->staff_id, (int) StaffProfile::first()->staff_id);

        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'x.png',
            'verification_status' => 'verified',
        ]);
    }

    private function frontDeskOfficer(string $username): Account
    {
        return Account::create([
            'role_id' => $this->frontDeskRole->role_id,
            'username' => $username,
            'email' => "{$username}@bjmp.gov.ph",
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
    }

    /** A confirmed visit on its visit morning, with its QR token. */
    private function confirmedVisitWithQr(): array
    {
        // Assign the day before so the confirmation window is still open
        // on the visit morning (same approach as StaffWorkflowRegressionTest).
        $this->travelTo($this->schedule->schedule_date->copy()->subDay()->setTime(10, 0));
        $visit = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);

        $this->travelTo($this->schedule->schedule_date->copy()->setTime(9, 30));

        Sanctum::actingAs($this->visitorAccount);
        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")->assertOk();
        $token = $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertOk()->json('qrToken');

        return [$visit, $token];
    }

    private function confirmQr(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/front-desk/checkin-checkout/confirm', [
            'qr_token' => $token,
            'id_surrendered_type' => 'national_id',
            'id_match_status' => 'matched',
        ]);
    }

    private function manualCheckIn(VisitRequest $visit): \Illuminate\Testing\TestResponse
    {
        return $this->post("/front-desk/checkin-checkout/{$visit->visit_request_id}/check-in", [
            'verification_method' => 'manual_override',
            'id_surrendered_type' => 'national_id',
            'id_number' => 'N01-23-456789',
            'override_reason' => 'Phone battery dead',
        ]);
    }

    private function checkOut(VisitCheckin $checkin): \Illuminate\Testing\TestResponse
    {
        return $this->post("/front-desk/checkin-checkout/{$checkin->checkin_id}/check-out");
    }

    private function checkinFor(VisitRequest $visit): ?VisitCheckin
    {
        return VisitCheckin::where('visit_request_id', $visit->visit_request_id)->first();
    }

    // ---------------------------------------------------- Attribution

    public function test_qr_check_in_and_check_out_record_each_signed_in_officer(): void
    {
        [$visit, $token] = $this->confirmedVisitWithQr();

        $this->actingAs($this->frontDeskAccount, 'web');
        $this->confirmQr($token)->assertOk()->assertJsonPath('success', true);

        $checkin = $this->checkinFor($visit);
        $this->assertSame((int) $this->deskOfficer->staff_id, (int) $checkin->check_in_officer_id);
        $this->assertSame('checked_in', $checkin->status);

        // A different officer lets the visitor out.
        $this->actingAs($this->secondDeskAccount, 'web');
        $this->checkOut($checkin)->assertRedirect()->assertSessionMissing('error');

        $checkin = $checkin->fresh();
        $this->assertSame('checked_out', $checkin->status);
        $this->assertSame((int) $this->secondDeskOfficer->staff_id, (int) $checkin->check_out_officer_id);
        $this->assertSame((int) $this->deskOfficer->staff_id, (int) $checkin->check_in_officer_id);
        $this->assertSame('completed', $visit->fresh()->status);
    }

    public function test_manual_check_in_records_the_signed_in_officer(): void
    {
        [$visit] = $this->confirmedVisitWithQr();

        $this->actingAs($this->secondDeskAccount, 'web');
        $this->manualCheckIn($visit)->assertRedirect()->assertSessionMissing('error');

        $checkin = $this->checkinFor($visit);
        $this->assertSame('checked_in', $checkin->status);
        $this->assertSame((int) $this->secondDeskOfficer->staff_id, (int) $checkin->check_in_officer_id);
    }

    // ------------------------------------------------------- Refusals

    public function test_an_officer_without_a_staff_profile_cannot_check_in(): void
    {
        [$visit, $token] = $this->confirmedVisitWithQr();
        $auditCount = AuditLog::count();

        $this->actingAs($this->frontDeskOfficer('no.profile'), 'web');

        $this->confirmQr($token)->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::NO_STAFF_PROFILE);

        $this->from('/front-desk/checkin-checkout');
        $this->manualCheckIn($visit)->assertRedirect('/front-desk/checkin-checkout')
            ->assertSessionHas('error', self::NO_STAFF_PROFILE);

        $this->assertNull($this->checkinFor($visit));
        $this->assertSame('confirmed', $visit->fresh()->status);
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_an_officer_without_a_staff_profile_cannot_check_out(): void
    {
        [$visit, $token] = $this->confirmedVisitWithQr();
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->confirmQr($token)->assertOk();
        $checkin = $this->checkinFor($visit);

        $this->actingAs($this->frontDeskOfficer('no.profile'), 'web');
        $this->from('/front-desk/checkin-checkout');
        $this->checkOut($checkin)->assertRedirect('/front-desk/checkin-checkout')
            ->assertSessionHas('error', self::NO_STAFF_PROFILE);

        $checkin = $checkin->fresh();
        $this->assertSame('checked_in', $checkin->status);
        $this->assertNull($checkin->check_out_officer_id);
        $this->assertNull($checkin->check_out_time);
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    // ------------------------------------------------ Role restrictions

    public function test_other_roles_still_cannot_check_visitors_in_or_out(): void
    {
        [$visit, $token] = $this->confirmedVisitWithQr();

        // The Record Officer has a staff profile but not the role.
        foreach ([$this->recordOfficerAccount, $this->visitorAccount] as $account) {
            $this->actingAs($account, 'web');
            $this->confirmQr($token)->assertForbidden();
            $this->manualCheckIn($visit)->assertForbidden();
        }
        $this->assertNull($this->checkinFor($visit));

        $this->actingAs($this->frontDeskAccount, 'web');
        $this->confirmQr($token)->assertOk();
        $checkin = $this->checkinFor($visit);

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->checkOut($checkin)->assertForbidden();
        $this->assertSame('checked_in', $checkin->fresh()->status);
    }
}
