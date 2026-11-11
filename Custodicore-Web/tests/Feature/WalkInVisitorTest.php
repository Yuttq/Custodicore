<?php

namespace Tests\Feature;

use App\Models\Pdl;
use App\Models\RelationshipDocument;
use App\Models\StaffProfile;
use App\Models\SystemSetting;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Walk-in visitors (no phone), in six phases: the Front Desk finds or
 * registers the visitor and selects the PDL (1), chooses a session Records
 * opened (2); the Record Officer verifies and approves or rejects once (3);
 * approval books the chosen session (4); the Front Desk checks the visitor
 * in (5) and out (6).
 */
class WalkInVisitorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    /** A Friday: the fixture rule has a 09:00–11:30 session. */
    private const FRIDAY = '2026-10-09';

    /** The next Friday: the fixture's own open session. */
    private const NEXT_FRIDAY = '2026-10-16';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 08:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        // Records opened today's session too.
        $this->publishSessions(self::FRIDAY, self::FRIDAY);
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Lourdes',
            'last_name' => 'Reyes',
            'date_of_birth' => '1970-05-02',
            'gender' => 'female',
            'contact_number' => '09171234567',
            'address' => 'Brgy. San Isidro, Quezon City',
            'photo' => UploadedFile::fake()->image('face.jpg'),
            'id_type' => 'national_id',
            'government_id' => UploadedFile::fake()->image('id.jpg'),
            'pdl_id' => $this->pdl->pdl_id,
            'requested_date' => self::FRIDAY,
            'requested_start_times' => ['09:00'],
            'visit_purpose_type' => 'Family visit',
            'visit_purpose' => 'Family visit',
            'relationship_type' => 'spouse',
            'documents' => ['marriage_certificate' => UploadedFile::fake()->create('marriage.pdf', 200, 'application/pdf')],
            'consent' => '1',
        ], $overrides);
    }

    private function register(array $overrides = [])
    {
        return $this->actingAs($this->frontDeskAccount)->post(route('frontdesk.walk-ins.store'), $this->form($overrides));
    }

    private function assessment(array $overrides = []): array
    {
        return array_merge([
            'decision' => 'approved',
            'walk_in_reason' => 'no_prior_booking',
            'remarks' => '',
        ], $overrides);
    }

    /** The Record Officer's walk-in assessment, then back to the Front Desk. */
    private function assessAsRecords(VisitorProfile $walkIn, array $overrides = [])
    {
        $response = $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.walk-in-assessment', $walkIn->visitor_id), $this->assessment($overrides));
        $this->actingAs($this->frontDeskAccount);

        return $response;
    }

    /** What the Record Officer does in the Review Queue, done directly. */
    private function approveAndLink(VisitorProfile $walkIn): VisitorPdlRelationship
    {
        $walkIn->idDocuments()->update(['verification_status' => 'verified']);
        $walkIn->update(['verification_status' => 'verified', 'verified_at' => now()]);

        $relationship = VisitorPdlRelationship::create([
            'visitor_id' => $walkIn->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'spouse',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]);
        RelationshipDocument::where('visitor_id', $walkIn->visitor_id)
            ->update(['relationship_id' => $relationship->relationship_id, 'status' => 'verified']);

        return $relationship;
    }

    private function todaysSchedule(): VisitSchedule
    {
        return VisitSchedule::whereDate('schedule_date', self::FRIDAY)->where('time_slot_start', '09:00:00')->firstOrFail();
    }

    // ------------------------------------------- Phase 1: visitor information

    public function test_front_desk_registers_a_walk_in_without_email(): void
    {
        $this->register()->assertSessionHasNoErrors()->assertRedirect();

        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $this->assertSame('walk_in', $walkIn->registered_via);
        $this->assertSame('pending', $walkIn->verification_status);
        // The PDL the desk selected.
        $this->assertSame('Example PDL', $walkIn->intended_pdl_name);
        $this->assertSame('PDL-TEST-001', $walkIn->intended_pdl_number);
        $this->assertSame('Spouse', $walkIn->relationship_hint);
        $this->assertSame(['Lourdes', 'Reyes'], [$walkIn->first_name, $walkIn->last_name]);
        $assessment = $walkIn->walkInAssessments()->firstOrFail();
        $this->assertSame('Family visit', $assessment->visit_purpose);
        $this->assertSame('pending', $assessment->decision);
        $this->assertSame($this->pdl->pdl_id, $assessment->pdl_id);
        $this->assertSame(self::FRIDAY, $assessment->requested_date->toDateString());
        $this->assertSame(['09:00'], $assessment->requested_start_times);
        $this->assertNull($assessment->processed_by);
        $this->assertSame($this->frontDeskAccount->staffProfile->staff_id, $walkIn->walk_in_registered_by);
        $this->assertNull($walkIn->account->email);
        $this->assertNotNull($walkIn->photo_path);
        $this->assertSame(1, VisitorId::where('visitor_id', $walkIn->visitor_id)->where('verification_status', 'pending')->count());
        $this->assertTrue(RelationshipDocument::where('visitor_id', $walkIn->visitor_id)->where('requirement_key', 'marriage_certificate')->exists());
        // No PDL is linked yet: the Record Officer verifies the relationship first.
        $this->assertSame(0, $walkIn->relationships()->count());
        // Choosing a session reserves nothing until Records approves.
        $this->assertSame(0, (int) $this->todaysSchedule()->slots_taken);
        $this->assertSame(0, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());

        $this->get(route('frontdesk.walk-ins.index'))
            ->assertOk()
            ->assertSee('Lourdes Reyes')
            ->assertSee('Pending Approval');
        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertSee('Fri, Oct 9, 2026 · 09:00');
    }

    public function test_the_desk_searches_existing_visitors_before_registering(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();

        $this->get(route('frontdesk.walk-ins.create', ['q' => 'lourdes']))
            ->assertOk()
            ->assertSee('Lourdes Reyes')
            ->assertSee(route('frontdesk.walk-ins.show', $walkIn), false);
        // App visitors are found too.
        $this->get(route('frontdesk.walk-ins.create', ['q' => 'santos']))
            ->assertSee('Maria D. Santos')
            ->assertSee('App user');
        $this->get(route('frontdesk.walk-ins.create', ['q' => 'nobody here']))
            ->assertSee('is registered. Register them below.', false);
    }

    public function test_pdl_search_finds_partial_names(): void
    {
        $this->actingAs($this->frontDeskAccount);

        // Any part of the name, alias or number; every word must match.
        $this->getJson(route('frontdesk.walk-ins.pdl-search', ['q' => 'examp']))
            ->assertOk()
            ->assertJsonPath('results.0.id', $this->pdl->pdl_id)
            ->assertJsonPath('results.0.number', 'PDL-TEST-001')
            ->assertJsonPath('results.0.visitable', true);
        $this->getJson(route('frontdesk.walk-ins.pdl-search', ['q' => 'pdl-test']))->assertJsonCount(1, 'results');
        $this->getJson(route('frontdesk.walk-ins.pdl-search', ['q' => 'example nobody']))->assertJsonCount(0, 'results');
        $this->getJson(route('frontdesk.walk-ins.pdl-search', ['q' => 'e']))->assertJsonCount(0, 'results');
    }

    public function test_a_pdl_must_be_selected(): void
    {
        $this->register(['pdl_id' => null])->assertSessionHasErrors('pdl_id');
        $this->register(['pdl_id' => 999999])->assertSessionHasErrors('pdl_id');
        $this->assertSame(0, VisitorProfile::where('registered_via', 'walk_in')->count());
    }

    public function test_walk_in_comes_first_in_the_review_queue(): void
    {
        VisitorProfile::where('visitor_id', $this->visitor->visitor_id)->update(['verification_status' => 'pending', 'created_at' => now()->subDay()]);
        $this->register();

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('account-reviews.index'))
            ->assertOk()
            ->assertSeeInOrder(['Lourdes Reyes', 'Walk-in · waiting at gate', 'Maria D. Santos'])
            ->assertSee('No email (walk-in)');
    }

    public function test_relationship_documents_are_required(): void
    {
        $this->register(['documents' => []])
            ->assertSessionHasErrors(['documents.marriage_certificate']);

        $this->register([
            'relationship_type' => 'live_in_partner',
            'has_children_together' => '1',
            'documents' => ['cenomar_visitor' => UploadedFile::fake()->image('c1.jpg'), 'cenomar_pdl' => UploadedFile::fake()->image('c2.jpg')],
        ])->assertSessionHasErrors(['documents.child_birth_certificate']);

        $this->assertSame(0, VisitorProfile::where('registered_via', 'walk_in')->count());
    }

    public function test_a_live_in_partner_without_children_needs_no_witness_statement(): void
    {
        $this->register([
            'relationship_type' => 'live_in_partner',
            'has_children_together' => '0',
            'documents' => ['cenomar_visitor' => UploadedFile::fake()->image('c1.jpg'), 'cenomar_pdl' => UploadedFile::fake()->image('c2.jpg')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, VisitorProfile::where('registered_via', 'walk_in')->count());
    }

    public function test_a_minor_needs_a_guardian_and_birth_certificate_but_no_id(): void
    {
        $minor = ['date_of_birth' => '2015-01-10', 'relationship_type' => 'child', 'id_type' => null, 'government_id' => null];

        $this->register($minor + ['documents' => ['visitor_birth_certificate' => UploadedFile::fake()->image('bc.jpg')]])
            ->assertSessionHasErrors(['guardian_full_name', 'guardian_relationship', 'guardian_contact_number']);

        $this->register($minor + [
            'documents' => ['visitor_birth_certificate' => UploadedFile::fake()->image('bc.jpg')],
            'guardian_full_name' => 'Ana Reyes',
            'guardian_relationship' => 'Mother',
            'guardian_contact_number' => '09171234567',
        ])->assertSessionHasNoErrors();
    }

    public function test_possible_duplicate_is_flagged_until_confirmed(): void
    {
        $this->register(['first_name' => 'Maria D.', 'last_name' => 'Santos', 'date_of_birth' => '1985-03-14'])
            ->assertSessionHasErrors(['duplicate'])
            ->assertSessionHas('duplicate_visitor_id', $this->visitor->visitor_id);

        $this->register(['first_name' => 'Maria D.', 'last_name' => 'Santos', 'date_of_birth' => '1985-03-14', 'not_duplicate' => '1'])
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------- Phase 2: date and session selection

    public function test_slots_lists_the_sessions_records_opened_with_seats(): void
    {
        $this->actingAs($this->frontDeskAccount);

        $days = $this->getJson(route('frontdesk.walk-ins.slots', ['pdl_id' => $this->pdl->pdl_id]))
            ->assertOk()
            ->json('days');

        // Today and next Friday are opened; the Fridays in between are not.
        $this->assertSame([self::FRIDAY, self::NEXT_FRIDAY], array_column($days, 'date'));
        $this->assertSame('09:00', $days[0]['slots'][0]['startTime']);
        $this->assertTrue($days[0]['slots'][0]['available']);
        $this->assertSame(2, $days[0]['slots'][0]['remaining']);

        // A full session is listed, but cannot be chosen.
        $this->todaysSchedule()->update(['slots_taken' => 2]);
        $this->getJson(route('frontdesk.walk-ins.slots', ['pdl_id' => $this->pdl->pdl_id]))
            ->assertJsonPath('days.0.slots.0.available', false)
            ->assertJsonPath('days.0.slots.0.reason', 'Full');

        // A session Records closed is not offered at all.
        $this->todaysSchedule()->update(['status' => 'closed']);
        $this->assertSame([self::NEXT_FRIDAY], array_column(
            $this->getJson(route('frontdesk.walk-ins.slots', ['pdl_id' => $this->pdl->pdl_id]))->json('days'), 'date'
        ));
    }

    public function test_only_an_open_session_with_seats_can_be_chosen(): void
    {
        // A Friday Records has not opened.
        $this->register(['requested_date' => '2026-10-23'])
            ->assertSessionHasErrors(['requested_date' => 'Visits are not allowed on the selected day.']);
        // No session at that time.
        $this->register(['requested_start_times' => ['14:00']])->assertSessionHasErrors('requested_start_times');
        // No session chosen.
        $this->register(['requested_date' => null, 'requested_start_times' => []])->assertSessionHasErrors(['requested_date', 'requested_start_times']);
        // Full.
        $this->todaysSchedule()->update(['slots_taken' => 2]);
        $this->register()->assertSessionHasErrors(['requested_start_times' => 'That session cannot be booked: Full.']);

        $this->assertSame(0, VisitorProfile::where('registered_via', 'walk_in')->count());

        // Another date Records opened is fine.
        $this->register(['requested_date' => self::NEXT_FRIDAY])->assertSessionHasNoErrors();
    }

    // ------------------------- Phases 3–6: approve once, booking, check-in/out

    public function test_records_approval_books_the_chosen_session_and_the_desk_checks_in_and_out(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();

        // Not approved yet: nothing booked.
        $this->get(route('frontdesk.walk-ins.show', $walkIn))->assertOk()->assertDontSee('Check In Now');

        $this->approveAndLink($walkIn);

        // Account approved and PDL linked: still waiting for the walk-in decision.
        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertOk()
            ->assertSee('Pending Approval')->assertSee('approve or reject decision');

        $this->assessAsRecords($walkIn)
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'check the visitor in now (09:00–11:30)'));

        // Phase 4: the chosen session is reserved and the visit confirmed.
        $visit = VisitRequest::where('visitor_id', $walkIn->visitor_id)->with('schedule')->firstOrFail();
        $this->assertSame('confirmed', $visit->status);
        $this->assertTrue($visit->is_walk_in);
        $this->assertSame($this->todaysSchedule()->schedule_id, $visit->schedule_id);
        $this->assertSame(1, (int) $this->todaysSchedule()->slots_taken);
        $this->assertSame($visit->visit_request_id, $walkIn->walkInAssessments()->first()->visit_request_id);

        // Phase 5: the desk verifies the ID, takes it and checks the visitor in.
        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertOk()
            ->assertSee('Rico P. Salcedo')
            ->assertSee('Approved')->assertSee('Check In Now')
            ->assertSee('09:00–11:30')
            ->assertSee(e(route('frontdesk.checkin-checkout', ['checkin' => 'Lourdes Reyes'])), false);
        $this->get(route('frontdesk.schedule', 'expected'))->assertOk()->assertSee('Lourdes Reyes');

        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 09:15:00', 'Asia/Manila'));
        $this->post(route('frontdesk.checkin-checkout.check-in', $visit), [
            'verification_method' => 'manual_override',
            'id_surrendered_type' => 'national_id',
            'override_reason' => 'Walk-in visitor approved by Records.',
        ])->assertSessionHasNoErrors();

        $checkin = $visit->checkins()->firstOrFail();
        $this->assertSame('checked_in', $checkin->status);
        $this->assertNotNull($checkin->check_in_time);
        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertSee('Checked In')
            ->assertDontSee('Check In Now');
        // Checked-in walk-ins stay under "All" (there is no Checked In tab).
        $this->get(route('frontdesk.walk-ins.index'))->assertSee('Lourdes Reyes')->assertDontSee('status=checked-in', false);

        // Phase 6: check-out records the time and completes the visit.
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 11:00:00', 'Asia/Manila'));
        $this->post(route('frontdesk.checkin-checkout.check-out', $checkin))->assertSessionHasNoErrors();
        $this->assertSame('checked_out', $checkin->fresh()->status);
        $this->assertNotNull($checkin->fresh()->check_out_time);
        $this->assertSame('completed', $visit->fresh()->status);
        $this->get(route('frontdesk.walk-ins.show', $walkIn))->assertSee('Checked Out');
    }

    public function test_a_later_date_is_booked_for_that_day(): void
    {
        $this->register(['requested_date' => self::NEXT_FRIDAY]);
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $this->approveAndLink($walkIn);

        $this->assessAsRecords($walkIn)
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Visit booked for Fri, Oct 16, 2026 (09:00–11:30)'));

        $visit = VisitRequest::where('visitor_id', $walkIn->visitor_id)->with('schedule')->sole();
        $this->assertSame('confirmed', $visit->status);
        $this->assertSame(self::NEXT_FRIDAY, $visit->schedule->schedule_date->toDateString());
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);

        // Approved, but checked in on that day, not today.
        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertSee('Approved')
            ->assertSee('booked for')
            ->assertDontSee('Check In Now');
        $this->get(route('frontdesk.checkin-checkout'))->assertDontSee('Lourdes Reyes');
    }

    public function test_the_decision_is_final(): void
    {
        [$walkIn] = $this->linkedWalkIn();
        $this->assessAsRecords($walkIn)->assertSessionHasNoErrors();

        // Approved: it cannot be changed to Rejected (or approved again).
        $this->assessAsRecords($walkIn, ['decision' => 'declined', 'remarks' => 'Changed my mind.'])
            ->assertSessionHasErrors('decision');
        $this->assertSame('approved', $walkIn->walkInAssessments()->first()->decision);
        $this->assertSame(1, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());

        // Rejected: it cannot be approved afterwards.
        $this->register(['first_name' => 'Pedro', 'last_name' => 'Cruz', 'date_of_birth' => '1980-01-01']);
        $rejected = VisitorProfile::where('full_name', 'Pedro Cruz')->firstOrFail();
        $this->assessAsRecords($rejected, ['decision' => 'declined', 'remarks' => 'Not on the PDL\'s visitor list.'])->assertSessionHasNoErrors();
        $this->approveAndLink($rejected);
        $this->assessAsRecords($rejected)->assertSessionHasErrors('decision');
        $this->assertSame('declined', $rejected->walkInAssessments()->first()->decision);

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.show', $rejected->visitor_id))
            ->assertSee('The decision is final');
    }

    public function test_an_approval_whose_session_ended_is_not_saved(): void
    {
        [$walkIn] = $this->linkedWalkIn();

        // Records gets to it after today's session ended.
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 18:00:00', 'Asia/Manila'));
        $this->assessAsRecords($walkIn)
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'already ended') && str_contains($message, 'stays Pending'));
        $this->assertSame('pending', $walkIn->walkInAssessments()->first()->decision);
        $this->assertSame(0, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());

        // The desk chooses another session; then Records approves.
        $this->post(route('frontdesk.walk-ins.slot.update', $walkIn), [
            'requested_date' => self::NEXT_FRIDAY,
            'requested_start_times' => ['09:00'],
        ])->assertSessionHasNoErrors();
        $this->assessAsRecords($walkIn)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(self::NEXT_FRIDAY, VisitRequest::where('visitor_id', $walkIn->visitor_id)->sole()->schedule->schedule_date->toDateString());
    }

    public function test_the_session_cannot_be_changed_after_the_decision(): void
    {
        [$walkIn] = $this->linkedWalkIn();
        $this->assessAsRecords($walkIn);

        $this->post(route('frontdesk.walk-ins.slot.update', $walkIn), [
            'requested_date' => self::NEXT_FRIDAY,
            'requested_start_times' => ['09:00'],
        ])->assertSessionHasErrors('requested_start_times');
        $this->assertSame(self::FRIDAY, $walkIn->walkInAssessments()->first()->requested_date->toDateString());
    }

    public function test_approval_needs_a_chosen_session(): void
    {
        [$walkIn] = $this->linkedWalkIn();
        // An arrival recorded before sessions were chosen at the desk.
        $walkIn->walkInAssessments()->first()->update(['requested_date' => null, 'requested_start_times' => null]);

        $this->assessAsRecords($walkIn)->assertSessionHasErrors('decision');
        $this->get(route('frontdesk.walk-ins.show', $walkIn))->assertSee('Choose Visit Date and Session');
    }

    // ------------------------------------- Walk-ins follow the booking rules

    /** A registered, approved and linked walk-in, ready for the Records decision. */
    private function linkedWalkIn(): array
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();

        return [$walkIn, $this->approveAndLink($walkIn)];
    }

    private function scheduleOn(string $date, array $overrides = []): VisitSchedule
    {
        return VisitSchedule::create($overrides + [
            'rule_id' => $this->rule->rule_id,
            'schedule_date' => $date,
            'time_slot_start' => '09:00:00',
            'time_slot_end' => '11:30:00',
            'max_capacity' => 2,
            'slots_taken' => 0,
            'status' => 'open',
        ]);
    }

    public function test_walk_in_at_the_weekly_limit_is_not_approved(): void
    {
        [$walkIn, $relationship] = $this->linkedWalkIn();
        SystemSetting::create(['setting_key' => 'visit.max_per_week', 'setting_value' => '1', 'updated_by' => StaffProfile::first()->staff_id]);
        // Already visited on Wednesday of this week.
        VisitRequest::create([
            'visitor_id' => $walkIn->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $relationship->relationship_id,
            'schedule_id' => $this->scheduleOn('2026-10-07', ['status' => 'closed'])->schedule_id,
            'status' => 'completed',
        ]);

        $this->assessAsRecords($walkIn)
            ->assertSessionHas('error', fn ($message) => str_contains($message, VisitAssignmentService::WEEKLY_LIMIT_MESSAGE));

        $this->assertSame(1, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count(), 'No walk-in visit is created.');
        $assessment = $walkIn->walkInAssessments()->first();
        $this->assertNull($assessment->visit_request_id);
        $this->assertSame('pending', $assessment->decision, 'Nothing was approved.');
    }

    public function test_walk_in_with_a_pending_visit_with_another_pdl_that_day_is_refused(): void
    {
        [$walkIn] = $this->linkedWalkIn();
        $otherPdl = Pdl::create([
            'pdl_number' => 'PDL-TEST-002',
            'full_name' => 'Second PDL',
            'date_of_birth' => '1991-01-01',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);
        $otherRelationship = VisitorPdlRelationship::create([
            'visitor_id' => $walkIn->visitor_id,
            'pdl_id' => $otherPdl->pdl_id,
            'relationship_type' => 'sibling',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]);
        // A visit request with the other PDL today, still awaiting review.
        $today = $this->todaysSchedule();
        $today->update(['slots_taken' => 1]);
        VisitRequest::create([
            'visitor_id' => $walkIn->visitor_id,
            'pdl_id' => $otherPdl->pdl_id,
            'relationship_id' => $otherRelationship->relationship_id,
            'schedule_id' => $today->schedule_id,
            'status' => 'assigned',
        ]);

        $this->assessAsRecords($walkIn)
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'another PDL on this date'));

        $this->assertSame(1, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());
        $this->assertSame(1, (int) $today->fresh()->slots_taken);
    }

    public function test_a_session_that_filled_up_is_not_booked(): void
    {
        [$walkIn] = $this->linkedWalkIn();
        // Every seat was taken after the desk chose the session.
        $this->todaysSchedule()->update(['slots_taken' => 2]);

        $this->assessAsRecords($walkIn)
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'full'));

        $this->assertSame(0, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());
        $this->assertSame(2, (int) $this->todaysSchedule()->slots_taken);
        $this->assertSame('pending', $walkIn->walkInAssessments()->first()->decision);
    }

    public function test_a_refused_booking_saves_nothing(): void
    {
        [$walkIn] = $this->linkedWalkIn();
        // The booking is refused at approval (e.g. eligibility).
        $assignments = Mockery::mock(VisitAssignmentService::class, [app(ScheduleAvailabilityService::class)])->makePartial();
        $assignments->shouldReceive('approveVisitorRequest')
            ->andThrow(ValidationException::withMessages(['eligibility' => 'Eligibility refused this visit.']));
        $this->app->instance(VisitAssignmentService::class, $assignments);

        $this->assessAsRecords($walkIn)
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'Eligibility refused this visit.'));

        $this->assertSame(0, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count(), 'Nothing is booked.');
        $this->assertSame(0, (int) $this->todaysSchedule()->slots_taken, 'The seat was not taken.');
        $this->assertSame('pending', $walkIn->walkInAssessments()->first()->decision);
    }

    // ------------------------------------------- Returning walk-ins (no phone)

    private function recordArrival(VisitorProfile $walkIn, string $purpose = 'Family visit', string $date = self::NEXT_FRIDAY)
    {
        return $this->actingAs($this->frontDeskAccount)
            ->post(route('frontdesk.walk-ins.arrivals.store', $walkIn), [
                'visit_purpose' => $purpose,
                'pdl_id' => $this->pdl->pdl_id,
                'requested_date' => $date,
                'requested_start_times' => ['09:00'],
            ]);
    }

    public function test_a_returning_walk_in_gets_a_new_arrival_instead_of_registering_again(): void
    {
        [$walkIn] = $this->linkedWalkIn();
        $this->assessAsRecords($walkIn);
        $this->assertSame(1, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());

        // A week later the visitor is back at the desk.
        $this->travelTo(CarbonImmutable::parse(self::NEXT_FRIDAY.' 08:00:00', 'Asia/Manila'));
        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertOk()
            ->assertSee('Registered')
            ->assertSee('Record New Arrival');

        $this->recordArrival($walkIn, 'Deliver food')->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(2, $walkIn->walkInAssessments()->count());
        $arrival = $walkIn->walkInAssessments()->first();
        $this->assertSame('pending', $arrival->decision);
        $this->assertSame('Deliver food', $arrival->visit_purpose);
        $this->assertSame('spouse', $arrival->relationship_type, 'From the verified relationship.');
        $this->assertSame(self::NEXT_FRIDAY, $arrival->requested_date->toDateString());
        $this->get(route('frontdesk.walk-ins.show', $walkIn))->assertSee('Pending Approval')->assertDontSee('Record New Arrival');

        // Records decides on the new arrival, which books the chosen session.
        $this->assessAsRecords($walkIn)
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'check the visitor in now'));

        $this->assertSame(2, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());
        $this->assertNotNull($walkIn->walkInAssessments()->first()->visit_request_id);
        $this->assertSame(1, VisitorProfile::where('registered_via', 'walk_in')->count(), 'No second registration.');
    }

    public function test_no_new_arrival_while_one_waits_or_a_visit_is_booked(): void
    {
        [$walkIn] = $this->linkedWalkIn();

        // The first arrival is still waiting for Records.
        $this->recordArrival($walkIn)->assertSessionHasErrors(['visit_purpose' => 'This visitor\'s arrival is already waiting for the Records Officer\'s decision.']);

        // Today's visit is booked.
        $this->assessAsRecords($walkIn);
        $this->recordArrival($walkIn)->assertSessionHasErrors(['visit_purpose' => 'This visitor already has a booked visit.']);

        $this->assertSame(1, $walkIn->walkInAssessments()->count());
    }

    public function test_a_new_arrival_needs_an_open_session(): void
    {
        [$walkIn] = $this->linkedWalkIn();
        $this->assessAsRecords($walkIn);
        $this->travelTo(CarbonImmutable::parse(self::NEXT_FRIDAY.' 08:00:00', 'Asia/Manila'));

        $this->recordArrival($walkIn, 'Family visit', '2026-10-23')->assertSessionHasErrors('requested_start_times');
        $this->assertSame(1, $walkIn->walkInAssessments()->count());
    }

    public function test_a_walk_in_not_yet_approved_cannot_get_a_new_arrival(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();

        $this->recordArrival($walkIn)->assertSessionHasErrors('visit_purpose');
        $this->get(route('frontdesk.walk-ins.show', $walkIn))->assertDontSee('Record New Arrival');
    }

    public function test_a_new_arrival_needs_a_purpose(): void
    {
        [$walkIn] = $this->linkedWalkIn();
        $this->assessAsRecords($walkIn);
        $this->travelTo(CarbonImmutable::parse(self::NEXT_FRIDAY.' 08:00:00', 'Asia/Manila'));

        $this->recordArrival($walkIn, '  ')->assertSessionHasErrors(['visit_purpose' => 'Enter the purpose of the visit.']);
        $this->assertSame(1, $walkIn->walkInAssessments()->count());
    }

    // ------------------------------- Visits assigned to walk-ins without the app

    public function test_a_visit_assigned_to_a_walk_in_without_the_app_is_confirmed_at_once(): void
    {
        [$walkIn, $relationship] = $this->linkedWalkIn();

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visits.assign', $walkIn->visitor_id), [
                'relationship_id' => $relationship->relationship_id,
                'date' => self::NEXT_FRIDAY,
                'start_times' => ['09:00'],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'assigned and confirmed'));

        $visit = VisitRequest::where('visitor_id', $walkIn->visitor_id)->sole();
        $this->assertSame('confirmed', $visit->status);
        $this->assertNotNull($visit->confirmed_at);
        $this->assertNull($visit->confirmation_deadline);
        $this->assertFalse((bool) $visit->is_walk_in, 'Booked ahead, not at the desk.');
    }

    public function test_a_walk_in_who_uses_the_app_still_confirms_there(): void
    {
        [$walkIn, $relationship] = $this->linkedWalkIn();
        $walkIn->account->forceFill(['last_login_at' => now()])->save();

        $visit = app(VisitAssignmentService::class)->assignSessions(
            $walkIn->fresh(), $relationship, CarbonImmutable::parse(self::NEXT_FRIDAY, 'Asia/Manila'), ['09:00']
        );

        $this->assertSame('pending_confirmation', $visit->status);
        $this->assertNotNull($visit->confirmation_deadline);
    }

    public function test_app_visitors_still_confirm_staff_assigned_visits(): void
    {
        $visit = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);

        $this->assertSame('pending_confirmation', $visit->status);
        $this->assertNull($visit->confirmed_at);
    }

    // ------------------------------------------------------- Front Desk pages

    public function test_cards_count_and_filter_walk_ins_by_status(): void
    {
        $this->register();
        $this->register(['first_name' => 'Pedro', 'last_name' => 'Cruz', 'date_of_birth' => '1980-01-01']);
        $approved = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $this->approveAndLink($approved);
        $this->assessAsRecords($approved);

        $this->get(route('frontdesk.walk-ins.index'))
            ->assertOk()
            ->assertSeeInOrder(['All', '2', 'Pending Approval', '1', 'Approved', '1', 'Rejected', '0']);

        $this->get(route('frontdesk.walk-ins.index', ['status' => 'approved']))
            ->assertOk()
            ->assertSee('Lourdes Reyes')
            ->assertDontSee('Pedro Cruz');

        // An empty tab still has the footer.
        $this->get(route('frontdesk.walk-ins.index', ['status' => 'rejected']))
            ->assertSee('Showing 0 of 0 visitors');

        $this->get(route('frontdesk.walk-ins.index', ['status' => 'pending']))
            ->assertSee('Pedro Cruz')
            ->assertDontSee('Lourdes Reyes');
    }

    public function test_pending_or_rejected_walk_ins_cannot_be_checked_in(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();

        // Pending: no visit, so not offered at the gate.
        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertSee('Pending Approval')
            ->assertDontSee('Check In Now');
        $this->get(route('frontdesk.checkin-checkout'))->assertDontSee('Lourdes Reyes');
        $this->assertSame(0, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());

        // Rejected: the request ends.
        $this->assessAsRecords($walkIn, ['decision' => 'declined', 'remarks' => 'Not on the PDL\'s visitor list.']);
        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertSee('Rejected')
            ->assertDontSee('Check In Now');
        $this->get(route('frontdesk.checkin-checkout'))->assertDontSee('Lourdes Reyes');
        $this->assertSame(0, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());
    }

    public function test_messages_are_short_lived_toasts(): void
    {
        // The confirmation after registering goes away after 5 seconds.
        $this->register()->assertRedirect();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertSee('data-toast="info"', false)
            ->assertSee('setTimeout(() => { toast.style.opacity = \'0\'; }, 5000);', false)
            // The status explanation is a toast too, not a box on the page.
            ->assertSee('<template id="walkInStatusMessage">', false);
    }

    public function test_walk_in_list_search_and_calendar(): void
    {
        $otherPdl = Pdl::create([
            'pdl_number' => 'PDL-TEST-002',
            'full_name' => 'Juan Dela Cruz',
            'date_of_birth' => '1991-01-01',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);
        $this->register();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00', 'Asia/Manila'));
        $this->register(['first_name' => 'Pedro', 'last_name' => 'Cruz', 'date_of_birth' => '1980-01-01', 'pdl_id' => $otherPdl->pdl_id, 'requested_date' => self::NEXT_FRIDAY])
            ->assertSessionHasNoErrors();

        // Search: visitor, or the PDL's name / number.
        $this->get(route('frontdesk.walk-ins.index', ['q' => 'pedro']))->assertSee('Pedro Cruz')->assertDontSee('Lourdes Reyes');
        $this->get(route('frontdesk.walk-ins.index', ['q' => 'PDL-TEST-001']))->assertSee('Lourdes Reyes')->assertDontSee('Pedro Cruz');

        // Calendar: the day they registered.
        $this->get(route('frontdesk.walk-ins.index', ['date' => '2026-10-09']))
            ->assertSee('Lourdes Reyes')
            ->assertDontSee('Pedro Cruz')
            ->assertSee('Clear')
            ->assertSee('name="date" value="2026-10-09"', false);

        $this->get(route('frontdesk.walk-ins.index', ['q' => 'nobody']))->assertSee('No walk-in visitors match.');
    }

    public function test_contact_numbers_must_be_11_digits(): void
    {
        $this->register(['contact_number' => '0917123456'])->assertSessionHasErrors('contact_number');
        $this->register(['contact_number' => '0917-123-4567'])->assertSessionHasNoErrors();
        $this->assertSame('09171234567', VisitorProfile::where('full_name', 'Lourdes Reyes')->value('contact_number'));
    }

    public function test_a_plus_63_mobile_number_is_accepted_and_stored_as_09(): void
    {
        $this->register(['contact_number' => '+639171234567'])->assertSessionHasNoErrors();
        $this->assertSame('09171234567', VisitorProfile::where('full_name', 'Lourdes Reyes')->value('contact_number'));
    }

    public function test_registration_fields_show_the_front_desk_messages(): void
    {
        $this->register([
            'first_name' => '   ',
            'last_name' => 'R',
            'gender' => '',
            'contact_number' => '',
            'address' => 'Abc',
            'photo' => UploadedFile::fake()->create('face.gif', 50, 'image/gif'),
            'relationship_type' => '',
            'visit_purpose_type' => '',
            'visit_purpose' => '',
            'consent' => '',
        ])->assertSessionHasErrors([
            'first_name' => 'Please enter the visitor’s first name.',
            'last_name' => 'Last name must be 2 to 50 characters.',
            'gender' => 'Please select the visitor’s sex.',
            'contact_number' => 'Please enter a contact number.',
            'address' => 'Address must be 5 to 200 characters.',
            'photo' => 'Photo must be a JPG or PNG file.',
            'relationship_type' => 'Please select the visitor’s relationship to the PDL.',
            'visit_purpose_type' => 'Please select the purpose of the visit.',
            'consent' => 'The visitor must agree to the Terms and Conditions and the Privacy Policy before proceeding.',
        ]);

        $this->register(['first_name' => '<b>Lou</b>rdes1'])->assertSessionHasErrors([
            'first_name' => 'First name can only contain letters, spaces, periods, hyphens, and apostrophes.',
        ]);
        $this->register(['first_name' => 'Niño', 'last_name' => "O'Brien-Dela Cruz Jr."])->assertSessionHasNoErrors();
    }

    public function test_dates_purpose_and_pdl_rules(): void
    {
        $this->register(['date_of_birth' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors(['date_of_birth' => 'Date of birth cannot be in the future.']);
        $this->register(['requested_date' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors(['requested_date' => 'Visit date cannot be in the past.']);
        $this->register(['visit_purpose_type' => 'other', 'visit_purpose' => ''])
            ->assertSessionHasErrors(['visit_purpose' => 'Please specify the purpose of the visit.']);
        $this->register(['pdl_id' => ''])
            ->assertSessionHasErrors(['pdl_id' => 'Please select the PDL to be visited.']);

        $this->pdl->forceFill(['custody_status' => 'released'])->save();
        $this->register()->assertSessionHasErrors(['pdl_id' => 'The selected PDL was not found or is no longer detained.']);
    }

    // ---------------------------------------------- Phase 3: Records review

    public function test_approving_a_walk_in_without_email_works(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $walkIn->idDocuments()->update(['verification_status' => 'verified']);

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.approve', $walkIn))
            ->assertSessionHas('success');

        $this->assertSame('verified', $walkIn->fresh()->verification_status);
    }

    public function test_records_officer_does_the_assessment(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();

        // Not the Front Desk's job.
        $this->post(route('visitor.walk-in-assessment', $walkIn->visitor_id), $this->assessment())->assertForbidden();

        // Approved only after the account is approved and the PDL relationship verified.
        $this->assessAsRecords($walkIn)->assertSessionHasErrors('decision');

        // Pending is fine meanwhile; it records who and when, and the automatic checks.
        $this->assessAsRecords($walkIn, ['decision' => 'pending'])->assertSessionHasNoErrors();
        $assessment = $walkIn->walkInAssessments()->first();
        $this->assertSame('verified', $assessment->pdl_verification); // the PDL the desk selected
        $this->assertSame('for_review', $assessment->visitor_eligibility);
        $this->assertSame('for_review', $assessment->authorized_visitor);
        $this->assertSame($this->recordOfficerAccount->staffProfile->staff_id, $assessment->processed_by);
        $this->assertNotNull($assessment->processed_at);

        // Declining, or "Other" as the reason, needs remarks.
        $this->assessAsRecords($walkIn, ['decision' => 'declined'])->assertSessionHasErrors('remarks');
        $this->assessAsRecords($walkIn, ['decision' => 'declined', 'remarks' => 'No valid ID presented.'])->assertSessionHasNoErrors();

        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertSee('Rejected')
            ->assertSee('No valid ID presented.');
    }

    public function test_review_queue_lists_approved_walk_ins_awaiting_the_decision(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $this->approveAndLink($walkIn);

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('account-reviews.index'))
            ->assertSee('Walk-ins waiting for your decision')
            ->assertSee('Lourdes Reyes');

        $this->get(route('visitor.show', $walkIn->visitor_id))
            ->assertOk()
            ->assertSee('Walk-in Assessment')
            ->assertSee('Family visit')
            ->assertSee('PDL-TEST-001')
            ->assertSee('Fri, Oct 9, 2026 · 09:00');

        $this->assessAsRecords($walkIn);
        $this->actingAs($this->recordOfficerAccount)
            ->get(route('account-reviews.index'))
            ->assertDontSee('Walk-ins waiting for your decision');
    }

    public function test_walk_in_list_keeps_everyone_registered(): void
    {
        $this->register();
        VisitorProfile::where('full_name', 'Lourdes Reyes')->update(['created_at' => now()->subDays(10)]);
        $this->register(['first_name' => 'Pedro', 'last_name' => 'Cruz', 'date_of_birth' => '1980-01-01']);

        $this->get(route('frontdesk.walk-ins.index'))
            ->assertOk()
            ->assertSeeInOrder(['Pedro Cruz', 'Lourdes Reyes'])
            ->assertDontSee('No walk-in visitors')
            ->assertSee('Showing 1–2 of 2 visitors');
    }

    public function test_pdl_verification_uses_the_selected_pdl(): void
    {
        $service = app(\App\Services\WalkInService::class);

        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $this->assertSame('verified', $service->checks($walkIn)['pdl_verification']);
        $this->assertSame([$this->pdl->pdl_id], $service->checks($walkIn)['matches']->pluck('pdl_id')->all());

        // The Records Officer sees the match; it is linked once the documents are verified.
        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.show', $walkIn->visitor_id))
            ->assertSee('Matches Example PDL (PDL-TEST-001')
            ->assertSee('Linked automatically as Spouse once every document is approved');

        // An older registration without a selected PDL is matched by the name given.
        $walkIn->walkInAssessments()->first()->update(['pdl_id' => null]);
        $walkIn->update(['intended_pdl_name' => 'Nobody Known', 'intended_pdl_number' => null]);
        $this->assertSame('not_found', $service->checks($walkIn->fresh())['pdl_verification']);
    }

    public function test_linking_the_matched_pdl_attaches_the_documents_for_review(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.relationships.store', $walkIn->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_type' => $walkIn->walkInAssessments()->first()->relationship_type,
            ])->assertSessionHasNoErrors();

        $relationship = $walkIn->relationships()->firstOrFail();
        $this->assertSame('spouse', $relationship->relationship_type);
        $this->assertSame($relationship->relationship_id, RelationshipDocument::where('visitor_id', $walkIn->visitor_id)->value('relationship_id'));
    }

    public function test_verified_documents_link_and_verify_the_relationship_automatically(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $document = RelationshipDocument::where('visitor_id', $walkIn->visitor_id)->firstOrFail();
        $id = $walkIn->idDocuments()->firstOrFail();
        $this->actingAs($this->recordOfficerAccount);

        $this->get(route('visitor.show', $walkIn->visitor_id))
            ->assertSee('Spouse · not linked yet');

        // Documents are reviewed before the PDL is linked; verifying the last one links it.
        $this->post(route('visitor.walk-in-documents.verify', [$walkIn->visitor_id, $document->document_id]))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Linked to Example PDL as Spouse'));

        $relationship = $walkIn->relationships()->firstOrFail();
        $this->assertSame($this->pdl->pdl_id, $relationship->pdl_id);
        $this->assertSame('pending', $relationship->verification_status); // the valid ID is not verified yet
        $this->assertSame($relationship->relationship_id, $document->fresh()->relationship_id);

        // Verifying the ID completes the checklist: the relationship is verified automatically.
        $this->post(route('visitor.ids.verify', [$walkIn->visitor_id, $id->visitor_id_doc_id]))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Relationship verified'));
        $this->assertSame('verified', $relationship->fresh()->verification_status);

        // Approving the account leaves nothing blocking the walk-in.
        $this->post(route('visitor.approve', $walkIn->visitor_id));
        $this->assertTrue(app(\App\Services\WalkInService::class)->checks($walkIn->fresh())['canApprove']);
        $this->assessAsRecords($walkIn)->assertSessionHasNoErrors();
    }

    public function test_a_rejected_document_is_not_linked(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $document = RelationshipDocument::where('visitor_id', $walkIn->visitor_id)->firstOrFail();

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.walk-in-documents.reject', [$walkIn->visitor_id, $document->document_id]), ['rejection_reason' => 'Names do not match.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $document->fresh()->status);
        $this->assertSame(0, $walkIn->relationships()->count());
    }

    public function test_every_file_must_be_reviewed_before_approval(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $relationship = $this->approveAndLink($walkIn);
        RelationshipDocument::where('relationship_id', $relationship->relationship_id)->update(['status' => 'pending']);

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.show', $walkIn->visitor_id))
            ->assertSee('Files to Review')
            ->assertSee('Marriage Certificate')
            ->assertSee('1 still waiting.', false);

        $this->assessAsRecords($walkIn)->assertSessionHasErrors('decision');

        RelationshipDocument::where('relationship_id', $relationship->relationship_id)->update(['status' => 'verified']);
        $this->assessAsRecords($walkIn)->assertSessionHasNoErrors();
        $this->assertSame('yes', $walkIn->walkInAssessments()->first()->authorized_visitor);
    }

    public function test_only_front_desk_officers_can_register_walk_ins(): void
    {
        $this->actingAs($this->recordOfficerAccount)->get(route('frontdesk.walk-ins.create'))->assertForbidden();
        $this->actingAs($this->frontDeskAccount)->get(route('frontdesk.walk-ins.create'))->assertOk()->assertSee('Marriage Certificate');
    }
}
