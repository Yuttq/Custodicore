<?php

namespace Tests\Feature;

use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Upcoming Visits: where the Record Officer sees who is coming, including
 * requests after they were approved.
 */
class UpcomingVisitsTest extends TestCase
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

    private function submit(): VisitRequest
    {
        return app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor, $this->relationship, CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'), '09:00'
        );
    }

    public function test_a_request_shows_while_waiting_and_after_it_is_approved(): void
    {
        $visit = $this->submit();
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->get('/upcoming-visits')->assertOk()
            ->assertSee('Friday, Oct 9, 2026')
            ->assertSee($this->visitor->full_name)
            ->assertSee($this->pdl->full_name)
            ->assertSee('Morning')
            ->assertSee('Waiting for review');

        $this->post("/review-queue/visits/{$visit->visit_request_id}/approve")
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Upcoming Visits'));

        $this->get('/upcoming-visits?status=confirmed')->assertOk()
            ->assertSee($this->visitor->full_name)
            ->assertSee('Confirmed')
            ->assertSee('Oct 7, 2026 10:00'); // approved at

        // Friday is not today.
        $this->get('/upcoming-visits?range=today')->assertOk()->assertDontSee($this->visitor->full_name);
    }

    public function test_cancelled_and_past_visits_are_not_listed(): void
    {
        $visit = $this->submit();
        app(VisitAssignmentService::class)->rejectVisitorRequest($visit, 'Visiting hours are full that day.');

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->get('/upcoming-visits?range=month')->assertOk()
            ->assertDontSee($this->visitor->full_name)
            ->assertSee('No visits in the next 30 days');
    }

    public function test_sidebar_link_and_access(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web')
            ->get('/dashboard')->assertSee(route('upcoming-visits.index'), false);

        $this->actingAs($this->frontDeskAccount, 'web')->get('/upcoming-visits')->assertForbidden();
    }
}
