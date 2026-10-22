<?php

namespace Tests\Feature;

use App\Models\EligibilityAssessment;
use App\Models\Notification;
use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Approving a visitor-submitted request (assigned -> confirmed) needs an
 * `eligible` eligibility assessment:
 *   eligible                            -> approval allowed
 *   flagged_for_review (unreviewed)     -> blocked until Eligibility Review marks it eligible
 *   rejected (assessed or reviewed)     -> blocked, no override
 *   no assessment                       -> blocked until the check is run
 *
 * "Now" is pinned to Wednesday 2026-10-07 10:00 Asia/Manila; requests are
 * for Fri 2026-10-09 09:00 (seeded schedule, capacity 2).
 */
class VisitApprovalEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->completeRequirements(withVerifiedId: false); // approval needs a complete BJMP checklist; tests add the ID
        $this->withoutVite();
    }

    private function submittedRequest(): VisitRequest
    {
        return app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor,
            $this->relationship,
            CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'),
            '09:00'
        );
    }

    private function approve(VisitRequest $visit): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visit-requests.approve', [$visit->visitor_id, $visit->visit_request_id]));
    }

    private function verifiedId(): void
    {
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'x.png',
            'verification_status' => 'verified',
        ]);
    }

    private function assertStillAwaitingReview(VisitRequest $visit): void
    {
        $visit->refresh();
        $this->assertSame('assigned', $visit->status);
        $this->assertNull($visit->confirmed_at);
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken, 'The reserved seat stays reserved.');
        $this->assertFalse(Notification::where('related_record_id', $visit->visit_request_id)
            ->where('title', 'Visit Request Approved')->exists());
    }

    // ---------------------------------------------------------- Eligible

    public function test_eligible_assessment_allows_approval(): void
    {
        $this->verifiedId();
        $visit = $this->submittedRequest();
        $this->assertSame('eligible', $visit->eligibilityAssessment->overall_result);

        $this->approve($visit)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    // ------------------------------------------------------------ Flagged

    public function test_unreviewed_flagged_assessment_blocks_approval(): void
    {
        // No verified ID: the assessment flags "identity not yet verified".
        $visit = $this->submittedRequest();
        $this->assertSame('flagged_for_review', $visit->eligibilityAssessment->overall_result);

        $this->approve($visit)->assertSessionHasErrors([
            'eligibility' => 'The eligibility assessment flagged this request for review (Identity not yet verified.). Complete the review in Eligibility Review before approving.',
        ]);

        $this->assertStillAwaitingReview($visit);
    }

    public function test_flagged_assessment_marked_eligible_in_eligibility_review_allows_approval(): void
    {
        $this->relationship->update(['relationship_type' => 'spouse']);
        $visit = $this->submittedRequest();
        $assessment = $visit->eligibilityAssessment;
        $this->assertTrue($assessment->needsReview());
        $this->approve($visit)->assertSessionHasErrors('eligibility');

        // The authorized review: requirements met, then marked eligible.
        $this->verifiedId();
        RelationshipDocument::create([
            'relationship_id' => $this->relationship->relationship_id,
            'requirement_key' => 'marriage_certificate',
            'file_path' => 'relationship-documents/marriage_certificate.png',
            'status' => 'verified',
            'uploaded_at' => now(),
        ]);
        $this->actingAs($this->recordOfficerAccount)
            ->post(route('eligibility.review', $assessment->assessment_id), ['decision' => 'eligible'])
            ->assertSessionHas('success');
        $this->assertSame('eligible', $assessment->fresh()->overall_result);
        $this->assertNotNull($assessment->fresh()->reviewed_at);

        $this->approve($visit)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_flagged_assessment_reviewed_as_rejected_blocks_approval(): void
    {
        $visit = $this->submittedRequest();

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('eligibility.review', $visit->eligibilityAssessment->assessment_id), ['decision' => 'rejected'])
            ->assertSessionHas('success');

        $this->approve($visit)->assertSessionHasErrors('eligibility');
        $this->assertStillAwaitingReview($visit);
    }

    // ----------------------------------------------------------- Rejected

    public function test_rejected_assessment_blocks_approval(): void
    {
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-BAD',
            'file_path' => 'x.png',
            'verification_status' => 'rejected',
        ]);
        $visit = $this->submittedRequest();
        $this->assertSame('rejected', $visit->eligibilityAssessment->overall_result);

        $this->approve($visit)->assertSessionHasErrors([
            'eligibility' => 'The eligibility assessment rejected this request (Visitor identity document was rejected.). Reject the request instead.',
        ]);

        $this->assertStillAwaitingReview($visit);
    }

    public function test_assessment_rejected_after_submission_blocks_approval_through_the_service(): void
    {
        $this->verifiedId();
        $visit = $this->submittedRequest();
        $visit->eligibilityAssessment->update(['overall_result' => 'rejected', 'flagged_reason' => 'Rejected on review.']);

        try {
            app(VisitAssignmentService::class)->approveVisitorRequest($visit);
            $this->fail('Approval of a rejected assessment should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('eligibility', $e->errors());
        }

        $this->assertStillAwaitingReview($visit);
    }

    // ------------------------------------------------------------ Missing

    public function test_missing_assessment_blocks_approval_until_the_check_is_run(): void
    {
        $this->verifiedId();
        $visit = $this->submittedRequest();
        EligibilityAssessment::where('visit_request_id', $visit->visit_request_id)->delete();

        $this->approve($visit)->assertSessionHasErrors([
            'eligibility' => 'This request has no eligibility assessment. Run the eligibility check before approving.',
        ]);
        $this->assertStillAwaitingReview($visit);

        // The pending table offers the existing "run check" action.
        $this->get(route('visitor.index'))
            ->assertOk()
            ->assertSee('Not assessed')
            ->assertSee(route('eligibility.run', $visit->visit_request_id), false);

        $this->post(route('eligibility.run', $visit->visit_request_id))->assertSessionHas('success');
        $this->assertSame('eligible', $visit->fresh()->eligibilityAssessment->overall_result);

        $this->approve($visit)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    // ---------------------------------------------- Existing checks remain

    public function test_eligible_assessment_does_not_bypass_relationship_verification(): void
    {
        $this->verifiedId();
        $visit = $this->submittedRequest();
        $this->relationship->update(['verification_status' => 'pending']);

        $this->approve($visit)->assertSessionHasErrors('relationship_id');
        $this->assertStillAwaitingReview($visit);
    }

    public function test_eligible_assessment_does_not_bypass_pdl_eligibility(): void
    {
        $this->verifiedId();
        $visit = $this->submittedRequest();
        $this->pdl->update(['custody_status' => 'released']);

        $this->approve($visit)->assertSessionHasErrors('pdl_id');
        $this->assertStillAwaitingReview($visit);
    }

    // ------------------------------------------------------------ Display

    public function test_pending_table_explains_what_blocks_approval(): void
    {
        $visit = $this->submittedRequest();

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.index'))
            ->assertOk()
            ->assertSee('FLAGGED FOR REVIEW')
            ->assertSee('Complete the review')
            ->assertSee(route('eligibility.index'), false);

        $visit->eligibilityAssessment->update(['overall_result' => 'rejected']);
        $this->get(route('visitor.index'))
            ->assertOk()
            ->assertSee('Cannot be approved. Reject this request.');
    }
}
