<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Bug: a visit request booked in the app whose eligibility check passed was
 * never shown in the Review Queue (only flagged checks were), so officers
 * couldn't find it. Now every request awaiting staff review is listed, and
 * Approve/Reject decide it in one step.
 */
class ReviewQueueVisitDecisionTest extends TestCase
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
    }

    private function verifiedId(): void
    {
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'ID-1',
            'file_path' => 'x.png', 'verification_status' => 'verified',
        ]);
    }

    private function submit(): VisitRequest
    {
        return app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor, $this->relationship, CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'), '09:00'
        );
    }

    public function test_request_that_passed_the_check_is_in_the_queue_and_can_be_approved(): void
    {
        $this->verifiedId();
        $visit = $this->submit();
        $this->assertSame('eligible', $visit->eligibilityAssessment->overall_result);
        $this->assertSame('assigned', $visit->status);

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->get('/eligibility')->assertOk()
            ->assertSee($this->visitor->full_name)
            ->assertSee('Requested by the visitor in the app')
            ->assertSee('Ready to approve.');
        $this->get('/review-queue')->assertRedirect(route('eligibility.index'));

        $this->post("/review-queue/visits/{$visit->visit_request_id}/approve")
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Visit approved'));

        $this->assertSame('confirmed', $visit->fresh()->status);
        $this->assertTrue(Notification::where('account_id', $this->visitor->account_id)->where('title', 'Visit Request Approved')->exists());
        $this->get('/eligibility')->assertSee('Nothing is waiting for review.');
    }

    public function test_flagged_request_is_approved_in_one_step_once_requirements_are_met(): void
    {
        $this->relationship->update(['relationship_type' => 'spouse']);
        $visit = $this->submit(); // no verified ID yet → flagged
        $assessment = $visit->eligibilityAssessment;
        $this->assertSame('flagged_for_review', $assessment->overall_result);

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->post("/review-queue/visits/{$visit->visit_request_id}/approve")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Waiting on'));
        $this->assertSame('assigned', $visit->fresh()->status);

        $this->verifiedId();
        RelationshipDocument::create([
            'relationship_id' => $this->relationship->relationship_id, 'requirement_key' => 'marriage_certificate',
            'file_path' => 'm.png', 'status' => 'verified', 'uploaded_at' => now(),
        ]);

        $this->post("/review-queue/visits/{$visit->visit_request_id}/approve")->assertSessionHas('success');

        $this->assertSame('confirmed', $visit->fresh()->status);
        $this->assertSame('eligible', $assessment->fresh()->overall_result);
        $this->assertSame($this->recordOfficerAccount->staffProfile->staff_id, $assessment->fresh()->reviewed_by);
    }

    public function test_reject_needs_a_reason_and_releases_the_request(): void
    {
        $this->verifiedId();
        $visit = $this->submit();
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->post("/review-queue/visits/{$visit->visit_request_id}/reject", [])->assertSessionHasErrors('reason');
        $this->assertSame('assigned', $visit->fresh()->status);

        $this->post("/review-queue/visits/{$visit->visit_request_id}/reject", ['reason' => 'Visiting hours are full that day.'])
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $visit->fresh()->status);
        $this->assertTrue(Notification::where('account_id', $this->visitor->account_id)
            ->where('message', 'like', '%Visiting hours are full that day.%')->exists());
    }

    public function test_sidebar_counts_requests_that_passed_the_check(): void
    {
        $this->verifiedId();
        $this->submit();

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->get('/dashboard')
            ->assertSee('title="1 waiting for review"', false);
    }
}
