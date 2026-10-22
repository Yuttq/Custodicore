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
 * Front Desk Attendance Log: a read-only list of one day's gate entries,
 * filterable by visitor or PDL, open to the Front Desk Officer only.
 */
class FrontDeskAttendanceLogTest extends TestCase
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

    public function test_shows_todays_entries_by_default(): void
    {
        $this->gateEntry('2026-10-19 09:05', '2026-10-19 10:40');
        $this->gateEntry('2026-10-19 13:20');
        $this->gateEntry('2026-10-18 09:00', '2026-10-18 10:00');

        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.attendance-log'))
            ->assertOk()
            ->assertSee('Maria D. Santos')
            ->assertSee('>09:05</td>', false)
            ->assertSee('>13:20</td>', false)
            ->assertSee('Returned 10:40')
            ->assertSee('Still held')
            ->assertSee('Noel D. Fernandez')
            ->assertSee('Showing 1–2 of 2 entries')
            ->assertDontSee('>09:00</td>', false);
    }

    public function test_an_earlier_day_can_be_selected(): void
    {
        $this->gateEntry('2026-10-18 09:00', '2026-10-18 10:00');
        $this->gateEntry('2026-10-19 13:20');

        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.attendance-log', ['date' => '2026-10-18']))
            ->assertOk()
            ->assertSee('name="date" value="2026-10-18"', false)
            ->assertSee('>09:00</td>', false)
            ->assertDontSee('>13:20</td>', false);
    }

    public function test_past_day_without_check_out_is_flagged(): void
    {
        $this->gateEntry('2026-10-18 09:00');

        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.attendance-log', ['date' => '2026-10-18']))
            ->assertOk()
            ->assertSee('Never checked out');
    }

    public function test_invalid_or_future_dates_fall_back_to_today(): void
    {
        $this->gateEntry('2026-10-19 09:05');

        foreach (['2026-10-25', 'not-a-date', '2026-02-31'] as $date) {
            $this->actingAs($this->frontDeskAccount)
                ->get(route('frontdesk.attendance-log', ['date' => $date]))
                ->assertOk()
                ->assertSee('>09:05</td>', false);
        }
    }

    public function test_search_filters_by_visitor_or_pdl_but_stays_on_the_day(): void
    {
        $this->gateEntry('2026-10-19 09:05');
        $this->gateEntry('2026-10-18 09:00');

        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.attendance-log', ['q' => 'PDL-TEST-001']))
            ->assertOk()
            ->assertSee('>09:05</td>', false)
            ->assertDontSee('>09:00</td>', false);

        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.attendance-log', ['q' => 'Nobody Here']))
            ->assertOk()
            ->assertSee('No gate entries matched')
            ->assertDontSee('>09:05</td>', false);
    }

    public function test_shows_and_searches_the_handling_officer(): void
    {
        $this->gateEntry('2026-10-19 09:05', '2026-10-19 10:40');
        $this->gateEntry('2026-10-19 13:20');

        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.attendance-log'))
            ->assertOk()
            ->assertSee('HANDLED BY')
            ->assertSeeInOrder(['In:', 'Noel D. Fernandez', 'Out:', 'Noel D. Fernandez'])
            ->assertSee('Not yet');

        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.attendance-log', ['q' => 'Fernandez']))
            ->assertOk()
            ->assertSee('Showing 1–2 of 2 entries');

        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.attendance-log', ['q' => 'Salcedo']))
            ->assertOk()
            ->assertSee('No gate entries matched');
    }

    public function test_filters_by_method(): void
    {
        $this->gateEntry('2026-10-19 09:05', '2026-10-19 10:40');
        $this->actingAs($this->frontDeskAccount);

        $this->get(route('frontdesk.attendance-log', ['method' => 'qr_scan']))
            ->assertOk()
            ->assertSee('>09:05</td>', false)
            ->assertSee('Clear');

        $this->get(route('frontdesk.attendance-log', ['method' => 'manual_override']))
            ->assertSee('No Manual entries for today.')
            ->assertDontSee('>09:05</td>', false);

        // Only Search, Method and one Date.
        $this->get(route('frontdesk.attendance-log'))
            ->assertSee('name="q"', false)
            ->assertSee('name="method"', false)
            ->assertSee('name="date"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="who"', false)
            ->assertDontSee('name="from"', false);
    }

    public function test_numbered_pages(): void
    {
        foreach (range(1, 27) as $i) {
            $this->gateEntry('2026-10-19 '.sprintf('%02d', 6 + intdiv($i, 4)).':'.sprintf('%02d', ($i % 4) * 10));
        }
        $this->actingAs($this->frontDeskAccount);

        $this->get(route('frontdesk.attendance-log'))
            ->assertOk()
            ->assertSee('Showing 1–25 of 27 entries')
            ->assertSee('aria-current="page">1</span>', false)
            ->assertSee(e(route('frontdesk.attendance-log', ['page' => 2])), false);

        $this->get(route('frontdesk.attendance-log', ['page' => 2]))
            ->assertSee('Showing 26–27 of 27 entries')
            ->assertSee('aria-current="page">2</span>', false);
    }

    public function test_page_is_read_only(): void
    {
        $this->gateEntry('2026-10-19 09:05');

        $response = $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.attendance-log'))
            ->assertOk();

        // The only form that posts is the sidebar's Sign out; nothing edits or deletes.
        preg_match_all('/<form[^>]*method="post"[^>]*action="([^"]*)"/i', $response->getContent(), $posts);
        $this->assertSame([route('logout')], $posts[1]);
        $this->post('/front-desk/attendance-log')->assertStatus(405);
    }

    public function test_menu_links_to_the_page(): void
    {
        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.dashboard'))
            ->assertOk()
            ->assertSee(route('frontdesk.attendance-log'))
            ->assertSee('Attendance Log');
    }

    public function test_only_front_desk_officers_can_open_it(): void
    {
        $this->actingAs($this->recordOfficerAccount)
            ->get(route('frontdesk.attendance-log'))
            ->assertForbidden();

        $this->actingAs($this->visitorAccount)
            ->get(route('frontdesk.attendance-log'))
            ->assertForbidden();

        auth()->logout();
        $this->get(route('frontdesk.attendance-log'))->assertRedirect(route('login'));
    }
}
