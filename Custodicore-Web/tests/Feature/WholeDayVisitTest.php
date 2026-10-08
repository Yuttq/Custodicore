<?php

namespace Tests\Feature;

use App\Http\Controllers\FrontDesk\CheckinCheckoutController;
use App\Models\FacilityVisitationRule;
use App\Models\QrCode;
use App\Models\StaffProfile;
use App\Models\SystemSetting;
use App\Models\VisitCheckin;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Models\VisitSession;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Field finding: a visitor may attend both sessions of a visiting day —
 * morning 09:00–11:30, leave at the midday break, return for the afternoon
 * 13:00–16:30 — and that whole day is ONE visit:
 *   - one visit_requests row (one review, one QR, one visit.max_per_week count)
 *   - one visit_sessions row and one seat per session
 *   - one gate record per session: check-in, midday exit, re-entry, final check-out
 *
 * "Now" starts at Wednesday 2026-10-07 10:00 Asia/Manila. The fixture PDL is
 * non_drug_related; Fri 2026-10-09 09:00 has a seeded schedule row
 * (capacity 2); Fri 13:00 and the Sunday sessions have rules only.
 */
class WholeDayVisitTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';
    private const SUNDAY = '2026-10-11';

    private const LIMIT_MESSAGE = 'You have reached the maximum number of visits allowed for this calendar week.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        foreach ([['fri', '13:00:00', '16:30:00'], ['sun', '09:00:00', '11:30:00'], ['sun', '13:00:00', '16:30:00']] as [$day, $start, $end]) {
            FacilityVisitationRule::create([
                'pdl_classification' => 'non_drug_related',
                'day_of_week' => $day,
                'time_slot_start' => $start,
                'time_slot_end' => $end,
                'max_capacity' => 30,
                'effective_from' => '2024-01-01',
            ]);
        }

        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'x.png',
            'verification_status' => 'verified',
        ]);
    }

    // ------------------------------------------------------------ Helpers

    private function setLimit(string $value): void
    {
        SystemSetting::create([
            'setting_key' => 'visit.max_per_week',
            'setting_value' => $value,
            'updated_by' => StaffProfile::first()->staff_id,
        ]);
    }

    /** POST /api/visit-requests as the fixture visitor. */
    private function request(array $startTimes, string $date = self::FRIDAY): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->visitorAccount);

        return $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => $date,
            'startTimes' => $startTimes,
        ]);
    }

    /** A visitor-submitted visit for $startTimes on $date, approved by staff. */
    private function confirmedVisit(array $startTimes = ['09:00', '13:00'], string $date = self::FRIDAY): VisitRequest
    {
        $id = $this->request($startTimes, $date)->assertCreated()->json('id');

        return app(VisitAssignmentService::class)->approveVisitorRequest(VisitRequest::findOrFail($id));
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

    private function checkOut(VisitCheckin $checkin): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->frontDeskAccount, 'web');

        return $this->post("/front-desk/checkin-checkout/{$checkin->checkin_id}/check-out");
    }

    private function activeCheckin(VisitRequest $visit): VisitCheckin
    {
        return VisitCheckin::where('visit_request_id', $visit->visit_request_id)->where('status', 'checked_in')->sole();
    }

    private function afternoonSchedule(): VisitSchedule
    {
        return VisitSchedule::whereDate('schedule_date', self::FRIDAY)->where('time_slot_start', '13:00:00')->sole();
    }

    private function weeklyCount(string $date = self::FRIDAY): int
    {
        $service = app(ScheduleAvailabilityService::class);
        $day = CarbonImmutable::parse($date);

        return $service->weeklyVisitCounts($this->visitor, $day, $day)[$service->weekStart($day)] ?? 0;
    }

    private function availabilityDay(string $date): array
    {
        Sanctum::actingAs($this->visitorAccount);

        return $this->getJson("/api/schedules/availability?from={$date}&to={$date}")->assertOk()->json('relationships.0.days.0');
    }

    // ------------------------------------------- Booking and weekly limit

    public function test_morning_and_afternoon_on_the_same_day_are_one_visit_with_two_sessions(): void
    {
        $this->request(['09:00', '13:00'])
            ->assertCreated()
            ->assertJsonPath('status', 'assigned')
            ->assertJsonPath('isWholeDay', true)
            ->assertJsonPath('scheduledAt', '2026-10-09T09:00:00+08:00')
            ->assertJsonPath('endAt', '2026-10-09T16:30:00+08:00')
            ->assertJsonPath('timeLabel', '9:00 AM - 4:30 PM')
            ->assertJsonPath('sessions.0.period', 'morning')
            ->assertJsonPath('sessions.1.period', 'afternoon')
            ->assertJsonCount(2, 'sessions');

        $visit = VisitRequest::sole();
        $this->assertSame((int) $this->schedule->schedule_id, (int) $visit->schedule_id, 'schedule_id is the first session.');
        $this->assertSame(2, VisitSession::where('visit_request_id', $visit->visit_request_id)->count());
        $this->assertSame(1, $this->weeklyCount(), 'A whole-day visit counts once.');
    }

    public function test_capacity_is_reserved_on_both_sessions(): void
    {
        $this->request(['09:00', '13:00'])->assertCreated();

        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame(1, (int) $this->afternoonSchedule()->slots_taken);

        $service = app(VisitAssignmentService::class);
        $this->assertSame(1, $service->occupiedSlots($this->schedule->fresh()));
        $this->assertSame(1, $service->occupiedSlots($this->afternoonSchedule()));
    }

    public function test_a_whole_day_visit_does_not_use_two_weekly_visits(): void
    {
        // Default limit of 2: Friday whole day + Sunday is two visits, allowed.
        $this->request(['09:00', '13:00'])->assertCreated();
        $this->request(['09:00'], self::SUNDAY)->assertCreated();

        $this->assertSame(2, VisitRequest::count());
        $this->assertSame(2, $this->weeklyCount());
    }

    public function test_visitor_at_the_weekly_limit_cannot_book_another_day(): void
    {
        $this->setLimit('1');
        $this->request(['09:00', '13:00'])->assertCreated();

        $sunday = $this->availabilityDay(self::SUNDAY);
        $this->assertSame('weekly_limit', $sunday['slots'][0]['reason']);
        $this->assertSame('weekly_limit', $sunday['wholeDay']['reason']);
        $this->assertFalse($sunday['wholeDay']['available']);

        $this->request(['09:00', '13:00'], self::SUNDAY)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule_id' => self::LIMIT_MESSAGE]);
        $this->assertSame(1, VisitRequest::count());
    }

    public function test_a_second_visit_with_the_same_pdl_on_the_same_day_is_refused(): void
    {
        $this->request(['09:00'])->assertCreated();

        $this->assertSame('already_scheduled', $this->availabilityDay(self::FRIDAY)['slots'][1]['reason']);

        $this->request(['13:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['startTime' => 'You already have a visit with this PDL on this date.']);
        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(1, $this->weeklyCount());
    }

    public function test_availability_offers_the_whole_day_as_one_visit(): void
    {
        $day = $this->availabilityDay(self::FRIDAY);

        $this->assertSame([
            'available' => true,
            'reason' => null,
            'startTime' => '09:00',
            'endTime' => '16:30',
            'startTimes' => ['09:00', '13:00'],
        ], $day['wholeDay']);
        $this->assertCount(2, $day['slots']);
    }

    public function test_a_full_session_blocks_the_whole_day_without_side_effects(): void
    {
        $this->schedule->update(['slots_taken' => 2, 'status' => 'full']);

        $day = $this->availabilityDay(self::FRIDAY);
        $this->assertFalse($day['wholeDay']['available']);
        $this->assertSame('full', $day['wholeDay']['reason']);

        $this->request(['09:00', '13:00'])->assertUnprocessable();
        $this->assertSame(0, VisitRequest::count());
        $this->assertSame(0, VisitSchedule::whereDate('schedule_date', self::FRIDAY)->where('time_slot_start', '13:00:00')->count());
    }

    public function test_rejected_whole_day_visit_releases_both_seats_and_stops_counting(): void
    {
        $id = $this->request(['09:00', '13:00'])->assertCreated()->json('id');

        app(VisitAssignmentService::class)->rejectVisitorRequest(VisitRequest::findOrFail($id), 'No slots for this family.');

        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame(0, (int) $this->afternoonSchedule()->slots_taken);
        $this->assertSame(0, $this->weeklyCount(), 'Cancelled visits do not count.');

        // The day can be requested again.
        $this->request(['09:00', '13:00'])->assertCreated();
    }

    public function test_record_officer_review_shows_both_sessions_as_one_visit(): void
    {
        $this->request(['09:00', '13:00'])->assertCreated();

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->get("/visitors/{$this->visitor->visitor_id}")
            ->assertOk()
            ->assertSee('Whole day (one visit)')
            ->assertSee('09:00–11:30')
            ->assertSee('13:00–16:30');
    }

    public function test_single_session_request_still_accepts_start_time(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::FRIDAY,
            'startTime' => '13:00',
        ])
            ->assertCreated()
            ->assertJsonPath('isWholeDay', false)
            ->assertJsonPath('timeLabel', '1:00 PM - 4:30 PM');

        $this->assertSame(1, VisitSession::count());
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame(1, (int) $this->afternoonSchedule()->slots_taken);
    }

    // ---------------------------------------------------- Gate lifecycle

    public function test_whole_day_gate_lifecycle_uses_one_visit_and_one_qr(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('09:30');
        $token = $this->qrToken($visit);

        // Morning check-in.
        $this->scan($token)
            ->assertOk()
            ->assertJsonPath('schedule.session', 'Morning session')
            ->assertJsonPath('schedule.is_reentry', false)
            ->assertJsonPath('schedule.whole_day', true);
        $this->checkIn($token)->assertOk()->assertJsonPath('checkin.session', 'Morning session');

        $morning = $this->activeCheckin($visit);
        $this->assertSame((int) $this->schedule->schedule_id, (int) $morning->schedule_id);
        $this->assertSame('active', QrCode::where('qr_token', $token)->value('status'), 'The QR stays valid for the afternoon.');

        // While inside: no second entry, no QR re-issue.
        $this->scan($token)->assertStatus(422)->assertJsonPath('message', 'This visitor is already checked in.');
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertStatus(409);

        // Midday exit: the visit continues.
        $this->at('11:30');
        $this->scan($token, ['mode' => 'checkout'])->assertOk()->assertJsonPath('checkin_id', $morning->checkin_id);
        $this->checkOut($morning)
            ->assertRedirect()
            ->assertSessionHas('status', 'Visitor exited for the midday break. The same QR pass admits them for the afternoon session.');

        $this->assertSame('checked_out', $morning->fresh()->status);
        $this->assertNotNull($morning->fresh()->id_returned_time);
        $this->assertSame('confirmed', $visit->fresh()->status);
        $this->assertTrue(CheckinCheckoutController::expectedToday()->contains('visit_request_id', $visit->visit_request_id));

        // Afternoon re-entry with the same visit (the visitor's app hands
        // out the current token; it is re-issued once the old one expires).
        // Not during the midday break: the afternoon session opens at 13:00.
        $this->at('12:59');
        $token = $this->qrToken($visit);
        $this->scan($token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This visit is for the afternoon session (01:00 PM - 04:30 PM). Entry opens at 01:00 PM.');
        $this->checkIn($token)->assertStatus(422);
        $this->assertSame(1, VisitCheckin::where('visit_request_id', $visit->visit_request_id)->count());

        $this->at('13:00');
        $this->scan($token)
            ->assertOk()
            ->assertJsonPath('schedule.session', 'Afternoon session')
            ->assertJsonPath('schedule.is_reentry', true)
            ->assertJsonPath('schedule.start', '01:00 PM');
        $this->checkIn($token)->assertOk()->assertJsonPath('checkin.session', 'Afternoon session');

        $afternoon = $this->activeCheckin($visit);
        $this->assertSame((int) $this->afternoonSchedule()->schedule_id, (int) $afternoon->schedule_id);
        $this->assertSame(2, VisitCheckin::where('visit_request_id', $visit->visit_request_id)->count());
        $this->assertSame('used', QrCode::where('qr_token', $token)->value('status'), 'No session left to enter.');
        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(1, $this->weeklyCount(), 'The re-entry is not a second weekly visit.');

        // Final check-out completes the visit.
        $this->at('16:00');
        $this->scan($token, ['mode' => 'checkout'])->assertOk()->assertJsonPath('checkin_id', $afternoon->checkin_id);
        $this->checkOut($afternoon)->assertRedirect()->assertSessionHas('status', 'Visitor checked out successfully.');

        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertSame('checked_out', $afternoon->fresh()->status);
        $this->assertFalse(CheckinCheckoutController::expectedToday()->contains('visit_request_id', $visit->visit_request_id));

        // The QR cannot be used again.
        $this->scan($token)->assertStatus(422);
        $this->scan($token, ['mode' => 'checkout'])->assertStatus(422);
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertStatus(409);

        // The timeline shows the first entry and the final exit.
        $steps = collect($this->getJson("/api/schedules/{$visit->visit_request_id}/timeline")->assertOk()->json('steps'))->keyBy('id');
        $this->assertSame($morning->fresh()->check_in_time->toIso8601String(), $steps['checked_in']['occurredAt']);
        $this->assertSame($afternoon->fresh()->check_out_time->toIso8601String(), $steps['checked_out']['occurredAt']);
    }

    public function test_midday_exit_does_not_show_the_visit_as_checked_out_in_the_timeline(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('09:30');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();
        $this->at('11:30');
        $this->checkOut($this->activeCheckin($visit))->assertRedirect();

        Sanctum::actingAs($this->visitorAccount);
        $steps = collect($this->getJson("/api/schedules/{$visit->visit_request_id}/timeline")->assertOk()->json('steps'))->keyBy('id');
        $this->assertNotNull($steps['checked_in']['occurredAt']);
        $this->assertNull($steps['checked_out']['occurredAt']);
        $this->assertNull($steps['visit_completed']['occurredAt']);
    }

    public function test_whole_day_visitor_cannot_reenter_before_the_afternoon_session_opens(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('09:30');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();

        // Leaves early, then tries to come back into the morning session.
        $this->at('10:00');
        $this->checkOut($this->activeCheckin($visit))->assertRedirect();
        $this->assertSame('confirmed', $visit->fresh()->status);

        $this->at('10:15');
        $this->scan($token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This visit is for the afternoon session (01:00 PM - 04:30 PM). Entry opens at 01:00 PM.');
        $this->checkIn($token)->assertStatus(422);
        $this->assertSame(1, VisitCheckin::count());

        // Nor during the midday break, up to the last minute before 13:00.
        $this->at('11:30');
        $this->scan($token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This visit is for the afternoon session (01:00 PM - 04:30 PM). Entry opens at 01:00 PM.');

        // The 09:30 token has passed qr.expiry_minutes (180); the app fetches a fresh one.
        $this->at('12:59');
        $token = $this->qrToken($visit);
        $this->scan($token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This visit is for the afternoon session (01:00 PM - 04:30 PM). Entry opens at 01:00 PM.');
        $this->checkIn($token)->assertStatus(422);
        $this->assertSame(1, VisitCheckin::count());

        $this->at('13:00');
        $this->scan($token)
            ->assertOk()
            ->assertJsonPath('schedule.session', 'Afternoon session')
            ->assertJsonPath('schedule.is_reentry', true);
        $this->checkIn($token)->assertOk()->assertJsonPath('checkin.session', 'Afternoon session');
        $this->assertSame((int) $this->afternoonSchedule()->schedule_id, (int) $this->activeCheckin($visit)->schedule_id);
        $this->assertSame(2, VisitCheckin::count());
    }

    public function test_afternoon_only_visit_cannot_enter_during_the_morning_session(): void
    {
        $visit = $this->confirmedVisit(['13:00']);
        $this->at('09:30');
        $token = $this->qrToken($visit);

        $this->scan($token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This visit is for the afternoon session (01:00 PM - 04:30 PM). Entry opens at 01:00 PM.');
        $this->checkIn($token)->assertStatus(422);
        $this->assertSame(0, VisitCheckin::count());

        // The 09:30 token has passed qr.expiry_minutes (180); the app fetches a fresh one.
        $this->at('12:59');
        $token = $this->qrToken($visit);
        $this->scan($token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This visit is for the afternoon session (01:00 PM - 04:30 PM). Entry opens at 01:00 PM.');
        $this->checkIn($token)->assertStatus(422);
        $this->assertSame(0, VisitCheckin::count());

        $this->at('13:00');
        $this->scan($token)->assertOk()->assertJsonPath('schedule.session', 'Afternoon session');
        $this->checkIn($token)->assertOk();
        $this->assertSame('used', QrCode::where('qr_token', $token)->value('status'));
    }

    public function test_morning_only_visit_cannot_enter_after_its_session_ended(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('11:00');
        $token = $this->qrToken($visit);

        $this->at('13:00');
        $this->scan($token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This visit\'s session has already ended for today.');
        $this->assertSame(0, VisitCheckin::count());
    }

    public function test_single_session_visit_still_completes_at_its_only_check_out(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('09:30');
        $token = $this->qrToken($visit);

        $this->scan($token)->assertOk()->assertJsonPath('schedule.whole_day', false);
        $this->checkIn($token)->assertOk();
        $this->assertSame('used', QrCode::where('qr_token', $token)->value('status'), 'Single session: QR used at entry, as before.');

        $this->at('10:30');
        $this->checkOut($this->activeCheckin($visit))->assertRedirect()->assertSessionHas('status', 'Visitor checked out successfully.');
        $this->assertSame('completed', $visit->fresh()->status);
    }

    public function test_qr_is_refused_on_the_wrong_day(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('09:30', '2026-10-08');
        $token = $this->qrToken($visit);

        $this->scan($token)->assertStatus(422)->assertJsonPath('message', 'This QR code is not for today\'s visit.');
        $this->checkIn($token)->assertStatus(422);
        $this->assertSame(0, VisitCheckin::count());
    }

    public function test_manual_check_in_enters_each_session_once(): void
    {
        $visit = $this->confirmedVisit();
        $this->actingAs($this->frontDeskAccount, 'web');

        $this->at('09:30');
        $this->post("/front-desk/checkin-checkout/{$visit->visit_request_id}/check-in", ['override_reason' => 'Phone battery dead'])->assertRedirect();
        $this->assertSame((int) $this->schedule->schedule_id, (int) $this->activeCheckin($visit)->schedule_id);
        $this->assertSame('active', $visit->qrCode()->value('status'));

        $this->at('11:30');
        $this->checkOut($this->activeCheckin($visit))->assertRedirect();

        $this->at('13:00');
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->post("/front-desk/checkin-checkout/{$visit->visit_request_id}/check-in", ['override_reason' => 'Phone battery dead'])->assertRedirect();
        $this->assertSame((int) $this->afternoonSchedule()->schedule_id, (int) $this->activeCheckin($visit)->schedule_id);
        $this->assertSame('used', $visit->qrCode()->value('status'));

        // No third entry.
        $this->at('14:00');
        $this->checkOut($this->activeCheckin($visit))->assertRedirect();
        $this->actingAs($this->frontDeskAccount, 'web');
        $this->post("/front-desk/checkin-checkout/{$visit->visit_request_id}/check-in")
            ->assertSessionHas('error', 'This visit is not confirmed.');
        $this->assertSame(2, VisitCheckin::count());
    }

    // ------------------------------------------------- Expiry and no-show

    public function test_whole_day_visitor_who_did_not_return_after_midday_is_completed_by_expiry(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('09:30');
        $token = $this->qrToken($visit);
        $this->checkIn($token)->assertOk();
        $this->at('11:30');
        $this->checkOut($this->activeCheckin($visit))->assertRedirect();

        // Still the visit day: nothing changes.
        $this->at('17:00');
        $this->artisan('visits:expire')->assertSuccessful();
        $this->assertSame('confirmed', $visit->fresh()->status);

        $this->at('08:00', '2026-10-10');
        $this->artisan('visits:expire')
            ->expectsOutput('Marked no-show: 0')
            ->expectsOutput('Completed attended visits: 1')
            ->assertSuccessful();

        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertSame('used', QrCode::where('qr_token', $token)->value('status'));
        // The unused afternoon seat stays counted, like a no-show's.
        $this->assertSame(1, (int) $this->afternoonSchedule()->slots_taken);
        $this->assertSame(1, $this->weeklyCount());
    }

    public function test_whole_day_visit_never_entered_becomes_no_show(): void
    {
        $visit = $this->confirmedVisit();

        $this->at('08:00', '2026-10-10');
        $this->artisan('visits:expire')
            ->expectsOutput('Marked no-show: 1')
            ->expectsOutput('Completed attended visits: 0')
            ->assertSuccessful();

        $this->assertSame('no_show', $visit->fresh()->status);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame(1, (int) $this->afternoonSchedule()->slots_taken);
    }

    public function test_visitor_still_inside_is_left_for_the_front_desk(): void
    {
        $visit = $this->confirmedVisit();
        $this->at('13:00');
        $this->checkIn($this->qrToken($visit))->assertOk();

        $this->at('08:00', '2026-10-10');
        $this->artisan('visits:expire')
            ->expectsOutput('Marked no-show: 0')
            ->expectsOutput('Completed attended visits: 0')
            ->assertSuccessful();

        $this->assertSame('confirmed', $visit->fresh()->status);
    }
}
