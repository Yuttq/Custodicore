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
 * The Front Desk checks the walk-in's original ID and documents in person
 * and approves the arrival itself ("desk_verified"): the account, ID,
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
            'desk_verified' => '1',
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

    public function test_the_desk_approves_and_books_a_new_walk_in_at_once(): void
    {
        $this->register()->assertSessionHasNoErrors()
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
        $this->assertSame($this->deskStaffId(), (int) $assessment->processed_by);
        $this->assertSame(WalkInService::DESK_APPROVAL_REMARK, $assessment->remarks);

        $visit = VisitRequest::where('visitor_id', $walkIn->visitor_id)->firstOrFail();
        $this->assertSame('confirmed', $visit->status);
        $this->assertTrue($visit->is_walk_in);
        $this->assertSame($assessment->visit_request_id, $visit->visit_request_id);
        $slot = VisitSchedule::whereDate('schedule_date', self::FRIDAY)->where('time_slot_start', '09:00:00')->firstOrFail();
        $this->assertSame(1, (int) $slot->slots_taken);

        $this->get(route('frontdesk.walk-ins.show', $walkIn))->assertOk()->assertSee('Approved');
    }

    public function test_without_the_desk_check_it_still_goes_to_records(): void
    {
        $this->register(['desk_verified' => null])->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'waiting for the Records Officer'));

        $walkIn = $this->walkIn();
        $this->assertSame('pending', $walkIn->verification_status);
        $this->assertSame('pending', $walkIn->walkInAssessments()->first()->decision);
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_a_legal_guardian_needs_the_no_immediate_family_confirmation(): void
    {
        $guardian = [
            'relationship_type' => 'legal_guardian',
            'documents' => ['guardianship_authorization' => UploadedFile::fake()->image('court-order.jpg')],
        ];

        // Not confirmed: nothing of the approval is saved; Records decides.
        $this->register($guardian)->assertSessionHasNoErrors()
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'could not be approved at the desk')
                && str_contains($message, 'waiting for the Records Officer'));
        $walkIn = $this->walkIn();
        $this->assertSame('pending', $walkIn->verification_status);
        $this->assertSame('pending', $walkIn->idDocuments()->first()->verification_status);
        $this->assertSame(0, $walkIn->relationships()->count());
        $this->assertSame('pending', $walkIn->walkInAssessments()->first()->decision);
        $this->assertSame(0, VisitRequest::count());

        // Confirmed at the desk: approved and booked.
        $this->register($guardian + [
            'first_name' => 'Pedro', 'last_name' => 'Cruz', 'date_of_birth' => '1980-01-01', 'no_immediate_family' => '1',
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $relationship = $this->walkIn('Pedro Cruz')->relationships()->firstOrFail();
        $this->assertSame('verified', $relationship->verification_status);
        $this->assertNotNull($relationship->confirmation('no_immediate_family'));
        $this->assertSame(1, VisitRequest::count());
    }

    public function test_the_desk_approves_a_returning_walk_ins_new_arrival(): void
    {
        $this->register()->assertSessionMissing('error');
        $walkIn = $this->walkIn();

        $this->actingAs($this->frontDeskAccount)
            ->post(route('frontdesk.walk-ins.arrivals.store', $walkIn), [
                'visit_purpose' => 'Deliver food',
                'pdl_id' => $this->pdl->pdl_id,
                'requested_date' => self::NEXT_FRIDAY,
                'requested_start_times' => ['09:00'],
                'desk_verified' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'is approved') && str_contains($message, 'Oct 16, 2026'));

        $this->assertSame(2, VisitRequest::where('visitor_id', $walkIn->visitor_id)->where('status', 'confirmed')->count());
        $arrival = $walkIn->walkInAssessments()->first();
        $this->assertSame('approved', $arrival->decision);
        $this->assertSame('Deliver food', $arrival->visit_purpose);
        $this->assertSame($this->deskStaffId(), (int) $arrival->processed_by);
    }

    public function test_a_flagged_visitor_is_not_approved_at_the_desk(): void
    {
        $this->register()->assertSessionMissing('error');
        $walkIn = $this->walkIn();
        $walkIn->flags()->create([
            'flag_type' => 'prohibited_item_attempt',
            'description' => 'Contraband attempt.',
            'flagged_by' => $this->deskStaffId(),
            'status' => 'active',
        ]);

        $this->actingAs($this->frontDeskAccount)
            ->post(route('frontdesk.walk-ins.arrivals.store', $walkIn), [
                'visit_purpose' => 'Family visit',
                'pdl_id' => $this->pdl->pdl_id,
                'requested_date' => self::NEXT_FRIDAY,
                'requested_start_times' => ['09:00'],
                'desk_verified' => '1',
            ])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'active flag'));

        $this->assertSame('pending', $walkIn->walkInAssessments()->first()->decision);
        $this->assertSame(1, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());
    }
}
