<?php

namespace Tests\Feature;

use App\Models\RelationshipDocument;
use App\Models\StaffProfile;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Services\WalkInService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * The Front Desk registers the walk-in and decides its Walk-in Assessment
 * in the same save, after checking the original ID and documents in
 * person. Approving verifies the account, ID,
 * documents and relationship are verified and the chosen session booked in
 * one step. Anything that blocks it leaves the arrival Pending for Records.
 */
class WalkInDeskApprovalTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    private const NEXT_FRIDAY = '2026-10-16';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 08:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->publishSessions(self::FRIDAY, self::FRIDAY);
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function register(array $overrides = [])
    {
        return $this->actingAs($this->frontDeskAccount)->post(route('frontdesk.walk-ins.store'), array_merge([
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
            'decision' => 'approved',
            'walk_in_reason' => 'no_prior_booking',
        ], $overrides));
    }

    private function deskStaffId(): int
    {
        return StaffProfile::where('account_id', $this->frontDeskAccount->account_id)->value('staff_id');
    }

    private function walkIn(string $name = 'Lourdes Reyes'): VisitorProfile
    {
        return VisitorProfile::where('full_name', $name)->firstOrFail();
    }

    /** The Front Desk's walk-in assessment. */
    private function assess(VisitorProfile $walkIn, array $data = [])
    {
        return $this->actingAs($this->frontDeskAccount)->post(route('frontdesk.walk-ins.assessment', $walkIn), $data + [
            'decision' => 'approved',
            'walk_in_reason' => 'no_prior_booking',
        ]);
    }

    private function recordArrival(VisitorProfile $walkIn, string $purpose = 'Family visit')
    {
        return $this->actingAs($this->frontDeskAccount)->post(route('frontdesk.walk-ins.arrivals.store', $walkIn), [
            'visit_purpose' => $purpose,
            'pdl_id' => $this->pdl->pdl_id,
            'requested_date' => self::NEXT_FRIDAY,
            'requested_start_times' => ['09:00'],
            'decision' => 'approved',
            'walk_in_reason' => 'no_prior_booking',
        ]);
    }

    public function test_one_save_registers_and_approves_the_walk_in(): void
    {
        $this->actingAs($this->frontDeskAccount)->get(route('frontdesk.walk-ins.create'))->assertOk()
            ->assertSee('5 · Walk-in Assessment')->assertSee('PDL verification (automatic)')->assertSee('Save and approve');

        $this->register(['remarks' => 'Originals checked.'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'is approved') && str_contains($message, 'today (09:00–11:30)'));

        $walkIn = $this->walkIn();
        $this->assertSame('verified', $walkIn->verification_status);
        $this->assertSame($this->deskStaffId(), (int) $walkIn->verified_by);
        $this->assertSame('verified', $walkIn->idDocuments()->first()->verification_status);
        $this->assertSame(0, RelationshipDocument::where('status', 'pending')->count());

        $relationship = $walkIn->relationships()->firstOrFail();
        $this->assertSame('verified', $relationship->verification_status);
        $this->assertSame($this->pdl->pdl_id, (int) $relationship->pdl_id);

        $assessment = $walkIn->walkInAssessments()->first();
        $this->assertSame('approved', $assessment->decision);
        $this->assertSame('verified', $assessment->pdl_verification);
        $this->assertSame('no_prior_booking', $assessment->walk_in_reason);
        $this->assertSame($this->deskStaffId(), (int) $assessment->processed_by);
        $this->assertSame('Originals checked.', $assessment->remarks);

        $visit = VisitRequest::where('visitor_id', $walkIn->visitor_id)->firstOrFail();
        $this->assertSame('confirmed', $visit->status);
        $this->assertTrue($visit->is_walk_in);
        $this->assertSame($assessment->visit_request_id, $visit->visit_request_id);
        $slot = VisitSchedule::whereDate('schedule_date', self::FRIDAY)->where('time_slot_start', '09:00:00')->firstOrFail();
        $this->assertSame(1, (int) $slot->slots_taken);

        $this->get(route('frontdesk.walk-ins.show', $walkIn))->assertOk()
            ->assertSee('Approved')->assertSee('Check In Now')->assertDontSee('Save Walk-in Decision');
    }

    public function test_one_save_can_reject_the_walk_in(): void
    {
        $this->register(['decision' => 'declined'])->assertSessionHasErrors('remarks');
        $this->register(['walk_in_reason' => 'other'])->assertSessionHasErrors('remarks');

        $this->register(['decision' => 'declined', 'remarks' => 'Marriage certificate names do not match.'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'rejected'));

        $assessment = $this->walkIn()->walkInAssessments()->first();
        $this->assertSame('declined', $assessment->decision);
        $this->assertSame($this->deskStaffId(), (int) $assessment->processed_by);
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_a_legal_guardian_needs_the_no_immediate_family_confirmation(): void
    {
        $this->register([
            'relationship_type' => 'legal_guardian',
            'documents' => ['guardianship_authorization' => UploadedFile::fake()->image('court-order.jpg')],
        ])->assertSessionHasNoErrors()
            // Not confirmed: registered, but nothing of the approval is saved.
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'could not be approved'));
        $walkIn = $this->walkIn();
        $this->get(route('frontdesk.walk-ins.show', $walkIn))->assertSee('Save Walk-in Decision');
        $walkIn->refresh();
        $this->assertSame('pending', $walkIn->verification_status);
        $this->assertSame('pending', $walkIn->idDocuments()->first()->verification_status);
        $this->assertSame(0, $walkIn->relationships()->count());
        $this->assertSame('pending', $walkIn->walkInAssessments()->first()->decision);
        $this->assertSame(0, VisitRequest::count());

        // Confirmed on the walk-in's page: approved and booked.
        $this->assess($walkIn, ['no_immediate_family' => '1'])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $relationship = $walkIn->relationships()->firstOrFail();
        $this->assertSame('verified', $relationship->verification_status);
        $this->assertNotNull($relationship->confirmation('no_immediate_family'));
        $this->assertSame(1, VisitRequest::count());
    }

    public function test_the_desk_approves_a_returning_walk_ins_new_arrival(): void
    {
        $this->register()->assertSessionMissing('error');
        $walkIn = $this->walkIn();

        $this->recordArrival($walkIn, 'Deliver food')->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'is approved') && str_contains($message, 'Oct 16, 2026'));

        $this->assertSame(2, VisitRequest::where('visitor_id', $walkIn->visitor_id)->where('status', 'confirmed')->count());
        $arrival = $walkIn->walkInAssessments()->first();
        $this->assertSame('approved', $arrival->decision);
        $this->assertSame('Deliver food', $arrival->visit_purpose);
        $this->assertSame($this->deskStaffId(), (int) $arrival->processed_by);
    }

    public function test_a_flagged_visitor_is_not_approved(): void
    {
        $this->register()->assertSessionMissing('error');
        $walkIn = $this->walkIn();
        $walkIn->flags()->create([
            'flag_type' => 'prohibited_item_attempt',
            'description' => 'Contraband attempt.',
            'flagged_by' => $this->deskStaffId(),
            'status' => 'active',
        ]);

        $this->recordArrival($walkIn)->assertSessionHas('error', fn ($message) => str_contains($message, 'active flag'));
        $this->get(route('frontdesk.walk-ins.show', $walkIn))->assertSee('Active flag: cannot be approved');

        $this->assertSame('pending', $walkIn->walkInAssessments()->first()->decision);
        $this->assertSame(1, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());
    }
}
