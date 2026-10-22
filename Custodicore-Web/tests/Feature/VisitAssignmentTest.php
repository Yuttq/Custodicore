<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Notification;
use App\Models\Pdl;
use App\Models\StaffProfile;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

class VisitAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
    }

    public function test_record_officer_can_assign_valid_visit(): void
    {
        $response = $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertRedirect(route('visitor.show', $this->visitor->visitor_id));
        $response->assertSessionHas('success');

        $visit = VisitRequest::first();
        $this->assertNotNull($visit);
        $this->assertSame('pending_confirmation', $visit->status);
        $this->assertSame($this->visitor->visitor_id, $visit->visitor_id);
        $this->assertSame($this->pdl->pdl_id, $visit->pdl_id);
        $this->assertSame($this->schedule->schedule_id, $visit->schedule_id);

        $this->assertSame(1, $this->schedule->fresh()->slots_taken);

        $notification = Notification::where('account_id', $this->visitorAccount->account_id)->first();
        $this->assertNotNull($notification);
        $this->assertSame('visits', $notification->notification_type);
        $this->assertSame('visit_requests', $notification->related_record_type);
        $this->assertSame($visit->visit_request_id, $notification->related_record_id);
    }

    public function test_schedule_capacity_is_respected(): void
    {
        $this->schedule->update(['slots_taken' => 2, 'status' => 'full', 'max_capacity' => 2]);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('schedule_id');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_rejected_visitor_cannot_be_assigned(): void
    {
        $this->visitor->update(['verification_status' => 'rejected']);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertSessionHasErrors('visitor_id');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_pending_visitor_cannot_be_assigned(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertSessionHasErrors('visitor_id');
        $this->assertSame(0, VisitRequest::count());
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
    }

    public function test_inactive_pdl_is_rejected(): void
    {
        $this->pdl->update(['custody_status' => 'released']);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertSessionHasErrors('pdl_id');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_invalid_relationship_is_rejected(): void
    {
        $otherPdl = Pdl::create([
            'pdl_number' => 'PDL-TEST-002',
            'full_name' => 'Other PDL',
            'date_of_birth' => '1991-01-01',
            'gender' => 'female',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);

        $wrongRel = VisitorPdlRelationship::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $otherPdl->pdl_id,
            'relationship_type' => 'approved_relative',
            'priority_tier' => 'requires_verification',
            'verification_status' => 'verified',
        ]);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $wrongRel->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertSessionHasErrors('relationship_id');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_rejected_relationship_is_rejected(): void
    {
        $this->relationship->update(['verification_status' => 'rejected']);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertSessionHasErrors('relationship_id');
    }

    public function test_duplicate_assignment_is_rejected(): void
    {
        app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertSessionHasErrors('schedule_id');
        $this->assertSame(1, VisitRequest::count());
    }

    public function test_front_desk_cannot_assign_visits(): void
    {
        $response = $this->actingAs($this->frontDeskAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertForbidden();
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_visitor_cannot_assign_visits(): void
    {
        $response = $this->actingAs($this->visitorAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertForbidden();
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_capacity_fills_after_second_assignment(): void
    {
        $secondAccount = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'carlo.ramos',
            'email' => 'carlo.ramos@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $secondVisitor = VisitorProfile::create([
            'account_id' => $secondAccount->account_id,
            'full_name' => 'Carlo J. Ramos',
            'date_of_birth' => '1979-11-02',
            'gender' => 'male',
            'contact_number' => '09285550199',
            'verification_status' => 'verified',
        ]);
        $secondRel = VisitorPdlRelationship::create([
            'visitor_id' => $secondVisitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'legal_counsel',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]);

        $service = app(VisitAssignmentService::class);
        $service->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $service->assign([
            'visitor_id' => $secondVisitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $secondRel->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);

        $schedule = $this->schedule->fresh();
        $this->assertSame(2, $schedule->slots_taken);
        $this->assertSame('full', $schedule->status);

        $this->expectException(ValidationException::class);
        $service->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
    }

    // ------------------------------------------- Session end time (today)

    /** Pins "now" to $time (H:i:s, Asia/Manila) on the fixture schedule's own date (09:00–11:30). */
    private function onScheduleDayAt(string $time): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse(
            $this->schedule->schedule_date->toDateString().' '.$time,
            config('visitation.timezone')
        ));
    }

    private function assignViaForm(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);
    }

    /** The same end-of-session answer from staff assignment, Manage Visitor Slots and the visitor calendar. */
    private function assertSameEndedRuleEverywhere(bool $ended): void
    {
        $this->assertSame(! $ended, app(\App\Services\VisitSlotService::class)->isEditable($this->schedule->fresh()));

        \Laravel\Sanctum\Sanctum::actingAs($this->visitorAccount);
        $date = $this->schedule->schedule_date->toDateString();
        $slot = collect($this->getJson("/api/schedules/availability?from={$date}&to={$date}")
            ->assertOk()
            ->json('relationships.0.days.0.slots'))->firstWhere('startTime', '09:00');
        $this->assertSame($ended ? 'ended' : null, $slot['reason']);
    }

    public function test_assignment_to_todays_session_is_allowed_before_it_ends(): void
    {
        $this->onScheduleDayAt('11:29:59');
        $this->assertSameEndedRuleEverywhere(false);

        $this->assignViaForm()->assertSessionHasNoErrors()->assertSessionHas('success');

        $visit = VisitRequest::sole();
        $this->assertSame('pending_confirmation', $visit->status);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
        $this->assertTrue(Notification::where('account_id', $this->visitorAccount->account_id)
            ->where('title', 'Visit Assigned — Confirmation Required')->exists());
    }

    public function test_assignment_is_refused_exactly_at_the_session_end_time(): void
    {
        $this->onScheduleDayAt('11:30:00');
        $this->assertSameEndedRuleEverywhere(true);

        $this->assignViaForm()->assertSessionHasErrors([
            'schedule_id' => 'This session has already ended. Choose a later session.',
        ]);

        $this->assertSame(0, VisitRequest::count());
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame(0, Notification::count());
    }

    public function test_assignment_is_refused_after_the_session_end_time(): void
    {
        $this->onScheduleDayAt('11:31:00');
        $this->assertSameEndedRuleEverywhere(true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This session has already ended. Choose a later session.');
        try {
            app(VisitAssignmentService::class)->assign([
                'visitor_id' => $this->visitor->visitor_id,
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);
        } finally {
            $this->assertSame(0, VisitRequest::count());
            $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
        }
    }

    // ------------------------------------- Assign form: session options

    /**
     * The fixture day's afternoon session (13:00–16:30) and the next week's
     * morning session, alongside the fixture morning session (09:00–11:30).
     *
     * @return array{0: \App\Models\VisitSchedule, 1: \App\Models\VisitSchedule}
     */
    private function addAfternoonAndNextWeekSessions(): array
    {
        $afternoonRule = \App\Models\FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'fri',
            'time_slot_start' => '13:00:00',
            'time_slot_end' => '16:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);
        $day = $this->schedule->schedule_date->toDateString();

        $afternoon = \App\Models\VisitSchedule::create([
            'rule_id' => $afternoonRule->rule_id, 'schedule_date' => $day,
            'time_slot_start' => '13:00:00', 'time_slot_end' => '16:30:00',
            'max_capacity' => 30, 'slots_taken' => 0, 'status' => 'open',
        ]);
        $nextWeek = \App\Models\VisitSchedule::create([
            'rule_id' => $this->rule->rule_id, 'schedule_date' => $this->schedule->schedule_date->copy()->addWeek()->toDateString(),
            'time_slot_start' => '09:00:00', 'time_slot_end' => '11:30:00',
            'max_capacity' => 2, 'slots_taken' => 0, 'status' => 'open',
        ]);

        return [$afternoon, $nextWeek];
    }

    /**
     * Sessions the Record Officer's assign form offers for the fixture
     * relationship, as "Y-m-d H:i", up to a week after the fixture day.
     */
    private function assignFormSessions(): array
    {
        $html = $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.show', $this->visitor->visitor_id))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('#<script type="application/json" id="assignDays">(.*?)</script>#s', $html, $m));
        $days = json_decode(html_entity_decode($m[1]), true)[$this->relationship->relationship_id] ?? [];
        $until = $this->schedule->schedule_date->copy()->addWeek()->toDateString();

        return collect($days)
            ->filter(fn ($day) => $day['date'] <= $until)
            ->flatMap(fn ($day) => collect($day['sessions'])->where('available', true)->map(fn ($s) => "{$day['date']} {$s['start']}"))
            ->values()
            ->all();
    }

    /** One day of the assign form's data for the fixture relationship. */
    private function assignFormDay(string $date): array
    {
        $html = $this->actingAs($this->recordOfficerAccount)->get(route('visitor.show', $this->visitor->visitor_id))->getContent();
        preg_match('#<script type="application/json" id="assignDays">(.*?)</script>#s', $html, $m);

        return collect(json_decode(html_entity_decode($m[1]), true)[$this->relationship->relationship_id])->firstWhere('date', $date);
    }

    /** "Y-m-d H:i" for the fixture visiting day, or $weeks later. */
    private function sessionKey(string $start, int $weeks = 0): string
    {
        return $this->schedule->schedule_date->copy()->addWeeks($weeks)->toDateString().' '.$start;
    }

    public function test_assign_form_offers_todays_session_until_it_ends(): void
    {
        $this->addAfternoonAndNextWeekSessions();

        $this->onScheduleDayAt('11:29:59');

        $this->assertSame(
            [$this->sessionKey('09:00'), $this->sessionKey('13:00'), $this->sessionKey('09:00', 1), $this->sessionKey('13:00', 1)],
            $this->assignFormSessions()
        );
    }

    public function test_assign_form_hides_a_session_exactly_at_its_end_time(): void
    {
        $this->addAfternoonAndNextWeekSessions();

        $this->onScheduleDayAt('11:30:00');

        $this->assertSame([$this->sessionKey('13:00'), $this->sessionKey('09:00', 1), $this->sessionKey('13:00', 1)], $this->assignFormSessions());
    }

    public function test_assign_form_hides_a_session_that_ended_earlier_today_but_keeps_later_ones(): void
    {
        $this->addAfternoonAndNextWeekSessions();

        // Midday break: the morning ended earlier today, the afternoon is still ahead.
        $this->onScheduleDayAt('12:15:00');
        $this->assertSame([$this->sessionKey('13:00'), $this->sessionKey('09:00', 1), $this->sessionKey('13:00', 1)], $this->assignFormSessions());

        // Evening: both of today's sessions are over; next week's remain.
        $this->onScheduleDayAt('16:30:00');
        $this->assertSame([$this->sessionKey('09:00', 1), $this->sessionKey('13:00', 1)], $this->assignFormSessions());
    }

    public function test_assign_form_offers_next_week_when_todays_only_session_has_ended(): void
    {
        $this->onScheduleDayAt('11:30:00');

        // Today's only session is over; the PDL's next visiting day is offered.
        $this->assertSame([$this->sessionKey('09:00', 1)], $this->assignFormSessions());

        // The server still refuses the ended session if it is posted anyway.
        $this->assignViaForm()->assertSessionHasErrors([
            'schedule_id' => 'This session has already ended. Choose a later session.',
        ]);
        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'relationship_id' => $this->relationship->relationship_id,
                'date' => $this->schedule->schedule_date->toDateString(),
                'start_times' => ['09:00'],
            ])->assertSessionHasErrors('startTime');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_assign_form_still_hides_full_closed_and_past_sessions(): void
    {
        [$afternoon, $nextWeek] = $this->addAfternoonAndNextWeekSessions();
        $this->onScheduleDayAt('10:00:00');
        $afternoon->update(['status' => 'closed']);
        $nextWeek->update(['status' => 'full']);
        \App\Models\VisitSchedule::create([
            'rule_id' => $this->rule->rule_id,
            'schedule_date' => $this->schedule->schedule_date->copy()->subWeek()->toDateString(),
            'time_slot_start' => '09:00:00', 'time_slot_end' => '11:30:00',
            'max_capacity' => 2, 'slots_taken' => 0, 'status' => 'open',
        ]);

        $this->assertSame([$this->sessionKey('09:00'), $this->sessionKey('13:00', 1)], $this->assignFormSessions());
    }

    public function test_end_time_is_judged_in_the_facility_timezone(): void
    {
        // The 11:30 end is Manila time (UTC+8), i.e. 03:30 UTC — not 11:30 UTC.
        $date = $this->schedule->schedule_date->toDateString();

        $this->travelTo(\Carbon\CarbonImmutable::parse("{$date} 03:29:59", 'UTC'));
        $this->assertSameEndedRuleEverywhere(false);

        $this->travelTo(\Carbon\CarbonImmutable::parse("{$date} 03:30:00", 'UTC'));
        $this->assertSameEndedRuleEverywhere(true);
        $this->assignViaForm()->assertSessionHasErrors([
            'schedule_id' => 'This session has already ended. Choose a later session.',
        ]);
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_record_officer_assigns_a_whole_day_visit_with_both_sessions(): void
    {
        // Only the afternoon rule exists; its schedule is created on demand.
        \App\Models\FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related', 'day_of_week' => 'fri',
            'time_slot_start' => '13:00:00', 'time_slot_end' => '16:30:00',
            'max_capacity' => 30, 'effective_from' => '2024-01-01',
        ]);
        $this->onScheduleDayAt('08:00:00');
        $day = $this->schedule->schedule_date->toDateString();

        // The form offers both sessions of the day and a "Whole day" option, like the mobile app.
        $this->assertSame([$this->sessionKey('09:00'), $this->sessionKey('13:00')], array_slice($this->assignFormSessions(), 0, 2));
        $day0 = $this->assignFormDay($day);
        $this->assertSame(['start' => '09:00', 'end' => '16:30', 'startTimes' => ['09:00', '13:00'], 'available' => true],
            array_intersect_key($day0['wholeDay'], array_flip(['start', 'end', 'startTimes', 'available'])));

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'relationship_id' => $this->relationship->relationship_id,
                'date' => $day,
                'start_times' => ['09:00', '13:00'],
            ])
            ->assertRedirect(route('visitor.show', $this->visitor->visitor_id))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'whole day'));

        // One visit, two sessions, a seat taken in each.
        $visit = VisitRequest::sole();
        $this->assertSame('pending_confirmation', $visit->status);
        $this->assertNotNull($visit->confirmation_deadline);
        $sessions = $visit->sessionSchedules()->orderBy('time_slot_start')->get();
        $this->assertSame(['09:00', '13:00'], $sessions->map(fn ($s) => substr($s->time_slot_start, 0, 5))->all());
        $this->assertSame([1, 1], $sessions->map(fn ($s) => (int) $s->slots_taken)->all());
        $this->assertTrue(Notification::where('account_id', $this->visitor->account_id)
            ->where('title', 'Visit Assigned — Confirmation Required')->exists());

        // Declining releases both seats.
        app(VisitAssignmentService::class)->declineAssignedVisit($visit, 'Cannot make it.');
        $this->assertSame([0, 0], $visit->sessionSchedules()->orderBy('time_slot_start')->get()->map(fn ($s) => (int) $s->slots_taken)->all());
    }

    public function test_assign_needs_a_day_and_at_least_one_session(): void
    {
        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'relationship_id' => $this->relationship->relationship_id,
            ])
            ->assertSessionHasErrors([
                'date' => 'Choose a visiting day.',
                'start_times' => 'Choose a session: morning, afternoon or whole day.',
            ]);
        $this->assertSame(0, VisitRequest::count());
    }
}
