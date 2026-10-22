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
        $this->completeRequirements(withVerifiedId: false); // approval needs a complete BJMP checklist; tests add the ID
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

    public function test_every_request_in_visitor_management_is_also_in_step_two(): void
    {
        $this->verifiedId();
        $passed = $this->submit();

        // Requests in every state Visitor Management can show: passed, flagged,
        // blocked, never checked, and past their visit date. Enough of them
        // to spill onto a second page of Step 2.
        $visits = collect([$passed]);
        foreach (['flagged_for_review', 'rejected', null, 'eligible', 'eligible', 'eligible', 'eligible', 'eligible', 'eligible', 'eligible', 'eligible'] as $result) {
            $copy = $passed->replicate();
            $copy->save();
            if ($result !== null) {
                $passed->eligibilityAssessment->replicate()
                    ->fill(['visit_request_id' => $copy->visit_request_id, 'overall_result' => $result, 'reviewed_at' => null])
                    ->save();
            }
            $visits->push($copy);
        }
        $this->travel(3)->days(); // every visit date has now passed

        $this->actingAs($this->recordOfficerAccount, 'web');
        $managementIds = $this->get('/visitors')->assertOk()->viewData('pendingVisitRequests')->pluck('visit_request_id')->sort()->values()->all();
        $this->assertSame($visits->pluck('visit_request_id')->sort()->values()->all(), $managementIds);

        $queueIds = collect([1, 2])->flatMap(
            fn ($page) => $this->get("/eligibility?page={$page}")->assertOk()->viewData('assessments')->pluck('visit_request_id')
        )->sort()->values()->all();
        $this->assertSame($managementIds, $queueIds);
        $this->get('/eligibility')->assertSee('title="' . count($managementIds) . ' waiting for review"', false);
    }

    public function test_incomplete_requirements_block_approve_on_both_pages(): void
    {
        $this->verifiedId();
        $visit = $this->submit();
        $this->assertSame('eligible', $visit->eligibilityAssessment->overall_result);

        // The check passed, then staff rejected the PDL birth certificate.
        RelationshipDocument::where('relationship_id', $this->relationship->relationship_id)->update(['status' => 'rejected']);
        $blocked = 'Requirements not complete. Waiting on: ';

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->get('/eligibility')->assertOk()
            ->assertSee($blocked)
            ->assertSee('<button type="submit" class="btn btn-blue" disabled', false);
        $this->get('/visitors')->assertOk()
            ->assertSee($blocked)
            ->assertDontSee(route('visitor.visit-requests.approve', [$visit->visitor_id, $visit->visit_request_id]), false);

        // Posting anyway is refused by both approve routes.
        $this->post("/review-queue/visits/{$visit->visit_request_id}/approve")
            ->assertSessionHas('error', fn ($m) => str_contains($m, $blocked));
        $this->post(route('visitor.visit-requests.approve', [$visit->visitor_id, $visit->visit_request_id]))
            ->assertSessionHasErrors('requirements');
        $this->assertSame('assigned', $visit->fresh()->status);

        // Once the document is verified again, approval goes through.
        RelationshipDocument::where('relationship_id', $this->relationship->relationship_id)->update(['status' => 'verified']);
        $this->post("/review-queue/visits/{$visit->visit_request_id}/approve")->assertSessionHas('success');
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_visit_history_and_pdl_restriction_link_to_their_pages(): void
    {
        $this->verifiedId();
        $this->submit();

        $this->actingAs($this->recordOfficerAccount, 'web')->get('/eligibility')->assertOk()
            ->assertSee('href="' . route('visitor.show', $this->visitor->visitor_id) . '#flags"', false)
            ->assertSee('href="' . route('pdl.show', $this->pdl->pdl_id) . '#restrictions"', false);
    }

    public function test_view_documents_shows_the_submitted_requirement_files(): void
    {
        $this->verifiedId();
        $this->submit();
        $id = VisitorId::where('visitor_id', $this->visitor->visitor_id)->firstOrFail();
        $document = RelationshipDocument::where('relationship_id', $this->relationship->relationship_id)->firstOrFail();

        $this->actingAs($this->recordOfficerAccount, 'web')->get('/eligibility')->assertOk()
            ->assertSee('View documents (2)')
            ->assertSee('Submitted documents')
            ->assertSee(route('visitor.ids.file', [$this->visitor->visitor_id, $id->getKey()]), false)
            ->assertSee(route('relationship-documents.file', $document->document_id), false);
    }
}
