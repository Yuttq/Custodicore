<?php

namespace Tests\Feature;

use App\Models\PdlLegalRecord;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Data checks: court dates that can't be real, confirmation deadlines after
 * the visit, and rejection reasons too short to tell the visitor anything.
 */
class RecordOfficerExtraValidationTest extends TestCase
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
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    private function addCase(array $overrides)
    {
        return $this->post(route('pdl.legal-records.store', $this->pdl->pdl_id), array_merge([
            'case_number' => 'CR-2026-0500', 'offense_code' => 'theft', 'case_status' => PdlLegalRecord::PENDING,
        ], $overrides));
    }

    public function test_next_hearing_must_be_within_five_years(): void
    {
        $this->addCase(['next_hearing_at' => '2206-01-15T09:00'])
            ->assertSessionHasErrors(['next_hearing_at' => 'The next hearing must be within the next 5 years. Check the year.']);
        $this->addCase(['next_hearing_at' => '2027-01-15T09:00'])->assertSessionHasNoErrors();
    }

    public function test_date_filed_cannot_be_before_the_pdl_was_born(): void
    {
        $bornOn = $this->pdl->date_of_birth->toDateString();

        $this->addCase(['date_filed' => $this->pdl->date_of_birth->copy()->subDay()->toDateString()])
            ->assertSessionHasErrors(['date_filed' => 'The date filed cannot be before the date of birth of the PDL.']);
        $this->addCase(['date_filed' => $bornOn])->assertSessionHasNoErrors();
    }

    public function test_typed_confirmation_deadline_must_be_before_the_visit(): void
    {
        $assign = fn (string $deadline) => $this->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
            'relationship_id' => $this->relationship->relationship_id,
            'date' => self::FRIDAY, 'start_times' => ['09:00'], 'confirmation_deadline' => $deadline,
        ]);

        $assign('2026-10-09T09:00')->assertSessionHasErrors([
            'confirmation_deadline' => 'The confirmation deadline must be before the visit starts (Oct 9, 2026 09:00).',
        ]);
        $this->assertSame(0, VisitRequest::count());

        $assign('2026-10-08T18:00')->assertSessionHasNoErrors();
        $this->assertSame('2026-10-08 18:00', VisitRequest::sole()->confirmation_deadline->format('Y-m-d H:i'));
    }

    public function test_rejection_reason_needs_at_least_five_characters(): void
    {
        $visit = app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor, $this->relationship, CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'), '09:00'
        );
        $reject = fn (string $reason) => $this->post(
            route('visitor.visit-requests.reject', [$visit->visitor_id, $visit->visit_request_id]), ['cancellation_reason' => $reason]
        );

        $reject('No')->assertSessionHasErrors(['cancellation_reason' => 'The reason must be at least 5 characters, so the visitor understands why.']);
        $this->assertSame('assigned', $visit->fresh()->status);

        $reject('The facility is closed that day.')->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $visit->fresh()->status);
    }
}
