<?php

namespace Tests\Feature;

use App\Models\FacilityVisitationRule;
use App\Models\Notification;
use App\Models\Pdl;
use App\Models\PdlRestriction;
use App\Models\QrCode;
use App\Models\StaffProfile;
use App\Models\VisitCheckin;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Models\VisitSession;
use App\Services\VisitAssignmentService;
use App\Services\VisitGateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * The QR workflow fixes from the gate audit:
 *  - PDL eligibility at the gate (custody / restriction on the day), no
 *    override, check-out never blocked; restrictions respect their dates;
 *    upcoming visits are cancelled (generic visitor message) when the PDL
 *    becomes unavailable;
 *  - a whole-day visitor leaving after the afternoon session started is
 *    checked out for good (no re-entry);
 *  - the Overstayed badge;
 *  - a scan in check-in mode of a visitor already inside;
 *  - a visitor is inside for only one visit at a time (another PDL today,
 *    or an exit left unrecorded on an earlier day); check-out never blocked;
 *  - check-in opens 30 minutes before the first session of the day (08:30)
 *    and at its own start for a later session (13:00), so the midday break
 *    stays closed.
 *
 * "Now" starts at Wednesday 2026-10-07 10:00 Asia/Manila; visits are on
 * Friday 2026-10-09 (morning 09:00–11:30, afternoon 13:00–16:30) or the
 * Friday after, 2026-10-16.
 */
class QrWorkflowFixesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    private const NEXT_FRIDAY = '2026-10-16';

    private const ALREADY_INSIDE = 'This visitor is already inside. Switch to Check-Out and record their check-out.';

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

    /** A visitor-submitted visit on $date for $startTimes, approved by staff. */
    private function confirmedVisit(array $startTimes = ['09:00'], string $date = self::FRIDAY): VisitRequest
    {
        Sanctum::actingAs($this->visitorAccount);
        $id = $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => $date,
            'startTimes' => $startTimes,
        ])->assertCreated()->json('id');
        $this->completeRequirements();

        return app(VisitAssignmentService::class)->approveVisitorRequest(VisitRequest::findOrFail($id));
    }

    /** A staff-assigned visit awaiting the visitor's confirmation. */
    private function pendingConfirmationVisit(string $date): VisitRequest
    {
        $this->completeRequirements();

        return app(VisitAssignmentService::class)->assignSessions(
            $this->visitor->fresh(), $this->relationship->fresh(), CarbonImmutable::parse($date), ['09:00']
        );
    }

    private function at(string $time, string $date = self::FRIDAY): void
    {
        $this->travelTo(CarbonImmutable::parse("{$date} {$time}:00", 'Asia/Manila'));
    }

    private function qrToken(VisitRequest $visit): string
    {
        Sanctum::actingAs($this->visitorAccount);

        return $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertOk()->json('qrToken');
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

    private function manualCheckIn(VisitRequest $visit): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->frontDeskAccount, 'web');

        return $this->from('/front-desk/checkin-checkout')
            ->post("/front-desk/checkin-checkout/{$visit->visit_request_id}/check-in", [
                'verification_method' => 'manual_override',
                'id_surrendered_type' => 'national_id',
                'override_reason' => 'Visitor phone battery is dead',
            ]);
    }

    private function checkOut(VisitCheckin $checkin): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->frontDeskAccount, 'web');

        return $this->post("/front-desk/checkin-checkout/{$checkin->checkin_id}/check-out");
    }

    private function activeCheckin(VisitRequest $visit): VisitCheckin
    {
        return VisitCheckin::where('visit_request_id', $visit->visit_request_id)->where('status', 'checked_in')->sole();
    }

    /**
     * A confirmed Friday-morning visit of the same visitor with a second PDL.
     * Booking allows one PDL per visitor per day, so it is booked for the
     * Friday after and moved onto Friday in the database — the state left by
     * data from before that rule, which the gate must still handle.
     */
    private function otherPdlVisitOnFriday(): VisitRequest
    {
        $pdl = Pdl::create([
            'pdl_number' => 'PDL-TEST-002',
            'full_name' => 'Second PDL',
            'date_of_birth' => '1991-01-01',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);
        $relationship = $this->completeRequirements(VisitorPdlRelationship::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $pdl->pdl_id,
            'relationship_type' => 'immediate_family',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]));

        Sanctum::actingAs($this->visitorAccount);
        $id = $this->postJson('/api/visit-requests', [
            'relationshipId' => $relationship->relationship_id,
            'date' => self::NEXT_FRIDAY,
            'startTimes' => ['09:00'],
        ])->assertCreated()->json('id');
        $visit = app(VisitAssignmentService::class)->approveVisitorRequest(VisitRequest::findOrFail($id));

        $friday = VisitSchedule::whereDate('schedule_date', self::FRIDAY)->where('time_slot_start', '09:00:00')->sole();
        VisitRequest::whereKey($visit->visit_request_id)->update(['schedule_id' => $friday->schedule_id]);
        VisitSession::where('visit_request_id', $visit->visit_request_id)->update(['schedule_id' => $friday->schedule_id]);

        return $visit->fresh();
    }

    private function closeUnrecorded(VisitCheckin $checkin): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->frontDeskAccount, 'web');

        return $this->from('/front-desk/schedule/inside')->post(
            "/front-desk/checkin-checkout/{$checkin->checkin_id}/close-unrecorded",
            [
                'reason' => 'Visitor left at closing time; check-out was not recorded.',
                'id_at_desk' => 'no',
                'current_password' => 'password',
            ]
        );
    }

    /** Restriction row written without model events (no automatic cancellation). */
    private function quietRestriction(string $start, ?string $end, string $status = 'active'): PdlRestriction
    {
        return PdlRestriction::withoutEvents(fn () => PdlRestriction::create([
            'pdl_id' => $this->pdl->pdl_id,
            'restriction_type' => 'quarantine',
            'status' => $status,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Medical quarantine',
            'imposed_by' => StaffProfile::first()->staff_id,
        ]));
    }

    private function restrict(string $start, ?string $end, string $type = 'victim_related'): PdlRestriction
    {
        return PdlRestriction::create([
            'pdl_id' => $this->pdl->pdl_id,
            'restriction_type' => $type,
            'status' => 'active',
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Victim protection order',
            'imposed_by' => StaffProfile::first()->staff_id,
        ]);
    }

    // ---------------------------------------- PDL eligibility at the gate

    public function test_a_pdl_no_longer_in_custody_blocks_scan_qr_check_in_and_manual_check_in(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('09:30');
        $token = $this->qrToken($visit);

        foreach (['released', 'transferred', 'deceased'] as $status) {
            // Written without model events: the gate must refuse on its own,
            // even before (or without) the automatic cancellation.
            Pdl::whereKey($this->pdl->pdl_id)->update(['custody_status' => $status]);

            $this->scan($token)->assertStatus(422)->assertJsonPath('message', VisitGateService::PDL_UNAVAILABLE_MESSAGE);
            $this->checkIn($token)->assertStatus(422)->assertJsonPath('message', VisitGateService::PDL_UNAVAILABLE_MESSAGE);
            $this->manualCheckIn($visit)->assertSessionHas('error', VisitGateService::PDL_UNAVAILABLE_MESSAGE);
        }

        $this->assertSame(0, VisitCheckin::count());
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_a_restriction_covering_today_blocks_entry(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('09:30');
        $token = $this->qrToken($visit);
        $this->quietRestriction(self::FRIDAY, self::FRIDAY);

        $this->scan($token)->assertStatus(422)->assertJsonPath('message', VisitGateService::PDL_UNAVAILABLE_MESSAGE);
        $this->checkIn($token)->assertStatus(422);
        $this->manualCheckIn($visit)->assertSessionHas('error', VisitGateService::PDL_UNAVAILABLE_MESSAGE);
        $this->assertSame(0, VisitCheckin::count());
    }

    public function test_restrictions_outside_their_dates_do_not_block_entry(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('09:30');
        $token = $this->qrToken($visit);

        // Starts next week; ended yesterday but never lifted; lifted.
        $this->quietRestriction('2026-10-12', null);
        $this->quietRestriction('2026-10-01', '2026-10-08');
        $this->quietRestriction('2026-10-01', null, 'lifted');

        $this->assertTrue($this->pdl->fresh()->activeRestrictions()->doesntExist());
        $this->scan($token)->assertOk();
        $this->checkIn($token)->assertOk();
    }

    public function test_check_out_is_never_blocked_by_the_pdl(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('09:30');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();

        $this->restrict(self::FRIDAY, null);
        Pdl::whereKey($this->pdl->pdl_id)->update(['custody_status' => 'transferred']);
        $this->assertSame('confirmed', $visit->fresh()->status, 'A visit already entered is not cancelled.');

        $checkin = $this->activeCheckin($visit);
        $this->scan($token, ['mode' => 'checkout'])->assertOk()->assertJsonPath('checkin_id', $checkin->checkin_id);
        $this->checkOut($checkin)->assertRedirect()->assertSessionHas('status', 'Visitor checked out successfully.');
        $this->assertSame('checked_out', $checkin->fresh()->status);
        $this->assertSame('completed', $visit->fresh()->status);
    }

    public function test_a_restriction_added_at_midday_blocks_the_afternoon_re_entry(): void
    {
        $visit = $this->confirmedVisit(['09:00', '13:00']);
        $this->at('09:30');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();

        $this->at('11:30');
        $this->checkOut($this->activeCheckin($visit))->assertRedirect();
        $this->restrict(self::FRIDAY, self::FRIDAY);

        $this->at('13:10');
        $this->scan($token)->assertStatus(422)->assertJsonPath('message', VisitGateService::PDL_UNAVAILABLE_MESSAGE);
        $this->checkIn($token)->assertStatus(422);
        $this->assertSame(1, VisitCheckin::count());
    }

    // ------------------------------------- Booking against the visit date

    public function test_booking_checks_restrictions_against_the_visit_date(): void
    {
        // Restricted from Monday 12th onward: the 9th is fine, the 16th is not.
        $this->quietRestriction('2026-10-12', null);

        $this->assertSame('confirmed', $this->confirmedVisit(['09:00'], self::FRIDAY)->status);

        Sanctum::actingAs($this->visitorAccount);
        $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::NEXT_FRIDAY,
            'startTimes' => ['09:00'],
        ])->assertStatus(422);

        $this->expectException(ValidationException::class);
        $this->pendingConfirmationVisit(self::NEXT_FRIDAY);
    }

    public function test_a_restriction_ending_before_the_visit_date_does_not_block_booking(): void
    {
        $this->quietRestriction('2026-10-01', '2026-10-12');

        $this->assertSame('confirmed', $this->confirmedVisit(['09:00'], self::NEXT_FRIDAY)->status);
    }

    // --------------------------------------------- Automatic cancellation

    public function test_leaving_custody_cancels_upcoming_visits_with_a_generic_notice(): void
    {
        $confirmed = $this->confirmedVisit(['09:00', '13:00'], self::FRIDAY);
        $pending = $this->pendingConfirmationVisit(self::NEXT_FRIDAY);
        $morning = VisitSchedule::whereDate('schedule_date', self::FRIDAY)->where('time_slot_start', '09:00:00')->sole();
        $this->assertSame(1, (int) $morning->slots_taken);

        $pdl = $this->pdl->fresh();
        $pdl->update(['custody_status' => 'released']);

        foreach ([$confirmed, $pending] as $visit) {
            $visit->refresh();
            $this->assertSame('cancelled', $visit->status);
            $this->assertSame(VisitAssignmentService::PDL_UNAVAILABLE_REASON, $visit->cancellation_reason);
            $this->assertNotNull($visit->cancelled_at);
        }
        $this->assertSame(0, (int) $morning->fresh()->slots_taken, 'The seat is released.');

        $notices = Notification::where('account_id', $this->visitorAccount->account_id)
            ->where('title', 'Visit Cancelled')->get();
        $this->assertCount(2, $notices);
        foreach ($notices as $notice) {
            $this->assertStringContainsString('not available for visitation', $notice->message);
            $this->assertStringNotContainsStringIgnoringCase('released', $notice->message);
        }

        // The specific reason is staff-only, in the audit log.
        $this->assertDatabaseHas('audit_logs', [
            'record_id' => $confirmed->visit_request_id,
        ]);
        $this->assertTrue(\App\Models\AuditLog::where('record_id', $confirmed->visit_request_id)
            ->where('description', 'like', '%custody status changed to released%')->exists());

        // The visitor's app no longer issues a pass.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson("/api/schedules/{$confirmed->visit_request_id}/qr")->assertStatus(409);
    }

    public function test_a_restriction_cancels_only_the_visits_it_covers_and_never_restores_them(): void
    {
        $covered = $this->confirmedVisit(['09:00'], self::FRIDAY);
        $notCovered = $this->confirmedVisit(['09:00'], self::NEXT_FRIDAY);

        $restriction = $this->restrict('2026-10-08', '2026-10-10');

        $this->assertSame('cancelled', $covered->fresh()->status);
        $this->assertSame('confirmed', $notCovered->fresh()->status);

        $notice = Notification::where('related_record_id', $covered->visit_request_id)->where('title', 'Visit Cancelled')->sole();
        $this->assertStringNotContainsStringIgnoringCase('victim', $notice->message);
        $this->assertTrue(\App\Models\AuditLog::where('record_id', $covered->visit_request_id)
            ->where('description', 'like', '%victim related restriction%')->exists());

        // Lifting it does not bring the cancelled visit back.
        $restriction->update(['status' => 'lifted']);
        $this->assertSame('cancelled', $covered->fresh()->status);
    }

    public function test_assigned_entered_and_past_visits_are_not_cancelled(): void
    {
        // Awaiting review: left to the review process.
        Sanctum::actingAs($this->visitorAccount);
        $assignedId = $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::NEXT_FRIDAY,
            'startTimes' => ['09:00'],
        ])->assertCreated()->json('id');

        // Entered at the gate (morning of a whole-day visit, now on break).
        $entered = $this->confirmedVisit(['09:00', '13:00'], self::FRIDAY);
        $this->at('09:30');
        $this->checkIn($this->qrToken($entered))->assertOk();
        $this->at('11:30');
        $this->checkOut($this->activeCheckin($entered))->assertRedirect();

        $this->at('12:00');
        $this->restrict(self::FRIDAY, null);

        $this->assertSame('assigned', VisitRequest::findOrFail($assignedId)->status);
        $this->assertSame('confirmed', $entered->fresh()->status);
    }

    // ----------------------------------------------------- Whole-day exit

    public function test_a_whole_day_visitor_leaving_after_the_afternoon_starts_is_checked_out_for_good(): void
    {
        $visit = $this->confirmedVisit(['09:00', '13:00']);
        $this->at('09:30');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();

        // Stayed through the midday break; leaves at 14:00.
        $this->at('14:00');
        $checkin = $this->activeCheckin($visit);
        $this->checkOut($checkin)->assertRedirect()->assertSessionHas('status', 'Visitor checked out successfully.');

        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertSame('used', QrCode::where('qr_token', $token)->value('status'));

        $this->at('14:10');
        $this->scan($token)->assertStatus(422);
        $this->checkIn($token)->assertStatus(422);
        $this->manualCheckIn($visit)->assertSessionHas('error');
        $this->assertSame(1, VisitCheckin::where('visit_request_id', $visit->visit_request_id)->count());
    }

    public function test_leaving_exactly_when_the_afternoon_starts_is_final_and_before_it_is_a_break(): void
    {
        $gate = app(VisitGateService::class);

        $visit = $this->confirmedVisit(['09:00', '13:00']);
        $this->at('09:30');
        $this->checkIn($this->qrToken($visit))->assertOk();
        $checkin = $this->activeCheckin($visit);

        $this->assertTrue($gate->hasReturnSession($visit, $checkin, CarbonImmutable::parse(self::FRIDAY.' 12:59:00')));
        $this->assertFalse($gate->hasReturnSession($visit, $checkin, CarbonImmutable::parse(self::FRIDAY.' 13:00:00')));
    }

    // ---------------------------------------------------------- Overstay

    public function test_a_visitor_inside_after_the_last_session_ends_is_overstayed(): void
    {
        $gate = app(VisitGateService::class);
        $visit = $this->confirmedVisit(['09:00', '13:00']);
        $this->at('09:30');
        $this->checkIn($this->qrToken($visit))->assertOk();

        // Inside through midday is not an overstay for a whole-day visit.
        $this->assertFalse($gate->isOverstayed($visit->fresh(), CarbonImmutable::parse(self::FRIDAY.' 12:00:00')));
        $this->assertFalse($gate->isOverstayed($visit->fresh(), CarbonImmutable::parse(self::FRIDAY.' 16:30:00')));
        $this->assertTrue($gate->isOverstayed($visit->fresh(), CarbonImmutable::parse(self::FRIDAY.' 16:31:00')));

        $this->at('16:45');
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->get('/front-desk/checkin-checkout')->assertOk()->assertSee('Overstayed');
        $this->get('/front-desk/schedule/inside')->assertOk()->assertSee('Overstayed');

        // Checked out: no longer overstayed.
        $this->checkOut($this->activeCheckin($visit))->assertRedirect();
        $this->assertFalse($gate->isOverstayed($visit->fresh()));
    }

    public function test_a_morning_visitor_is_overstayed_after_11_30_and_not_before(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('09:30');
        $this->checkIn($this->qrToken($visit))->assertOk();

        $this->at('11:00');
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->get('/front-desk/schedule/inside')->assertOk()->assertDontSee('Overstayed');

        $this->at('11:45');
        $this->get('/front-desk/schedule/inside')->assertOk()->assertSee('Overstayed');
    }

    // --------------------------------------------------- Wrong scan mode

    public function test_a_check_in_scan_of_a_visitor_inside_points_to_check_out_after_the_pass_expired(): void
    {
        $visit = $this->confirmedVisit(['09:00', '13:00']);
        $this->at('09:30');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();
        $this->assertSame('active', QrCode::where('qr_token', $token)->value('status'));

        // Still inside after the pass expired (16:30).
        $this->at('16:40');
        $this->scan($token)
            ->assertStatus(422)
            ->assertJsonPath('message', self::ALREADY_INSIDE)
            ->assertJsonPath('already_inside', true);
        $this->checkIn($token)->assertStatus(422)->assertJsonPath('message', self::ALREADY_INSIDE);
        $this->assertSame('active', QrCode::where('qr_token', $token)->value('status'), 'The pass is not marked expired.');

        // Check-out still works with the expired pass.
        $checkin = $this->activeCheckin($visit);
        $this->scan($token, ['mode' => 'checkout'])->assertOk()->assertJsonPath('checkin_id', $checkin->checkin_id);
        $this->checkOut($checkin)->assertRedirect();
        $this->assertSame('completed', $visit->fresh()->status);
    }

    public function test_a_check_in_scan_of_a_visitor_inside_with_a_used_pass_points_to_check_out(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('09:30');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();
        $this->assertSame('used', QrCode::where('qr_token', $token)->value('status'));

        $this->scan($token)->assertStatus(422)->assertJsonPath('message', self::ALREADY_INSIDE);
        $this->manualCheckIn($visit)->assertSessionHas('error', self::ALREADY_INSIDE);
    }

    // ------------------------------------ One visit inside at a time

    public function test_a_visitor_inside_for_another_visit_today_is_not_checked_in_until_checked_out_of_it(): void
    {
        $visit = $this->confirmedVisit();
        $other = $this->otherPdlVisitOnFriday();
        $this->at('09:30');
        $token = $this->qrToken($visit);

        $this->manualCheckIn($other)->assertSessionMissing('error');
        $inside = $this->activeCheckin($other);

        $message = "This visitor is already inside for another visit (visit #{$other->visit_request_id}, PDL Second PDL). Check them out of that visit first.";
        $this->scan($token)->assertStatus(422)
            ->assertJsonPath('message', $message)
            ->assertJsonPath('inside_elsewhere', true);
        $this->checkIn($token)->assertStatus(422)
            ->assertJsonPath('message', $message)
            ->assertJsonPath('inside_elsewhere', true);
        $this->manualCheckIn($visit)->assertSessionHas('error', $message);
        $this->assertSame(0, VisitCheckin::where('visit_request_id', $visit->visit_request_id)->count());
        $this->assertSame('active', QrCode::where('qr_token', $token)->value('status'), 'A refused entry does not use the pass.');

        // Checked out of the other visit: now they can come in on this one.
        $this->at('10:00');
        $this->checkOut($inside)->assertRedirect()->assertSessionHas('status', 'Visitor checked out successfully.');
        $this->scan($token)->assertOk();
        $this->checkIn($token)->assertOk();
        $this->assertSame('checked_in', $this->activeCheckin($visit)->status);
    }

    public function test_a_visitor_still_marked_inside_from_a_previous_day_is_not_checked_in_until_that_record_is_closed(): void
    {
        $earlier = $this->confirmedVisit(['09:00'], self::FRIDAY);
        $today = $this->confirmedVisit(['09:00'], self::NEXT_FRIDAY);

        // Checked in on the 9th; the exit was never recorded.
        $this->at('09:30');
        $this->checkIn($this->qrToken($earlier))->assertOk();
        $left = $this->activeCheckin($earlier);

        $this->at('09:30', self::NEXT_FRIDAY);
        $token = $this->qrToken($today);

        $this->scan($token)->assertStatus(422)->assertJsonPath('inside_elsewhere', true);
        $this->assertStringContainsString('previous visit on Oct 9, 2026', $this->scan($token)->json('message'));
        $this->checkIn($token)->assertStatus(422)->assertJsonPath('inside_elsewhere', true);
        $this->manualCheckIn($today)->assertSessionHas('error');
        $this->assertStringContainsString('Oct 9, 2026', session('error'));
        $this->assertSame(0, VisitCheckin::where('visit_request_id', $today->visit_request_id)->count());

        $this->closeUnrecorded($left)->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame('exit_unrecorded', $left->fresh()->status);

        $this->manualCheckIn($today)->assertSessionMissing('error');
        $this->assertSame('checked_in', $this->activeCheckin($today)->status);
    }

    // ------------------------------------------------- Entry opening times

    public function test_qr_check_in_opens_30_minutes_before_the_morning_session(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('08:00');
        $token = $this->qrToken($visit);

        $message = 'This visit is for the morning session (09:00 - 11:30). Entry opens at 08:30.';
        $this->at('08:29');
        $this->scan($token)->assertStatus(422)->assertJsonPath('message', $message);
        $this->checkIn($token)->assertStatus(422)->assertJsonPath('message', $message);
        $this->assertSame(0, VisitCheckin::count());

        $this->at('08:30');
        $this->scan($token)->assertOk()->assertJsonPath('schedule.session', 'Morning session');
        $this->checkIn($token)->assertOk();
        $this->assertSame(1, VisitCheckin::count());
    }

    public function test_manual_check_in_opens_30_minutes_before_the_morning_session(): void
    {
        $visit = $this->confirmedVisit(['09:00']);

        $this->at('08:29');
        $this->manualCheckIn($visit)
            ->assertSessionHas('error', 'This visit is for the morning session (09:00 - 11:30). Entry opens at 08:30.');
        $this->assertSame(0, VisitCheckin::count());

        $this->at('08:30');
        $this->manualCheckIn($visit)->assertSessionMissing('error');
        $this->assertSame(1, VisitCheckin::count());
    }

    public function test_the_afternoon_re_entry_opens_at_13_00_not_30_minutes_early(): void
    {
        $visit = $this->confirmedVisit(['09:00', '13:00']);
        $this->at('08:45');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();

        // Midday exit: the visit stays confirmed and the pass stays active.
        $this->at('11:30');
        $this->checkOut($this->activeCheckin($visit))->assertRedirect()
            ->assertSessionHas('status', 'Visitor exited for the midday break. The same QR pass admits them for the afternoon session.');
        $this->assertSame('confirmed', $visit->fresh()->status);
        $this->assertSame('active', QrCode::where('qr_token', $token)->value('status'));

        // The early window is for the first session only: the midday break
        // stays closed, at 12:30 and up to the last minute before 13:00.
        $message = 'This visit is for the afternoon session (13:00 - 16:30). Entry opens at 13:00.';
        foreach (['12:30', '12:59'] as $time) {
            $this->at($time);
            $this->scan($token)->assertStatus(422)->assertJsonPath('message', $message);
            $this->checkIn($token)->assertStatus(422)->assertJsonPath('message', $message);
            $this->manualCheckIn($visit)->assertSessionHas('error', $message);
        }

        $this->at('13:00');
        $this->scan($token)->assertOk()->assertJsonPath('schedule.session', 'Afternoon session');
        $this->checkIn($token)->assertOk();
        $this->assertSame('used', QrCode::where('qr_token', $token)->value('status'));
        $this->assertSame(2, VisitCheckin::where('visit_request_id', $visit->visit_request_id)->count());
    }

    public function test_an_afternoon_only_visitor_is_not_admitted_during_the_midday_break(): void
    {
        $visit = $this->confirmedVisit(['13:00']);
        $this->at('12:30');
        $token = $this->qrToken($visit);

        $this->scan($token)->assertStatus(422)
            ->assertJsonPath('message', 'This visit is for the afternoon session (13:00 - 16:30). Entry opens at 13:00.');

        $this->at('13:00');
        $this->checkIn($token)->assertOk();
    }

    // ------------------------------------------- Check-out is never blocked

    public function test_check_out_is_allowed_at_any_time_including_overstay(): void
    {
        // Leaving within minutes of an early entry, before the session starts.
        $early = $this->confirmedVisit(['09:00']);
        $this->at('08:30');
        $this->checkIn($this->qrToken($early))->assertOk();
        $this->at('08:40');
        $this->checkOut($this->activeCheckin($early))->assertRedirect()->assertSessionHas('status', 'Visitor checked out successfully.');
        $this->assertSame('completed', $early->fresh()->status);

        // Still inside after the last session ended (overstay).
        $late = $this->confirmedVisit(['09:00'], self::NEXT_FRIDAY);
        $this->at('09:30', self::NEXT_FRIDAY);
        $token = $this->qrToken($late);
        $this->checkIn($token)->assertOk();

        $this->at('17:45', self::NEXT_FRIDAY);
        $this->assertTrue(app(VisitGateService::class)->isOverstayed($late->fresh()));
        $checkin = $this->activeCheckin($late);
        $this->scan($token, ['mode' => 'checkout'])->assertOk()->assertJsonPath('checkin_id', $checkin->checkin_id);
        $this->checkOut($checkin)->assertRedirect()->assertSessionHas('status', 'Visitor checked out successfully.');
        $this->assertSame('checked_out', $checkin->fresh()->status);
    }

    // ------------------------------------------------ Check-in page load

    public function test_the_check_in_page_query_count_does_not_grow_with_the_number_of_visitors(): void
    {
        $this->at('09:30');
        $morning = VisitSchedule::whereDate('schedule_date', self::FRIDAY)->where('time_slot_start', '09:00:00')->sole();

        // Written directly (no booking rules): only the page's reads matter.
        $seed = function (int $count) use ($morning) {
            for ($i = 0; $i < $count; $i++) {
                foreach ([false, true] as $inside) {
                    $visit = VisitRequest::create([
                        'visitor_id' => $this->visitor->visitor_id,
                        'pdl_id' => $this->pdl->pdl_id,
                        'relationship_id' => $this->relationship->relationship_id,
                        'schedule_id' => $morning->schedule_id,
                        'status' => 'confirmed',
                        'confirmed_at' => now(),
                    ]);
                    if ($inside) {
                        VisitCheckin::create([
                            'visit_request_id' => $visit->visit_request_id,
                            'schedule_id' => $morning->schedule_id,
                            'qr_code_id' => QrCode::generateFor($visit)->qr_code_id,
                            'id_surrendered_type' => 'national_id',
                            'id_surrender_time' => now(),
                            'check_in_time' => now(),
                            'check_in_officer_id' => StaffProfile::first()->staff_id,
                            'verification_method' => 'qr_scan',
                            'status' => 'checked_in',
                        ]);
                    }
                }
            }
        };
        $queries = function (): int {
            $this->actingAs($this->frontDeskAccount, 'web');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/front-desk/checkin-checkout')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $queries(); // first request: one-time session/cache reads, not counted

        $seed(2);
        $few = $queries();
        $seed(6);
        $many = $queries();

        $this->assertSame($few, $many, "2 expected + 2 inside: {$few} queries; 8 + 8: {$many}.");
    }

    public function test_a_previous_day_record_is_still_refused_at_check_out(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('09:30');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();
        $checkin = $this->activeCheckin($visit);

        $this->at('09:00', '2026-10-10');
        $this->scan($token, ['mode' => 'checkout'])->assertStatus(422);
        $this->checkOut($checkin)->assertRedirect()->assertSessionHas('error');
        $this->assertStringContainsString('Close record – exit not recorded', session('error'));
        $this->assertSame('checked_in', $checkin->fresh()->status);
    }
}
