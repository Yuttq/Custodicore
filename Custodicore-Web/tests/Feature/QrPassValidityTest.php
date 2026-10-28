<?php

namespace Tests\Feature;

use App\Models\FacilityVisitationRule;
use App\Models\QrCode;
use App\Models\StaffProfile;
use App\Models\SystemSetting;
use App\Models\VisitCheckin;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * QR pass validity: one pass per visit, valid for the visit day until the
 * visit's last session ends (QrCode::validUntil) — not a fixed time after it
 * was issued. Every scan is still checked by the server against the visit
 * date, the session, the visit status and earlier use.
 *
 * "Now" starts at Wednesday 2026-10-07 10:00 Asia/Manila; the visits are on
 * Friday 2026-10-09: morning 09:00–11:30, afternoon 13:00–16:30.
 */
class QrPassValidityTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

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
    }

    // ------------------------------------------------------------ Helpers

    /** A visitor-submitted visit for $startTimes on Friday, approved by staff. */
    private function confirmedVisit(array $startTimes): VisitRequest
    {
        Sanctum::actingAs($this->visitorAccount);
        $id = $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::FRIDAY,
            'startTimes' => $startTimes,
        ])->assertCreated()->json('id');
        $this->completeRequirements();

        return app(VisitAssignmentService::class)->approveVisitorRequest(VisitRequest::findOrFail($id));
    }

    private function at(string $time, string $date = self::FRIDAY): void
    {
        $this->travelTo(CarbonImmutable::parse("{$date} {$time}:00", 'Asia/Manila'));
    }

    private function fetchQr(VisitRequest $visit): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->visitorAccount);

        return $this->getJson("/api/schedules/{$visit->visit_request_id}/qr");
    }

    private function qrToken(VisitRequest $visit): string
    {
        return $this->fetchQr($visit)->assertOk()->json('qrToken');
    }

    private function scan(string $token, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->frontDeskAccount, 'web');

        return $this->postJson('/front-desk/checkin-checkout/scan', ['qr_token' => $token] + $extra);
    }

    private function checkIn(string $token): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->frontDeskAccount, 'web');

        return $this->postJson('/front-desk/checkin-checkout/confirm', [
            'qr_token' => $token,
            'id_surrendered_type' => 'national_id',
            'id_match_status' => 'matched',
        ]);
    }

    private function activeCheckin(VisitRequest $visit): VisitCheckin
    {
        return VisitCheckin::where('visit_request_id', $visit->visit_request_id)->where('status', 'checked_in')->sole();
    }

    // -------------------------------------------------------------- Tests

    public function test_the_pass_is_valid_until_the_last_session_of_the_visit_ends(): void
    {
        $morning = $this->confirmedVisit(['09:00']);
        $this->qrToken($morning);
        $this->assertSame('2026-10-09 11:30:00', $morning->qrCode()->sole()->expires_at->format('Y-m-d H:i:s'));

        $morning->update(['status' => 'cancelled']);
        $wholeDay = $this->confirmedVisit(['09:00', '13:00']);
        $this->fetchQr($wholeDay)->assertOk()->assertJsonPath('expiresAt', '2026-10-09T16:30:00+08:00');
    }

    public function test_the_pass_is_refused_before_the_visit_date_and_at_manila_midnight_it_is_today(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $token = $this->qrToken($visit);

        // 23:59 Thursday in Manila (15:59 UTC).
        $this->at('23:59', '2026-10-08');
        $this->scan($token)->assertStatus(422)->assertJsonPath('message', 'This QR code is not for today\'s visit.');
        $this->checkIn($token)->assertStatus(422);

        // 00:00 Friday in Manila is still Thursday in UTC: it is the visit day.
        // (The first session of the day has no opening bound, as before.)
        $this->at('00:00');
        $this->scan($token)->assertOk();
        $this->assertSame(0, VisitCheckin::count());
    }

    public function test_the_pass_is_accepted_during_the_session_more_than_three_hours_after_it_was_issued(): void
    {
        $visit = $this->confirmedVisit(['13:00']);
        $this->at('08:00');
        $token = $this->qrToken($visit);

        // Afternoon visitor: not before the afternoon session opens.
        $this->at('12:59');
        $this->assertSame($token, $this->qrToken($visit), 'The app is handed the same pass.');
        $this->scan($token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This visit is for the afternoon session (13:00 - 16:30). Entry opens at 13:00.');

        // Five and a half hours after it was issued, during the session.
        $this->at('13:30');
        $this->scan($token)->assertOk()->assertJsonPath('schedule.session', 'Afternoon session');
        $this->checkIn($token)->assertOk();
        $this->assertSame(1, QrCode::where('visit_request_id', $visit->visit_request_id)->count());
    }

    public function test_a_morning_visitor_may_arrive_early_on_the_day_but_not_after_the_session(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('07:30');
        $token = $this->qrToken($visit);
        $this->scan($token)->assertOk()->assertJsonPath('schedule.session', 'Morning session');

        $this->at('11:31');
        $this->scan($token)->assertStatus(422)->assertJsonPath('message', 'This QR code has expired.');
        $this->checkIn($token)->assertStatus(422);
        $this->assertSame(0, VisitCheckin::count());
    }

    public function test_the_pass_is_refused_after_the_visit_day_and_is_not_reissued(): void
    {
        $visit = $this->confirmedVisit(['09:00', '13:00']);
        $this->at('09:00');
        $token = $this->qrToken($visit);

        $this->at('16:31');
        $this->fetchQr($visit)->assertStatus(409)->assertJsonPath('message', 'A QR pass is not available for this visit.');

        $this->at('09:30', '2026-10-10');
        $this->scan($token)->assertStatus(422);
        $this->checkIn($token)->assertStatus(422);
        $this->fetchQr($visit)->assertStatus(409);
        $this->assertSame($token, QrCode::where('visit_request_id', $visit->visit_request_id)->sole()->qr_token);
        $this->assertSame(0, VisitCheckin::count());
    }

    public function test_a_pass_issued_under_the_old_expiry_keeps_its_token(): void
    {
        $visit = $this->confirmedVisit(['13:00']);
        $this->at('10:00');
        $token = $this->qrToken($visit);
        QrCode::where('qr_token', $token)->update(['expires_at' => now()->addMinutes(180)]);

        $this->at('12:00');
        $this->fetchQr($visit)
            ->assertOk()
            ->assertJsonPath('qrToken', $token)
            ->assertJsonPath('expiresAt', '2026-10-09T16:30:00+08:00');
    }

    public function test_a_checked_in_visitor_can_check_out_after_the_pass_has_expired(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('10:00');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();

        // Still inside after the session ended.
        $this->at('12:00');
        $this->fetchQr($visit)->assertOk()->assertJsonPath('qrToken', $token)->assertJsonPath('checkedIn', true);
        $checkin = $this->activeCheckin($visit);
        $this->scan($token, ['mode' => 'checkout'])->assertOk()->assertJsonPath('checkin_id', $checkin->checkin_id);

        $this->actingAs($this->frontDeskAccount, 'web');
        $this->post("/front-desk/checkin-checkout/{$checkin->checkin_id}/check-out")->assertRedirect();
        $this->assertSame('checked_out', $checkin->fresh()->status);
        $this->assertSame('completed', $visit->fresh()->status);
    }

    public function test_whole_day_visitor_reenters_after_the_midday_break_with_the_same_pass(): void
    {
        $visit = $this->confirmedVisit(['09:00', '13:00']);
        $this->at('08:30');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();

        $this->at('11:30');
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->post("/front-desk/checkin-checkout/{$this->activeCheckin($visit)->checkin_id}/check-out")->assertRedirect();

        $this->at('13:05');
        $this->assertSame($token, $this->qrToken($visit));
        $this->checkIn($token)->assertOk()->assertJsonPath('checkin.session', 'Afternoon session');
        $this->assertSame(2, VisitCheckin::where('visit_request_id', $visit->visit_request_id)->count());
    }

    public function test_a_pass_cannot_check_in_twice(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('09:30');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();

        $this->scan($token)->assertStatus(422);
        $this->checkIn($token)->assertStatus(422);

        // After leaving, the used pass does not admit again.
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->post("/front-desk/checkin-checkout/{$this->activeCheckin($visit)->checkin_id}/check-out")->assertRedirect();
        $this->at('10:30');
        $this->scan($token)->assertStatus(422)->assertJsonPath('message', 'This QR code is no longer active.');
        $this->checkIn($token)->assertStatus(422);
        $this->assertSame(1, VisitCheckin::count());
    }

    public function test_ineligible_visit_statuses_are_refused(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('09:30');
        $token = $this->qrToken($visit);

        foreach (['cancelled', 'declined', 'no_show', 'completed', 'assigned', 'pending_confirmation'] as $status) {
            $visit->update(['status' => $status]);
            $this->scan($token)->assertStatus(422);
            $this->checkIn($token)->assertStatus(422);
            $this->fetchQr($visit)->assertStatus(409);
        }
        $this->assertSame(0, VisitCheckin::count());
    }

    public function test_an_unknown_pass_is_never_admitted(): void
    {
        $this->confirmedVisit(['09:00']);
        $this->at('09:30');

        $this->scan(str_repeat('x', 64))->assertNotFound()->assertJsonPath('success', false);
        $this->checkIn(str_repeat('x', 64))->assertNotFound()->assertJsonPath('success', false);
        $this->assertSame(0, VisitCheckin::count());
    }

    public function test_the_unused_qr_expiry_setting_is_hidden_from_front_desk_but_kept(): void
    {
        SystemSetting::create([
            'setting_key' => 'qr.expiry_minutes',
            'setting_value' => '180',
            'updated_by' => StaffProfile::first()->staff_id,
        ]);

        $this->actingAs($this->frontDeskAccount, 'web');
        $this->get('/front-desk/settings')
            ->assertOk()
            ->assertDontSee('qr.expiry_minutes')
            ->assertDontSee('Minutes a generated QR code stays valid')
            ->assertSee('visit.confirmation_window_hours');

        $this->assertSame('180', SystemSetting::value('qr.expiry_minutes'));
    }
}
