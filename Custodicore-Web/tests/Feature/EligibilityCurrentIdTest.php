<?php

namespace Tests\Feature;

use App\Models\EligibilityAssessment;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * The identity check looks at the visitor's CURRENT government ID only. An
 * old ID rejected before a replacement was approved is history and must not
 * block visits (it used to: "ID rejected" on an approved visitor).
 */
class EligibilityCurrentIdTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
    }

    private function id(string $status, string $uploadedAt): VisitorId
    {
        return VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'N-' . $uploadedAt,
            'file_path' => 'visitor-ids/id.png', 'verification_status' => $status, 'uploaded_at' => $uploadedAt,
        ]);
    }

    private function visitRequest(): VisitRequest
    {
        return VisitRequest::create([
            'visitor_id' => $this->visitor->visitor_id, 'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id, 'schedule_id' => $this->schedule->schedule_id, 'status' => 'assigned',
        ]);
    }

    public function test_an_old_rejected_id_replaced_by_an_approved_one_passes(): void
    {
        $this->id('rejected', '2026-11-20 10:00:00');
        $this->id('verified', '2026-11-22 14:08:00');

        $assessment = EligibilityAssessment::assessFor($this->visitRequest());

        $this->assertSame('pass', $assessment->identity_check_result);
        $this->assertNotSame('rejected', $assessment->overall_result);
    }

    public function test_a_rejected_current_id_still_blocks(): void
    {
        $this->id('verified', '2026-11-20 10:00:00');
        $this->id('rejected', '2026-11-22 14:08:00'); // replacement requested on the newest ID

        $assessment = EligibilityAssessment::assessFor($this->visitRequest());

        $this->assertSame('rejected', $assessment->identity_check_result);
        $this->assertSame('rejected', $assessment->overall_result);
    }

    public function test_a_set_aside_duplicate_is_ignored(): void
    {
        $this->id('verified', '2026-11-20 10:00:00');
        $this->id(VisitorId::STATUS_SUPERSEDED, '2026-11-22 14:08:00');

        $this->assertSame('pass', EligibilityAssessment::assessFor($this->visitRequest())->identity_check_result);
    }

    public function test_a_replacement_waiting_for_review_needs_review(): void
    {
        $this->id('rejected', '2026-11-20 10:00:00');
        $this->id('pending', '2026-11-22 14:08:00');

        $this->assertSame('flagged', EligibilityAssessment::assessFor($this->visitRequest())->identity_check_result);
    }

    public function test_accepting_a_replacement_rechecks_waiting_requests(): void
    {
        $old = $this->id('rejected', '2026-11-20 10:00:00');
        $old->update(['rejection_reason' => 'Expired.']);
        $visit = $this->visitRequest();
        $this->id('pending', now()->toDateTimeString());
        EligibilityAssessment::assessFor($visit);
        $this->assertSame('flagged', $visit->eligibilityAssessment()->first()->identity_check_result);

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->post(route('visitor.replacements.accept', $this->visitor->visitor_id))->assertSessionHas('success');

        $this->assertSame('pass', $visit->eligibilityAssessment()->first()->identity_check_result);
    }
}
