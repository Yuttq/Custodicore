<?php

namespace Tests\Feature;

use App\Models\PdlRestriction;
use App\Models\VisitorFlag;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Adding or lifting a PDL restriction, or adding or resolving a visitor
 * flag, re-runs the eligibility check on that PDL's / visitor's requests
 * still awaiting review, so the Review Queue shows the current result.
 */
class EligibilityRecheckTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->completeRequirements();
        $this->withoutVite();
    }

    private function waitingRequest(): VisitRequest
    {
        $visit = app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor, $this->relationship, CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'), '09:00'
        );
        $this->assertSame('eligible', $visit->eligibilityAssessment->overall_result);

        return $visit;
    }

    private function checkResult(VisitRequest $visit): string
    {
        return $visit->fresh('eligibilityAssessment')->eligibilityAssessment->overall_result;
    }

    public function test_restriction_added_after_booking_rejects_the_waiting_request_and_lifting_it_clears(): void
    {
        $visit = $this->waitingRequest();
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->post(route('pdl.restrictions.store', $this->pdl->pdl_id), [
            'restriction_type' => 'quarantine',
            'start_date' => now()->toDateString(),
            'reason' => 'Medical quarantine per infirmary order',
        ])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $this->checkResult($visit));
        $this->get('/eligibility')->assertSee('PDL has an active restriction in place.');

        $restriction = PdlRestriction::where('pdl_id', $this->pdl->pdl_id)->firstOrFail();
        $this->patch(route('pdl.restrictions.lift', [$this->pdl->pdl_id, $restriction->restriction_id]))->assertSessionHasNoErrors();

        $this->assertSame('eligible', $this->checkResult($visit));
    }

    public function test_flag_added_after_booking_flags_the_waiting_request_and_resolving_it_clears(): void
    {
        $visit = $this->waitingRequest();
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->post(route('visitor.flags.store', $this->visitor->visitor_id), [
            'flag_type' => 'rule_violation',
            'description' => 'Tried to bring in a prohibited item.',
            'duration' => '7',
        ])->assertSessionHasNoErrors();

        $this->assertSame('flagged_for_review', $this->checkResult($visit));

        $flag = VisitorFlag::where('visitor_id', $this->visitor->visitor_id)->firstOrFail();
        $this->post(route('visitor.flags.resolve', [$this->visitor->visitor_id, $flag->flag_id]))->assertSessionHasNoErrors();

        $this->assertSame('eligible', $this->checkResult($visit));
    }

    public function test_decided_requests_are_not_rechecked(): void
    {
        $visit = $this->waitingRequest();
        app(VisitAssignmentService::class)->approveVisitorRequest($visit);
        $checkedAt = $visit->fresh('eligibilityAssessment')->eligibilityAssessment->updated_at;

        $this->travel(1)->hours();
        PdlRestriction::create([
            'pdl_id' => $this->pdl->pdl_id, 'restriction_type' => 'quarantine', 'status' => 'active',
            'start_date' => now()->toDateString(), 'reason' => 'Medical quarantine',
            'imposed_by' => $this->recordOfficerAccount->staffProfile->staff_id,
        ]);

        $this->assertSame('eligible', $this->checkResult($visit));
        $this->assertEquals($checkedAt, $visit->fresh('eligibilityAssessment')->eligibilityAssessment->updated_at);
    }
}
