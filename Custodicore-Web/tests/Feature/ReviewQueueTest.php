<?php

namespace Tests\Feature;

use App\Models\EligibilityAssessment;
use App\Services\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Account Reviews and Eligibility Review are one "Review Queue" with two
 * steps (tabs), one sidebar link and one combined count.
 */
class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    private function flaggedVisitRequest(): void
    {
        $visit = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        EligibilityAssessment::updateOrCreate(['visit_request_id' => $visit->visit_request_id], [
            'identity_check_result' => 'flagged',
            'relationship_check_result' => 'pass',
            'history_check_result' => 'clean',
            'pdl_restriction_check_result' => 'no_restriction',
            'overall_result' => 'flagged_for_review',
            'flagged_reason' => 'Identity not yet verified.',
        ]);
    }

    public function test_queue_has_no_visit_request_step(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);

        $this->get('/account-reviews')->assertOk()
            ->assertSee('Review Queue')
            ->assertSee(route('account-reviews.index'), false)
            ->assertSee(route('pdl-links.index'), false)
            // Visit requests need no review: a booking is confirmed at once.
            ->assertDontSee('Step 2')
            ->assertDontSee('>Visit Requests<', false)
            ->assertSee('title="1 waiting for review"', false);
        $this->get('/eligibility')->assertNotFound();
    }

    public function test_review_queue_opens_the_step_that_has_work(): void
    {
        // Nothing waiting → accounts step.
        $this->get('/review-queue')->assertRedirect(route('account-reviews.index'));

        // Accounts waiting → accounts step first.
        $this->visitor->update(['verification_status' => 'pending']);
        $this->get('/review-queue')->assertRedirect(route('account-reviews.index'));
    }

    public function test_only_record_officers_can_open_the_queue(): void
    {
        $this->actingAs($this->frontDeskAccount, 'web')->get('/review-queue')->assertForbidden();
    }
}
