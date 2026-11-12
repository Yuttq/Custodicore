<?php

namespace Tests\Feature;

use App\Models\RelationshipDocument;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Services\WalkInService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * A walk-in's one Approve in the Review Queue approves the account, ID and
 * files, links the PDL, approves the relationship and saves the walk-in
 * decision as Approved (booking the visit the Front Desk chose), so nothing
 * stays pending. (Uses GD-free test images.)
 */
class WalkInOneStepApprovalTest extends TestCase
{
    use MakesTestImages;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY . ' 08:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->publishSessions(self::FRIDAY, self::FRIDAY);
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function registerWalkIn(): VisitorProfile
    {
        $this->actingAs($this->frontDeskAccount)->post(route('frontdesk.walk-ins.store'), [
            'first_name' => 'Lourdes', 'last_name' => 'Reyes', 'date_of_birth' => '1970-05-02', 'gender' => 'female',
            'contact_number' => '09171234567', 'address' => 'Brgy. San Isidro, Quezon City',
            'photo' => $this->fakePhoto('face.png'), 'id_type' => 'national_id', 'government_id' => $this->fakePhoto('id.png'),
            'pdl_id' => $this->pdl->pdl_id, 'requested_date' => self::FRIDAY, 'requested_start_times' => ['09:00'],
            'visit_purpose_type' => 'Family visit', 'visit_purpose' => 'Family visit', 'relationship_type' => 'spouse',
            'documents' => ['marriage_certificate' => UploadedFile::fake()->create('marriage.pdf', 200, 'application/pdf')],
            'consent' => '1',
        ])->assertSessionHasNoErrors();

        return VisitorProfile::where('full_name', 'Lourdes Reyes')->firstOrFail();
    }

    public function test_one_approve_leaves_nothing_pending_and_books_the_visit(): void
    {
        $walkIn = $this->registerWalkIn();
        $this->assertSame('pending', $walkIn->verification_status);

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.approve', $walkIn->visitor_id))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Walk-in approved: visit booked'));

        $walkIn->refresh();
        $this->assertSame('verified', $walkIn->verification_status);
        $this->assertSame(['verified'], $walkIn->idDocuments()->pluck('verification_status')->unique()->values()->all());
        $this->assertSame(0, RelationshipDocument::where('visitor_id', $walkIn->visitor_id)->where('status', 'pending')->count());

        $relationship = $walkIn->relationships()->sole();
        $this->assertSame($this->pdl->pdl_id, $relationship->pdl_id);
        $this->assertSame('verified', $relationship->verification_status);

        $assessment = app(WalkInService::class)->assessment($walkIn);
        $this->assertSame('approved', $assessment->decision);
        $visit = VisitRequest::where('visitor_id', $walkIn->visitor_id)->sole();
        $this->assertSame('confirmed', $visit->status);

        // No longer "waiting for your decision"; the Front Desk can check them in.
        $this->assertFalse(app(WalkInService::class)->awaitingDecision()->contains('visitor_id', $walkIn->visitor_id));
        $this->actingAs($this->frontDeskAccount)->get(route('frontdesk.walk-ins.show', $walkIn))->assertOk()->assertSee('Check In Now');
    }

    public function test_a_blocker_keeps_the_decision_pending_and_says_why(): void
    {
        $walkIn = $this->registerWalkIn();
        $walkIn->flags()->create([
            'flag_type' => 'rule_violation', 'description' => 'Brought a prohibited item last time.', 'status' => 'active',
            'flagged_by' => $this->recordOfficerAccount->staffProfile->staff_id,
        ]);

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.approve', $walkIn->visitor_id))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Walk-in decision still Pending') && str_contains($m, 'active flag'));

        $this->assertSame('verified', $walkIn->fresh()->verification_status);
        $this->assertSame('pending', app(WalkInService::class)->assessment($walkIn->fresh())->decision);
        $this->assertSame(0, VisitRequest::where('visitor_id', $walkIn->visitor_id)->count());
    }

    public function test_command_finishes_walk_ins_approved_the_old_way(): void
    {
        // Approved before one Approve also saved the walk-in decision: account and ID approved, decision Pending.
        $walkIn = $this->registerWalkIn();
        $walkIn->idDocuments()->update(['verification_status' => 'verified']);
        $walkIn->update(['verification_status' => 'verified', 'verified_by' => $this->recordOfficerAccount->staffProfile->staff_id, 'verified_at' => now()]);
        $this->assertTrue(app(WalkInService::class)->awaitingDecision()->contains('visitor_id', $walkIn->visitor_id));

        $this->artisan('cc:finish-approved-walk-ins', ['--dry-run' => true])->expectsOutputToContain('Would finish')->assertSuccessful();
        $this->assertSame('pending', app(WalkInService::class)->assessment($walkIn->fresh())->decision);

        $this->artisan('cc:finish-approved-walk-ins')->expectsOutputToContain('Walk-in approved: visit booked')->assertSuccessful();

        $this->assertSame('approved', app(WalkInService::class)->assessment($walkIn->fresh())->decision);
        $this->assertSame('confirmed', VisitRequest::where('visitor_id', $walkIn->visitor_id)->sole()->status);
        $this->artisan('cc:finish-approved-walk-ins')->expectsOutputToContain('No approved walk-ins are waiting')->assertSuccessful();
    }
}
