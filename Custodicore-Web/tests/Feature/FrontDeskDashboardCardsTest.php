<?php

namespace Tests\Feature;

use App\Models\QrCode;
use App\Models\VisitCheckin;
use App\Models\VisitRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Front Desk dashboard cards: each links to the visitors behind it (a tab on
 * the Visit Schedule page), and the counts are served as JSON so pages update live.
 */
class FrontDeskDashboardCardsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-19 15:00'));
    }

    private function gateEntry(string $checkIn, ?string $checkOut = null): VisitCheckin
    {
        $visit = VisitRequest::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
            'status' => $checkOut ? 'completed' : 'confirmed',
        ]);

        $qr = QrCode::create([
            'visit_request_id' => $visit->visit_request_id,
            'qr_token' => Str::random(64),
            'generated_at' => $checkIn,
            'status' => 'used',
        ]);

        return VisitCheckin::create([
            'visit_request_id' => $visit->visit_request_id,
            'qr_code_id' => $qr->qr_code_id,
            'id_surrendered_type' => 'national_id',
            'id_surrender_time' => $checkIn,
            'check_in_time' => $checkIn,
            'check_in_officer_id' => $this->frontDeskAccount->staffProfile->staff_id,
            'check_out_time' => $checkOut,
            'check_out_officer_id' => $checkOut ? $this->frontDeskAccount->staffProfile->staff_id : null,
            'id_returned_time' => $checkOut,
            'verification_method' => 'qr_scan',
            'status' => $checkOut ? 'checked_out' : 'checked_in',
        ]);
    }

    public function test_cards_link_to_their_lists(): void
    {
        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.dashboard'))
            ->assertOk()
            ->assertSee('Expected Visitors')
            ->assertDontSee('Expected Today')
            ->assertSee(route('frontdesk.schedule', 'expected'), false)
            ->assertSee(route('frontdesk.schedule', 'inside'), false)
            ->assertSee(route('frontdesk.schedule', 'checked-out'), false);
    }

    public function test_stats_follow_check_in_and_check_out(): void
    {
        $this->actingAs($this->frontDeskAccount);

        $this->getJson(route('frontdesk.dashboard.stats'))->assertExactJson(['expected' => 0, 'inside' => 0, 'checked-out' => 0]);

        $entry = $this->gateEntry('2026-10-19 13:20');
        $this->getJson(route('frontdesk.dashboard.stats'))->assertJsonPath('inside', 1)->assertJsonPath('checked-out', 0);

        $entry->update(['check_out_time' => '2026-10-19 14:30', 'status' => 'checked_out']);
        $entry->visitRequest->update(['status' => 'completed']);
        $this->getJson(route('frontdesk.dashboard.stats'))->assertJsonPath('inside', 0)->assertJsonPath('checked-out', 1);
    }

    public function test_lists_show_the_visitors_behind_each_card(): void
    {
        $this->gateEntry('2026-10-19 13:20');
        $this->gateEntry('2026-10-19 09:05', '2026-10-19 10:40');
        $this->actingAs($this->frontDeskAccount);

        $this->get(route('frontdesk.schedule', 'inside'))
            ->assertOk()
            ->assertSee('Maria D. Santos')
            ->assertSee('>13:20</td>', false)
            ->assertSee('Inside');

        $this->get(route('frontdesk.schedule', 'checked-out'))
            ->assertOk()
            ->assertSee('>09:05</td>', false)
            ->assertSee('>10:40</td>', false)
            ->assertDontSee('>13:20</td>', false);

        // Still an approved upcoming visit while the visitor is inside.
        $this->get(route('frontdesk.schedule', 'expected'))->assertOk()->assertSee('>13:20</td>', false)->assertDontSee('>09:05</td>', false);
    }

    public function test_schedule_page_has_visitor_tabs_and_upcoming_sessions(): void
    {
        $this->gateEntry('2026-10-19 13:20');
        $this->actingAs($this->frontDeskAccount);

        $this->get(route('frontdesk.attendance-log'))
            ->assertOk()
            ->assertSee(route('frontdesk.schedule'), false)
            ->assertSee('Visit Schedule')
            ->assertDontSee("Today&#039;s Schedule", false);

        $this->get(route('frontdesk.schedule'))
            ->assertOk()
            ->assertDontSee('Every approved visit that is coming up')
            ->assertSeeInOrder(['data-stat="inside"', 'cc-chip-success">1</span>'], false)
            ->assertSee(route('frontdesk.schedule', 'checked-out'), false)
            ->assertSee(route('frontdesk.schedule', 'sessions'), false);

        $this->get(route('frontdesk.schedule', 'sessions'))
            ->assertOk()
            ->assertSee('Time Slot');
    }

    public function test_expected_visitors_counts_every_approved_upcoming_visit(): void
    {
        $sessions = [];
        $session = function (string $date) use (&$sessions) {
            return $sessions[$date] ??= \App\Models\VisitSchedule::create([
                'rule_id' => $this->rule->rule_id,
                'schedule_date' => $date,
                'time_slot_start' => '09:00:00',
                'time_slot_end' => '11:30:00',
                'max_capacity' => 10,
                'slots_taken' => 0,
                'status' => 'open',
            ]);
        };
        $book = fn ($schedule, string $status) => VisitRequest::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $schedule->schedule_id,
            'status' => $status,
        ]);

        $book($session('2026-10-19'), 'confirmed'); // today
        $book($session('2026-10-30'), 'confirmed'); // a later date
        $book($session('2026-10-19'), 'completed'); // already done
        $book($session('2026-10-12'), 'confirmed'); // a past date
        $book($session('2026-10-30'), 'assigned');  // not approved yet
        $this->actingAs($this->frontDeskAccount);

        $this->getJson(route('frontdesk.dashboard.stats'))->assertJsonPath('expected', 2);
        $this->get(route('frontdesk.dashboard'))->assertSee('All approved visits coming up');
        $this->get(route('frontdesk.schedule', 'expected'))
            ->assertOk()
            ->assertSeeInOrder(['Today', 'Fri, Oct 30']);
    }

    public function test_schedule_can_show_a_past_day(): void
    {
        $this->gateEntry('2026-10-18 09:00', '2026-10-18 10:00'); // yesterday, checked out
        $this->gateEntry('2026-10-18 13:00');                     // yesterday, never checked out
        $this->gateEntry('2026-10-19 09:05', '2026-10-19 10:40'); // today
        $this->actingAs($this->frontDeskAccount);

        $this->get(route('frontdesk.schedule', ['checked-out', 'date' => '2026-10-18']))
            ->assertOk()
            ->assertSee('value="2026-10-18"', false)
            ->assertSee('Clear')
            // Previous / next day.
            ->assertSee(e(route('frontdesk.schedule', ['checked-out', 'date' => '2026-10-17'])), false)
            ->assertSee(e(route('frontdesk.schedule', ['checked-out', 'date' => '2026-10-19'])), false)
            ->assertSee('>09:00</td>', false)
            ->assertDontSee('>09:05</td>', false)
            // Tabs keep the day.
            ->assertSee(e(route('frontdesk.schedule', ['inside', 'date' => '2026-10-18'])), false);

        $this->get(route('frontdesk.schedule', ['inside', 'date' => '2026-10-18']))
            ->assertOk()
            ->assertSee('Never Checked Out')
            ->assertSee('>13:00</td>', false)
            ->assertDontSee('>09:00</td>', false);

        // No date: the live view, as before.
        $this->get(route('frontdesk.schedule', 'checked-out'))
            ->assertSee('>09:05</td>', false)
            ->assertDontSee('>09:00</td>', false)
            ->assertDontSee('>Clear</a>', false);

        // A malformed date falls back to the live view.
        $this->get(route('frontdesk.schedule', ['checked-out', 'date' => 'yesterday']))->assertOk()->assertDontSee('>Clear</a>', false);
    }

    public function test_schedule_search_and_pages(): void
    {
        $this->gateEntry('2026-10-19 13:20');
        $this->actingAs($this->frontDeskAccount);

        $this->get(route('frontdesk.schedule', ['inside', 'q' => 'santos']))
            ->assertOk()
            ->assertSee('>13:20</td>', false)
            ->assertSee('Showing 1–1 of 1 visitor');

        $this->get(route('frontdesk.schedule', ['inside', 'q' => 'nobody']))
            ->assertSee('No visitors match')
            ->assertDontSee('>13:20</td>', false);

        // No Live button any more.
        $this->get(route('frontdesk.schedule'))->assertDontSee('>Live<', false)->assertDontSee('animate-ping', false);
    }

    public function test_unknown_list_is_not_found(): void
    {
        $this->actingAs($this->frontDeskAccount)->get('/front-desk/schedule/everyone')->assertNotFound();
    }
}
