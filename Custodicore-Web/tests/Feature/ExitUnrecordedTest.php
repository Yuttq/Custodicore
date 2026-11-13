<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FacilityVisitationRule;
use App\Models\QrCode;
use App\Models\Role;
use App\Models\VisitCheckin;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * L8: a visitor still marked inside after the visit ended because nobody
 * recorded the check-out. "Close record – exit not recorded" ends the gate
 * record as `exit_unrecorded` without inventing an exit: no check-out time,
 * officer or ID return. Explanation and password required; audit logged;
 * only once the visit's last session has ended; never done by the nightly job.
 *
 * Visits are on Friday 2026-10-09 (morning 09:00–11:30, afternoon 13:00–16:30).
 */
class ExitUnrecordedTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    private const SATURDAY = '2026-10-10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'fri',
            'time_slot_start' => '13:00:00',
            'time_slot_end' => '16:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);

        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'x.png',
            'verification_status' => 'verified',
        ]);

        $this->publishSessions('2026-10-01', '2026-11-30');
    }

    // ------------------------------------------------------------ Helpers

    private function at(string $time, string $date = self::FRIDAY): void
    {
        $this->travelTo(CarbonImmutable::parse("{$date} {$time}:00", 'Asia/Manila'));
    }

    /** A confirmed visit on Friday, checked in at the gate at 09:30 and never checked out. */
    private function visitLeftInside(array $startTimes = ['09:00']): VisitRequest
    {
        Sanctum::actingAs($this->visitorAccount);
        $id = $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::FRIDAY,
            'startTimes' => $startTimes,
        ])->assertCreated()->json('id');
        $this->completeRequirements();
        $visit = app(VisitAssignmentService::class)->approveVisitorRequest(VisitRequest::findOrFail($id));

        $this->at('09:30');
        $token = $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertOk()->json('qrToken');
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->postJson('/front-desk/checkin-checkout/confirm', [
            'qr_token' => $token,
            'id_surrendered_type' => 'national_id',
            'id_match_status' => 'matched',
        ])->assertOk();

        return $visit;
    }

    private function openCheckin(VisitRequest $visit): VisitCheckin
    {
        return VisitCheckin::where('visit_request_id', $visit->visit_request_id)->where('status', 'checked_in')->sole();
    }

    private function close(VisitCheckin $checkin, array $input = []): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->frontDeskAccount, 'web');

        return $this->from('/front-desk/schedule/inside')->post(
            "/front-desk/checkin-checkout/{$checkin->checkin_id}/close-unrecorded",
            $input + [
                'reason' => 'Visitor left at closing time; check-out was not recorded.',
                'id_at_desk' => 'no',
                'current_password' => 'password',
            ]
        );
    }

    // -------------------------------------------------------------- Tests

    public function test_closing_records_no_exit_and_ends_the_visit(): void
    {
        $visit = $this->visitLeftInside();
        $checkin = $this->openCheckin($visit);

        $this->at('08:00', self::SATURDAY);
        $this->close($checkin, ['id_at_desk' => 'yes'])
            ->assertRedirect('/front-desk/schedule/inside')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $checkin->refresh();
        $this->assertSame(VisitCheckin::STATUS_EXIT_UNRECORDED, $checkin->status);
        $this->assertNull($checkin->check_out_time, 'No exit time is invented.');
        $this->assertNull($checkin->check_out_officer_id);
        $this->assertNull($checkin->id_returned_time);
        $this->assertNotNull($checkin->check_in_time, 'The real check-in is kept.');

        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertSame('used', QrCode::where('visit_request_id', $visit->visit_request_id)->value('status'));

        $log = AuditLog::where('record_type', 'visit_checkins')->where('record_id', $checkin->checkin_id)->where('action_type', 'update')->sole();
        $this->assertSame('update', $log->action_type);
        $this->assertSame($this->frontDeskAccount->account_id, $log->account_id);
        $this->assertStringContainsString('exit not recorded', $log->description);
        $this->assertStringContainsString('still at desk: yes', $log->description);
        $this->assertStringContainsString('check-out was not recorded', $log->description);

        // No longer inside anywhere; the visitor's app stops showing the pass.
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->getJson('/front-desk/dashboard/stats')->assertJsonPath('inside', 0);
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertStatus(409);
        $this->getJson('/api/visits/history')->assertOk()->assertJsonFragment(['id' => (string) $visit->visit_request_id]);
    }

    public function test_the_officers_password_is_required(): void
    {
        $checkin = $this->openCheckin($this->visitLeftInside());
        $this->at('08:00', self::SATURDAY);

        $this->close($checkin, ['current_password' => ''])->assertSessionHasErrors('current_password');
        $this->close($checkin, ['current_password' => 'wrong-password'])->assertSessionHasErrors('current_password');

        $this->assertSame('checked_in', $checkin->fresh()->status);
        $this->assertSame(0, AuditLog::where('record_type', 'visit_checkins')->where('action_type', 'update')->count());
    }

    public function test_an_explanation_and_the_id_answer_are_required(): void
    {
        $checkin = $this->openCheckin($this->visitLeftInside());
        $this->at('08:00', self::SATURDAY);

        $this->close($checkin, ['reason' => 'forgot'])->assertSessionHasErrors('reason');
        $this->close($checkin, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->close($checkin, ['id_at_desk' => ''])->assertSessionHasErrors('id_at_desk');
        $this->close($checkin, ['id_at_desk' => 'maybe'])->assertSessionHasErrors('id_at_desk');

        $this->assertSame('checked_in', $checkin->fresh()->status);
    }

    public function test_a_record_cannot_be_closed_before_the_visit_ends(): void
    {
        $visit = $this->visitLeftInside(['09:00', '13:00']);
        $checkin = $this->openCheckin($visit);

        // Whole-day visit: still within the visit at 15:00.
        $this->at('15:00');
        $this->close($checkin)->assertSessionHas('error');
        $this->assertSame('checked_in', $checkin->fresh()->status);
        $this->assertSame('confirmed', $visit->fresh()->status);

        // After the last session ended (16:30) it can be.
        $this->at('16:45');
        $this->close($checkin)->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertSame(VisitCheckin::STATUS_EXIT_UNRECORDED, $checkin->fresh()->status);
    }

    public function test_only_a_record_still_marked_inside_can_be_closed(): void
    {
        $visit = $this->visitLeftInside();
        $checkin = $this->openCheckin($visit);

        $this->at('12:00');
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->post("/front-desk/checkin-checkout/{$checkin->checkin_id}/check-out")->assertRedirect();

        $this->close($checkin)->assertSessionHas('error', 'This visitor is not marked inside any more.');
        $this->assertSame('checked_out', $checkin->fresh()->status);
        $this->assertSame(0, AuditLog::where('record_type', 'visit_checkins')->where('action_type', 'update')->count());
    }

    public function test_a_closed_record_is_never_checked_out_afterwards_or_closed_twice(): void
    {
        $checkin = $this->openCheckin($this->visitLeftInside());
        $this->at('08:00', self::SATURDAY);
        $this->close($checkin)->assertSessionHas('status');

        $this->actingAs($this->frontDeskAccount, 'web');
        $this->post("/front-desk/checkin-checkout/{$checkin->checkin_id}/check-out")->assertSessionHas('error');
        $this->close($checkin)->assertSessionHas('error');

        $this->assertNull($checkin->fresh()->check_out_time);
        $this->assertSame(VisitCheckin::STATUS_EXIT_UNRECORDED, $checkin->fresh()->status);
        $this->assertSame(1, AuditLog::where('record_type', 'visit_checkins')->where('action_type', 'update')->count());
    }

    public function test_the_nightly_job_never_closes_a_record_on_its_own(): void
    {
        $visit = $this->visitLeftInside();
        $checkin = $this->openCheckin($visit);

        $this->at('00:05', self::SATURDAY);
        $this->artisan('visits:expire')->assertSuccessful();

        $this->assertSame('checked_in', $checkin->fresh()->status);
        $this->assertNull($checkin->fresh()->check_out_time);
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_the_dashboard_warns_about_previous_day_records_still_inside(): void
    {
        $visit = $this->visitLeftInside();
        $this->actingAs($this->frontDeskAccount, 'web');

        // Same day: not yet a previous-day record.
        $this->at('16:00');
        $this->get('/front-desk')->assertOk()->assertDontSee('still marked inside from a previous day');

        $this->at('08:00', self::SATURDAY);
        $this->get('/front-desk')->assertOk()->assertSee('1 visitor still marked inside from a previous day');

        $this->close($this->openCheckin($visit))->assertSessionHas('status');
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->get('/front-desk')->assertOk()->assertDontSee('still marked inside from a previous day');
    }

    public function test_the_action_shows_on_overstayed_rows_and_the_never_checked_out_tab(): void
    {
        $visit = $this->visitLeftInside();
        $this->actingAs($this->frontDeskAccount, 'web');

        $this->at('11:00');
        $this->get('/front-desk/schedule/inside')->assertOk()->assertDontSee('Close record – exit not recorded');

        $this->at('11:45');
        $this->get('/front-desk/schedule/inside')->assertOk()->assertSee('Close record – exit not recorded');
        $this->get('/front-desk/checkin-checkout')->assertOk()->assertSee('Close record – exit not recorded');

        $this->at('08:00', self::SATURDAY);
        $this->get('/front-desk/schedule/inside?date='.self::FRIDAY)
            ->assertOk()
            ->assertSee('Never Checked Out')
            ->assertSee('Close record – exit not recorded');

        // After closing: labelled, and no longer on the inside lists.
        $this->close($this->openCheckin($visit))->assertSessionHas('status');
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->get('/front-desk/schedule/inside?date='.self::FRIDAY)->assertOk()->assertDontSee('Close record – exit not recorded');
        $this->get('/front-desk/schedule/expected?date='.self::FRIDAY)->assertOk()->assertSee('Exit not recorded');
        $this->get('/front-desk/attendance-log?date='.self::FRIDAY)->assertOk()->assertSee('Exit Not Recorded')->assertSee('Return not recorded');
    }

    public function test_the_admin_record_reads_exit_not_recorded_not_still_inside(): void
    {
        $visit = $this->visitLeftInside();
        $this->at('08:00', self::SATURDAY);
        $this->close($this->openCheckin($visit))->assertSessionHas('status');

        $admin = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'warden',
            'email' => 'warden@bjmp.gov.ph',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'web');
        $this->get(route('admin.checkins.index'))
            ->assertOk()
            ->assertSee('Exit Not Recorded')
            ->assertSee('Not recorded')
            ->assertDontSee('Still inside');
    }
}
