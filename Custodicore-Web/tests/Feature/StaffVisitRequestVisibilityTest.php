<?php

namespace Tests\Feature;

use App\Models\FacilityVisitationRule;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Record Officer visibility of visitor-submitted requests awaiting review
 * (`assigned`): the sidebar count on Visitor Management, the dashboard card,
 * and the "visit date passed" label in the pending table.
 *
 * "Now" is pinned to Wednesday 2026-10-07 10:00 Asia/Manila; requests are
 * for Fri 2026-10-09.
 */
class StaffVisitRequestVisibilityTest extends TestCase
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

        FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'fri',
            'time_slot_start' => '13:00:00',
            'time_slot_end' => '16:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);
    }

    private function submittedRequest(array $startTimes = ['09:00']): VisitRequest
    {
        return app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor,
            $this->relationship,
            CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'),
            $startTimes
        );
    }

    /** A row in an arbitrary status, bypassing the service. */
    /** The dashboard's "Visit Requests Awaiting Review" card markup. */
    private function pendingCard(\Illuminate\Testing\TestResponse $response): string
    {
        $html = $response->getContent();
        $start = strpos($html, '<a class="stat-card stat-link accent-teal"');
        $this->assertNotFalse($start, 'Pending visit request card is rendered.');

        return substr($html, $start, strpos($html, '</a>', $start) - $start);
    }

    private function visitWithStatus(string $status): VisitRequest
    {
        return VisitRequest::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
            'status' => $status,
        ]);
    }

    // ------------------------------------------------------------ Sidebar

    public function test_sidebar_counts_only_requests_awaiting_review(): void
    {
        $this->submittedRequest();
        $this->visitWithStatus('assigned');
        foreach (['pending_confirmation', 'confirmed', 'cancelled', 'declined', 'completed', 'no_show'] as $status) {
            $this->visitWithStatus($status);
        }

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<a href="'.route('visitor.index').'#visit-requests"', false)
            ->assertSee('<span class="nav-count" title="2 visit requests awaiting review">2</span>', false);
    }

    public function test_sidebar_count_appears_on_every_record_officer_page(): void
    {
        $this->submittedRequest();
        $this->actingAs($this->recordOfficerAccount);

        foreach (['visitor.index', 'pdl.index', 'eligibility.index', 'account-reviews.index'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('title="1 visit request awaiting review"', false);
        }
    }

    public function test_sidebar_has_no_count_when_nothing_awaits_review(): void
    {
        $this->visitWithStatus('confirmed');

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<a href="'.route('visitor.index').'#visit-requests"', false)
            ->assertDontSee('awaiting review"', false);
    }

    public function test_past_dated_requests_still_count_until_resolved(): void
    {
        $visit = $this->submittedRequest();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 00:01:00', 'Asia/Manila'));

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('dashboard'))
            ->assertSee('title="1 visit request awaiting review"', false);

        app(VisitAssignmentService::class)->expirePastDueAssignedRequest($visit->visit_request_id);

        $this->get(route('dashboard'))->assertDontSee('awaiting review"', false);
    }

    // ---------------------------------------------------------- Dashboard

    public function test_dashboard_card_shows_the_pending_count_and_links_to_the_section(): void
    {
        $this->submittedRequest();
        $this->visitWithStatus('pending_confirmation');

        $response = $this->actingAs($this->recordOfficerAccount)->get(route('dashboard'))->assertOk();

        $response->assertSee('Visit Requests Awaiting Review')
            // Links to Review Queue → Visit Requests, which lists every request awaiting a decision.
            ->assertSee('<a class="stat-card stat-link accent-teal" href="'.route('eligibility.index').'"', false);
        $this->assertSame(1, $response->viewData('stats')['pending_visit_requests']);
        $this->assertStringContainsString('ATTENTION', $this->pendingCard($response));
    }

    public function test_dashboard_card_shows_zero_without_attention_badge(): void
    {
        $response = $this->actingAs($this->recordOfficerAccount)->get(route('dashboard'))->assertOk();

        $response->assertSee('Visit Requests Awaiting Review');
        $this->assertSame(0, $response->viewData('stats')['pending_visit_requests']);
        $this->assertStringNotContainsString('ATTENTION', $this->pendingCard($response));
    }

    public function test_dashboard_and_sidebar_show_no_visitor_details(): void
    {
        $this->submittedRequest();

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee($this->visitor->full_name)
            ->assertDontSee($this->visitor->contact_number);
    }

    public function test_other_roles_cannot_open_the_record_officer_dashboard(): void
    {
        foreach ([$this->frontDeskAccount, $this->visitorAccount] as $account) {
            $this->actingAs($account)->get(route('dashboard'))->assertForbidden();
        }
    }

    // ------------------------------------------------- Past-dated label

    public function test_past_dated_request_is_labelled_and_cannot_be_approved_from_the_table(): void
    {
        $visit = $this->submittedRequest();
        $approveUrl = route('visitor.visit-requests.approve', [$visit->visitor_id, $visit->visit_request_id]);
        $rejectUrl = route('visitor.visit-requests.reject', [$visit->visitor_id, $visit->visit_request_id]);
        $this->travelTo(CarbonImmutable::parse('2026-10-10 00:01:00', 'Asia/Manila'));
        $this->actingAs($this->recordOfficerAccount);

        foreach ([route('visitor.index'), route('visitor.show', $this->visitor->visitor_id)] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee('VISIT DATE PASSED')
                ->assertSee('This request can no longer be approved. It still holds 1 reserved seat:')
                ->assertSee('reject it to release it now, or it will expire automatically at the next scheduled expiry run.')
                ->assertDontSee($approveUrl, false)
                ->assertSee($rejectUrl, false);
        }
    }

    public function test_past_dated_whole_day_request_mentions_both_seats(): void
    {
        $this->submittedRequest(['09:00', '13:00']);
        $this->travelTo(CarbonImmutable::parse('2026-10-10 00:01:00', 'Asia/Manila'));

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.index'))
            ->assertOk()
            ->assertSee('It still holds 2 reserved seats:')
            ->assertSee('reject it to release them now');
    }

    public function test_upcoming_and_same_day_requests_are_not_labelled_past_dated(): void
    {
        $this->completeRequirements();
        $visit = $this->submittedRequest();
        $approveUrl = route('visitor.visit-requests.approve', [$visit->visitor_id, $visit->visit_request_id]);
        $this->actingAs($this->recordOfficerAccount);

        $this->get(route('visitor.index'))->assertOk()->assertDontSee('VISIT DATE PASSED')->assertSee($approveUrl, false);

        // On the visit day itself the request is not past-dated yet.
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 18:00:00', 'Asia/Manila'));
        $this->get(route('visitor.index'))->assertOk()->assertDontSee('VISIT DATE PASSED')->assertSee($approveUrl, false);
    }

    public function test_rejecting_a_past_dated_request_releases_its_seat(): void
    {
        $visit = $this->submittedRequest();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 00:01:00', 'Asia/Manila'));

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visit-requests.reject', [$visit->visitor_id, $visit->visit_request_id]), [
                'cancellation_reason' => 'The visit date has passed.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $visit->fresh()->status);
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
    }
}
