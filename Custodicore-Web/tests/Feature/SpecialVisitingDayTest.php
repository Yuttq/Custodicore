<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FacilityVisitationRule;
use App\Models\VisitSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Special visiting day (Manage Visitor Slots, Record Officer): opening a
 * date outside a classification's regular days. "Now" is Wednesday
 * 2026-10-07 10:00 Asia/Manila; the fixture PDL is non_drug_related with a
 * regular Friday 09:00 rule.
 */
class SpecialVisitingDayTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const MONDAY = '2026-10-12';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();
    }

    private function openSpecial(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->recordOfficerAccount)->post(route('visitor-slots.special'), array_merge([
            'special_date' => self::MONDAY,
            'special_classification' => 'non_drug_related',
            'special_capacity' => 10,
            'special_reason' => 'Capstone defense demonstration',
        ], $overrides));
    }

    public function test_record_officer_opens_a_special_day_that_visitors_can_book_whole_day(): void
    {
        $this->openSpecial()->assertSessionHasNoErrors()->assertSessionHas('success');

        $sessions = VisitSchedule::with('rule')->whereDate('schedule_date', self::MONDAY)->orderBy('time_slot_start')->get();
        $this->assertCount(2, $sessions);
        $this->assertSame(['09:00', '13:00'], $sessions->map(fn ($s) => substr($s->time_slot_start, 0, 5))->all());
        foreach ($sessions as $session) {
            $this->assertSame(10, (int) $session->max_capacity);
            $this->assertSame('open', $session->status);
            $this->assertTrue($session->rule->isSpecial());
            $this->assertSame(self::MONDAY, $session->rule->effective_to->toDateString());
        }
        $this->assertTrue(AuditLog::where('record_type', 'visit_schedules')
            ->where('description', 'like', '%Capstone defense demonstration%')->exists());

        // The visitor's calendar offers Monday as a whole day...
        Sanctum::actingAs($this->visitorAccount);
        $result = $this->getJson('/api/schedules/availability?from='.self::MONDAY.'&to='.self::MONDAY)->assertOk();
        $day = $result->json('relationships.0.days.0');
        $this->assertTrue($day['available']);
        $this->assertTrue($day['wholeDay']['available']);
        // ...but Monday is not listed as a regular visiting day.
        $this->assertSame(['fri'], $result->json('relationships.0.visitingDays'));

        $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::MONDAY,
            'startTimes' => ['09:00', '13:00'],
        ])->assertCreated();
        $this->assertSame([1, 1], $sessions->map(fn ($s) => app(\App\Services\VisitAssignmentService::class)->occupiedSlots($s->fresh()))->all());
    }

    public function test_it_is_refused_on_a_regular_day_twice_in_the_past_or_without_a_reason(): void
    {
        $this->openSpecial(['special_date' => '2026-10-09']) // a regular Friday
            ->assertSessionHasErrors(['special_date' => 'Friday is already a regular visiting day for Non-drug-related PDLs. Use Create Session instead.']);
        $this->openSpecial(['special_date' => '2026-10-05'])->assertSessionHasErrors('special_date');
        $this->openSpecial(['special_reason' => ''])->assertSessionHasErrors('special_reason');
        $this->openSpecial(['special_classification' => 'dorm:1'])->assertSessionHasErrors('special_classification');
        $this->openSpecial(['special_capacity' => 0])->assertSessionHasErrors('special_capacity');
        $this->assertSame(1, VisitSchedule::count());

        $this->openSpecial()->assertSessionHasNoErrors();
        $this->openSpecial()->assertSessionHasErrors(['special_date' => 'Oct 12, 2026 is already a special visiting day for Non-drug-related PDLs.']);
        $this->assertSame(3, VisitSchedule::count());
    }

    public function test_only_the_record_officer_can_open_one(): void
    {
        foreach ([$this->frontDeskAccount, $this->visitorAccount] as $account) {
            $this->actingAs($account)->post(route('visitor-slots.special'), [
                'special_date' => self::MONDAY, 'special_classification' => 'non_drug_related',
                'special_capacity' => 10, 'special_reason' => 'Not allowed',
            ])->assertForbidden();
        }
        $this->assertSame(1, VisitSchedule::count());
    }

    public function test_it_stays_out_of_the_weekly_schedule_and_regular_session_creation(): void
    {
        $this->openSpecial()->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('Mon', FacilityVisitationRule::classificationSummary('non_drug_related'));

        // The regular Create Session form still refuses a Monday.
        $this->actingAs($this->recordOfficerAccount)->post(route('visitor-slots.store'), [
            'schedule_date' => self::MONDAY, 'pdl_classification' => 'non_drug_related',
            'session' => 'morning', 'max_capacity' => 5, 'is_open' => '1',
        ])->assertSessionHasErrors('schedule_date');

        // The page lists it with a Special day badge.
        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor-slots.index', ['from' => self::MONDAY, 'to' => self::MONDAY]))
            ->assertOk()->assertSee('Special day')->assertSee('Special Visiting Day');
    }

    public function test_closing_its_session_stops_new_bookings(): void
    {
        $this->openSpecial()->assertSessionHasNoErrors();
        $morning = VisitSchedule::whereDate('schedule_date', self::MONDAY)->orderBy('time_slot_start')->first();

        $this->actingAs($this->recordOfficerAccount)
            ->patch(route('visitor-slots.status', $morning->schedule_id), ['is_open' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertSame('closed', $morning->fresh()->status);

        Sanctum::actingAs($this->visitorAccount);
        $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::MONDAY,
            'startTime' => '09:00',
        ])->assertUnprocessable();
    }
}
