<?php

namespace Tests\Feature;

use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Staff list pages refresh themselves (partials/live-refresh): the browser
 * re-fetches the page every 30 seconds and reloads only when <main> changed,
 * ignoring scripts, CSRF tokens and [data-live-ignore] parts. These tests
 * check the server side of that contract: every page includes the script,
 * the same data renders the same <main> later on (so nothing reloads on its
 * own, e.g. "5 minutes ago"), and a real change is seen.
 */
class LiveRefreshPagesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();
    }

    /** @return array<string, array{0:string,1:string}> page => [account, url] */
    private function pages(): array
    {
        return [
            'accounts' => ['records', route('account-reviews.index')],
            'visit requests' => ['records', route('eligibility.index')],
            'pdl links' => ['records', route('pdl-links.index')],
            'upcoming visits' => ['records', route('upcoming-visits.index')],
            'walk-ins' => ['frontdesk', route('frontdesk.walk-ins.index')],
            'visit schedule' => ['frontdesk', route('frontdesk.schedule', 'expected')],
        ];
    }

    private function fetchPage(string $who, string $url): string
    {
        $account = $who === 'records' ? $this->recordOfficerAccount : $this->frontDeskAccount;

        return $this->actingAs($account)->get($url)->assertOk()->getContent();
    }

    /** <main> as the script compares it. */
    private function comparable(string $html): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $main = $xpath->query('//main')->item(0);
        $this->assertNotNull($main, 'The page has a <main>.');
        foreach (iterator_to_array($xpath->query('.//*[@data-live-ignore] | .//script | .//input[@name="_token"] | .//*[@id="flashModal"]', $main)) as $node) {
            $node->parentNode->removeChild($node);
        }

        return trim(preg_replace('/\s+/', ' ', $dom->saveHTML($main)));
    }

    /** A pending visitor (Accounts) and a visit request awaiting review (Visit Requests, Upcoming). */
    private function seedWaitingWork(): void
    {
        app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor, $this->relationship, CarbonImmutable::parse('2026-10-09', 'Asia/Manila'), '09:00'
        );
        $this->visitor->update(['verification_status' => 'pending']);
    }

    public function test_every_list_page_includes_the_live_refresh_script(): void
    {
        foreach ($this->pages() as $name => [$who, $url]) {
            $this->assertStringContainsString('window.ccLiveRefresh', $this->fetchPage($who, $url), $name);
        }
    }

    public function test_unchanged_data_renders_the_same_main_later_on(): void
    {
        $this->seedWaitingWork();

        foreach ($this->pages() as $name => [$who, $url]) {
            $first = $this->comparable($this->fetchPage($who, $url));
            $this->travel(10)->minutes();
            $later = $this->comparable($this->fetchPage($who, $url));
            $this->travelBack();
            $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));

            $this->assertSame($first, $later, "{$name}: nothing changed, so the page must not reload by itself.");
        }
    }

    public function test_a_new_visit_request_changes_the_visit_requests_page(): void
    {
        $url = route('eligibility.index');
        $before = $this->comparable($this->fetchPage('records', $url));

        $this->seedWaitingWork();

        $this->assertNotSame($before, $this->comparable($this->fetchPage('records', $url)));
        $this->assertSame(1, VisitRequest::count());
    }
}
