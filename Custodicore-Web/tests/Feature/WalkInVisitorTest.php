<?php

namespace Tests\Feature;

use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Walk-in visitors (no phone): the Front Desk registers them, the Record
 * Officer approves, links the PDL and decides in the walk-in assessment, then
 * the Front Desk books today's visit, confirmed at once, and checks them in.
 */
class WalkInVisitorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    /** A Friday: the fixture rule has a 09:00–11:30 session. */
    private const FRIDAY = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 08:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
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
            'address' => 'Brgy. San Isidro',
            'photo' => UploadedFile::fake()->image('face.jpg'),
            'id_type' => 'national_id',
            'id_number' => 'PSN-1234-5678',
            'government_id' => UploadedFile::fake()->image('id.jpg'),
            'intended_pdl_name' => 'Example PDL',
            'intended_pdl_number' => 'PDL-TEST-001',
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

    public function test_front_desk_registers_a_walk_in_without_email(): void
    {
        $this->register()->assertSessionHasNoErrors()->assertRedirect();

        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $this->assertSame('walk_in', $walkIn->registered_via);
        $this->assertSame('pending', $walkIn->verification_status);
        $this->assertSame('Example Pdl', $walkIn->intended_pdl_name); // cleaned like the app's (Sanitizer::name)
        $this->assertSame('Spouse', $walkIn->relationship_hint);
        $this->assertSame(['Lourdes', 'Reyes'], [$walkIn->first_name, $walkIn->last_name]);
        $this->assertSame('PDL-TEST-001', $walkIn->intended_pdl_number);
        $assessment = $walkIn->walkInAssessments()->firstOrFail();
        $this->assertSame('Family visit', $assessment->visit_purpose);
        $this->assertSame('pending', $assessment->decision);
        $this->assertNull($assessment->processed_by);
        $this->assertSame($this->frontDeskAccount->staffProfile->staff_id, $walkIn->walk_in_registered_by);
        $this->assertNull($walkIn->account->email);
        $this->assertNotNull($walkIn->photo_path);
        $this->assertSame(1, VisitorId::where('visitor_id', $walkIn->visitor_id)->where('verification_status', 'pending')->count());
        $this->assertTrue(RelationshipDocument::where('visitor_id', $walkIn->visitor_id)->where('requirement_key', 'marriage_certificate')->exists());
        // No PDL is linked: the Record Officer does that.
        $this->assertSame(0, $walkIn->relationships()->count());

        $this->get(route('frontdesk.walk-ins.index'))
            ->assertOk()
            ->assertSee('Lourdes Reyes')
            ->assertSee('Pending Approval');
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
            'has_children_together' => '0',
            'documents' => ['cenomar_visitor' => UploadedFile::fake()->image('c1.jpg'), 'cenomar_pdl' => UploadedFile::fake()->image('c2.jpg')],
        ])->assertSessionHasErrors(['documents.witness_statement']);

        $this->assertSame(0, VisitorProfile::where('registered_via', 'walk_in')->count());
    }

    public function test_a_minor_needs_a_guardian_and_birth_certificate_but_no_id(): void
    {
        $minor = ['date_of_birth' => '2015-01-10', 'relationship_type' => 'child', 'id_type' => null, 'id_number' => null, 'government_id' => null];

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

    public function test_records_approval_books_the_visit_so_the_desk_only_checks_in(): void
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

        $visit = VisitRequest::where('visitor_id', $walkIn->visitor_id)->firstOrFail();
        $this->assertSame('confirmed', $visit->status);
        $this->assertTrue($visit->is_walk_in);
        $this->assertSame($visit->visit_request_id, $walkIn->walkInAssessments()->first()->visit_request_id);

        // The desk just checks the visitor in.
        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertOk()
            ->assertSee('Rico P. Salcedo')
            ->assertSee('Approved')->assertSee('Check In Now')
            ->assertSee('09:00–11:30')
            ->assertSee(e(route('frontdesk.checkin-checkout', ['checkin' => 'Lourdes Reyes'])), false);
        $this->get(route('frontdesk.schedule', 'expected'))->assertOk()->assertSee('Lourdes Reyes');

        // Front Desk verifies the ID, takes it and checks the visitor in.
        $this->post(route('frontdesk.checkin-checkout.check-in', $visit), [
            'verification_method' => 'manual_override',
            'id_surrendered_type' => 'national_id',
            'id_number' => 'PSN-1234-5678',
            'override_reason' => 'Walk-in visitor approved by Records.',
        ])->assertSessionHasNoErrors();

        $this->get(route('frontdesk.walk-ins.show', $walkIn))
            ->assertSee('Checked In')
            ->assertDontSee('Check In Now');
        // Checked-in walk-ins stay under "All" (there is no Checked In tab).
        $this->get(route('frontdesk.walk-ins.index'))->assertSee('Lourdes Reyes')->assertDontSee('status=checked-in', false);
    }

    public function test_approved_walk_in_can_check_in_even_on_a_non_visiting_day(): void
    {
        // A Thursday: the PDL's group only has visiting on Fridays.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'Asia/Manila'));
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $this->approveAndLink($walkIn);

        $this->assessAsRecords($walkIn)
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'check the visitor in now (09:00–11:30)'));

        $visit = VisitRequest::where('visitor_id', $walkIn->visitor_id)->with('schedule')->firstOrFail();
        $this->assertSame('confirmed', $visit->status);
        $this->assertTrue($visit->is_walk_in);
        $this->assertSame('2026-10-08', $visit->schedule->schedule_date->toDateString());
        // It does not open the day for app bookings.
        $this->assertFalse((bool) $visit->schedule->is_special);
        $this->assertSame('closed', $visit->schedule->status);

        $this->get(route('frontdesk.walk-ins.show', $walkIn))->assertSee('Approved')->assertSee('Check In Now');
        $this->get(route('frontdesk.checkin-checkout'))->assertOk()->assertSee('Lourdes Reyes');
    }

    public function test_nothing_is_set_up_after_visiting_hours(): void
    {
        $this->register();
        $walkIn = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $this->approveAndLink($walkIn);

        // Approved after the last session of the day ended.
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 18:00:00', 'Asia/Manila'));
        $this->assessAsRecords($walkIn)
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'visiting hours are over'));
        $this->assertSame(0, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());
        $this->get(route('frontdesk.walk-ins.show', $walkIn))->assertSee('visiting hours are over');
    }

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

    public function test_pdl_id_number_is_checked_against_the_pdl_records(): void
    {
        $this->actingAs($this->frontDeskAccount);

        // Live check, as on the Records PDL profile (case does not matter).
        $this->getJson(route('frontdesk.walk-ins.pdl-lookup', ['number' => 'pdl-test-001']))
            ->assertExactJson(['found' => true, 'pdlNumber' => 'PDL-TEST-001', 'fullName' => 'Example PDL']);
        $this->getJson(route('frontdesk.walk-ins.pdl-lookup', ['number' => 'PDL-0000-99999']))
            ->assertExactJson(['found' => false]);

        // A number that no PDL has is refused; blank is fine.
        $this->register(['intended_pdl_number' => 'PDL-0000-99999'])->assertSessionHasErrors('intended_pdl_number');
        $this->register(['intended_pdl_number' => ''])->assertSessionHasNoErrors();
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
        $this->register();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00', 'Asia/Manila'));
        $this->register(['first_name' => 'Pedro', 'last_name' => 'Cruz', 'date_of_birth' => '1980-01-01', 'intended_pdl_name' => 'Juan Dela Cruz', 'intended_pdl_number' => '']);

        // Search: visitor, or the PDL name / number given.
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
        $this->assertSame('verified', $assessment->pdl_verification); // "Example PDL" / PDL-TEST-001 exists
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
            ->assertSee('PDL-TEST-001');

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

    public function test_pdl_verification_is_automatic(): void
    {
        $service = app(\App\Services\WalkInService::class);

        $this->register();
        $byBoth = VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
        $this->assertSame('verified', $service->checks($byBoth)['pdl_verification']);

        $this->register(['first_name' => 'Ana', 'last_name' => 'Lim', 'intended_pdl_name' => 'Somebody Else', 'intended_pdl_number' => 'pdl-test-001']);
        $byNumber = VisitorProfile::where('full_name', 'Ana Lim')->firstOrFail();
        $this->assertSame([$this->pdl->pdl_id], $service->checks($byNumber)['matches']->pluck('pdl_id')->all());

        $this->register(['first_name' => 'Ben', 'last_name' => 'Tan', 'intended_pdl_name' => 'Nobody Known', 'intended_pdl_number' => null]);
        $notFound = VisitorProfile::where('full_name', 'Ben Tan')->firstOrFail();
        $this->assertSame('not_found', $service->checks($notFound)['pdl_verification']);

        // The Records Officer sees the match; it is linked once the documents are verified.
        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.show', $byBoth->visitor_id))
            ->assertSee('Matches Example PDL (PDL-TEST-001')
            ->assertSee('Linked automatically as Spouse once every document is verified');
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
