<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FacilityVisitationRule;
use App\Models\Pdl;
use App\Models\PdlRestriction;
use App\Models\StaffProfile;
use App\Models\SystemSetting;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 3: GET /api/schedules/availability and GET /api/announcements.
 *
 * "Now" is pinned to Wednesday 2026-10-07 10:00 Asia/Manila, so:
 * Thu 10-08 / Sat 10-10 are drug-related days and Fri 10-09 / Sun 10-11
 * are non-drug-related days. The base fixture's PDL is non_drug_related and
 * already has a Fri 09:00 rule (capacity 2) plus a visit_schedules row on
 * Fri 10-09.
 */
class ScheduleAvailabilityApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();

        // Complete the seeded policy: the fixture only has non_drug fri 09:00.
        foreach ([
            ['non_drug_related', 'fri', '13:00:00', '16:30:00'],
            ['non_drug_related', 'sun', '09:00:00', '11:30:00'],
            ['non_drug_related', 'sun', '13:00:00', '16:30:00'],
            ['drug_related', 'thu', '09:00:00', '11:30:00'],
            ['drug_related', 'thu', '13:00:00', '16:30:00'],
            ['drug_related', 'sat', '09:00:00', '11:30:00'],
            ['drug_related', 'sat', '13:00:00', '16:30:00'],
        ] as [$classification, $day, $start, $end]) {
            FacilityVisitationRule::create([
                'pdl_classification' => $classification,
                'day_of_week' => $day,
                'time_slot_start' => $start,
                'time_slot_end' => $end,
                'max_capacity' => 30,
                'effective_from' => '2024-01-01',
            ]);
        }
    }

    private function availability(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/schedules/availability?'.http_build_query($query));
    }

    private function day(array $relationship, string $date): array
    {
        return collect($relationship['days'])->firstWhere('date', $date);
    }

    private function slot(array $relationship, string $date, string $start): array
    {
        return collect($this->day($relationship, $date)['slots'])->firstWhere('startTime', $start);
    }

    private function availableDays(array $relationship): array
    {
        return collect($relationship['days'])->where('available', true)->pluck('dayOfWeek')->unique()->sort()->values()->all();
    }

    private function makeDrugRelatedRelationship(string $status = 'verified'): VisitorPdlRelationship
    {
        $pdl = Pdl::create([
            'pdl_number' => 'PDL-TEST-DRUG',
            'full_name' => 'Drug Case PDL',
            'date_of_birth' => '1991-01-01',
            'gender' => 'male',
            'classification' => 'drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);

        return VisitorPdlRelationship::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $pdl->pdl_id,
            'relationship_type' => 'immediate_family',
            'priority_tier' => 'high_priority',
            'verification_status' => $status,
        ]);
    }

    private function makeOtherVisitorRelationship(): VisitorPdlRelationship
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'carlo.ramos',
            'email' => 'carlo.ramos@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $other = VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => 'Carlo Ramos',
            'date_of_birth' => '1990-02-02',
            'contact_number' => '09170000002',
            'verification_status' => 'verified',
        ]);

        return VisitorPdlRelationship::create([
            'visitor_id' => $other->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'approved_relative',
            'priority_tier' => 'requires_verification',
            'verification_status' => 'verified',
        ]);
    }

    // ------------------------------------------------------------ Access

    public function test_approved_visitor_can_retrieve_availability(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $response = $this->availability()
            ->assertOk()
            ->assertJsonPath('timezone', 'Asia/Manila')
            ->assertJsonPath('today', '2026-10-07')
            ->assertJsonPath('from', '2026-10-07')
            ->assertJsonPath('to', '2026-11-06')
            ->assertJsonPath('message', null)
            ->assertJsonCount(1, 'relationships')
            ->assertJsonPath('relationships.0.relationshipId', (string) $this->relationship->relationship_id)
            ->assertJsonPath('relationships.0.classification', 'non_drug_related')
            ->assertJsonPath('relationships.0.visitingDays', ['fri', 'sun']);

        $relationship = $response->json('relationships.0');
        $this->assertCount(31, $relationship['days']);

        $slot = $this->slot($relationship, '2026-10-09', '13:00');
        $this->assertTrue($slot['available']);
        $this->assertNull($slot['reason']);
        $this->assertSame('16:30', $slot['endTime']);
        $this->assertSame('afternoon', $slot['period']);
        $this->assertSame(30, $slot['capacity']);
        $this->assertSame(30, $slot['slotsRemaining']);
        $this->assertSame((string) $this->relationship->relationship_id, $slot['relationshipId']);
    }

    public function test_unapproved_visitor_is_rejected(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);
        Sanctum::actingAs($this->visitorAccount);

        $this->availability()->assertForbidden()->assertJsonPath('code', 'visitor_not_approved');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->availability()->assertUnauthorized();
        $this->getJson('/api/announcements')->assertUnauthorized();
    }

    // ------------------------------------------------- Classification days

    public function test_drug_related_pdl_gets_thursday_and_saturday(): void
    {
        $this->relationship->update(['verification_status' => 'pending']);
        $drug = $this->makeDrugRelatedRelationship();
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability()
            ->assertOk()
            ->assertJsonCount(1, 'relationships')
            ->assertJsonPath('relationships.0.relationshipId', (string) $drug->relationship_id)
            ->assertJsonPath('relationships.0.visitingDays', ['thu', 'sat'])
            ->json('relationships.0');

        $this->assertSame(['sat', 'thu'], $this->availableDays($relationship));
        $this->assertTrue($this->slot($relationship, '2026-10-08', '09:00')['available']);

        $friday = $this->slot($relationship, '2026-10-09', '09:00');
        $this->assertFalse($friday['available']);
        $this->assertSame('not_eligible', $friday['reason']);
    }

    public function test_non_drug_related_pdl_gets_friday_and_sunday(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability()->assertOk()->json('relationships.0');

        $this->assertSame(['fri', 'sun'], $this->availableDays($relationship));
        $this->assertTrue($this->slot($relationship, '2026-10-11', '09:00')['available']);

        $thursday = $this->day($relationship, '2026-10-08');
        $this->assertFalse($thursday['available']);
        $this->assertSame(['not_eligible', 'not_eligible'], array_column($thursday['slots'], 'reason'));
    }

    public function test_visitor_with_both_classifications_gets_each_relationship(): void
    {
        $this->makeDrugRelatedRelationship();
        Sanctum::actingAs($this->visitorAccount);

        $this->availability()
            ->assertOk()
            ->assertJsonCount(2, 'relationships')
            ->assertJsonPath('relationships.0.classification', 'non_drug_related')
            ->assertJsonPath('relationships.1.classification', 'drug_related');
    }

    // ------------------------------------------------------- Relationship

    public function test_visitor_can_filter_by_own_verified_relationship(): void
    {
        $drug = $this->makeDrugRelatedRelationship();
        Sanctum::actingAs($this->visitorAccount);

        $this->availability(['relationshipId' => $drug->relationship_id])
            ->assertOk()
            ->assertJsonCount(1, 'relationships')
            ->assertJsonPath('relationships.0.relationshipId', (string) $drug->relationship_id);
    }

    public function test_visitor_cannot_use_another_visitors_relationship(): void
    {
        $theirs = $this->makeOtherVisitorRelationship();
        Sanctum::actingAs($this->visitorAccount);

        $this->availability(['relationshipId' => $theirs->relationship_id])
            ->assertNotFound()
            ->assertJsonMissing(['relationshipId' => (string) $theirs->relationship_id]);
    }

    public function test_unverified_own_relationship_is_rejected(): void
    {
        $pending = $this->makeDrugRelatedRelationship('pending');
        Sanctum::actingAs($this->visitorAccount);

        $this->availability(['relationshipId' => $pending->relationship_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('relationshipId');
    }

    public function test_visitor_without_verified_relationship_gets_empty_result(): void
    {
        $this->relationship->update(['verification_status' => 'pending']);
        Sanctum::actingAs($this->visitorAccount);

        $response = $this->availability()
            ->assertOk()
            ->assertJsonPath('relationships', []);

        $this->assertStringContainsString('verified PDL relationship', $response->json('message'));
    }

    // ------------------------------------------------- Unavailable reasons

    public function test_past_dates_are_unavailable(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability(['from' => '2026-10-01', 'to' => '2026-10-11'])
            ->assertOk()
            ->json('relationships.0');

        // Friday 2026-10-02 is a valid non-drug day — but in the past.
        $past = $this->day($relationship, '2026-10-02');
        $this->assertFalse($past['available']);
        $this->assertSame(['past', 'past'], array_column($past['slots'], 'reason'));
    }

    public function test_slot_that_already_ended_today_is_unavailable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'Asia/Manila'));
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability()->assertOk()->json('relationships.0');

        $this->assertSame('ended', $this->slot($relationship, '2026-10-09', '09:00')['reason']);
        $this->assertTrue($this->slot($relationship, '2026-10-09', '13:00')['available']);
    }

    public function test_full_slots_are_unavailable(): void
    {
        $this->schedule->update(['slots_taken' => 2]); // capacity 2
        Sanctum::actingAs($this->visitorAccount);

        $slot = $this->slot($this->availability()->assertOk()->json('relationships.0'), '2026-10-09', '09:00');

        $this->assertFalse($slot['available']);
        $this->assertSame('full', $slot['reason']);
        $this->assertSame(2, $slot['capacity']);
        $this->assertSame(0, $slot['slotsRemaining']);
        $this->assertSame((string) $this->schedule->schedule_id, $slot['scheduleId']);
    }

    public function test_existing_schedule_capacity_is_overlaid(): void
    {
        $this->schedule->update(['slots_taken' => 1]);
        Sanctum::actingAs($this->visitorAccount);

        $slot = $this->slot($this->availability()->assertOk()->json('relationships.0'), '2026-10-09', '09:00');

        $this->assertTrue($slot['available']);
        $this->assertSame(1, $slot['slotsRemaining']);
    }

    public function test_closed_slots_are_unavailable(): void
    {
        $this->schedule->update(['status' => 'closed']);
        Sanctum::actingAs($this->visitorAccount);

        $slot = $this->slot($this->availability()->assertOk()->json('relationships.0'), '2026-10-09', '09:00');

        $this->assertFalse($slot['available']);
        $this->assertSame('closed', $slot['reason']);
    }

    public function test_pdl_with_active_restriction_is_unavailable(): void
    {
        PdlRestriction::create([
            'pdl_id' => $this->pdl->pdl_id,
            'restriction_type' => 'quarantine',
            'status' => 'active',
            'start_date' => '2026-10-01',
            'reason' => 'Health protocol',
            'imposed_by' => StaffProfile::first()->staff_id,
        ]);
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability()
            ->assertOk()
            ->assertJsonPath('relationships.0.pdlAvailable', false)
            ->json('relationships.0');

        $this->assertSame([], $this->availableDays($relationship));
        $this->assertSame('pdl_unavailable', $this->slot($relationship, '2026-10-09', '13:00')['reason']);
    }

    public function test_effective_dates_are_respected(): void
    {
        // Sunday rules only start on 2026-10-15; Friday afternoon rule ended 2026-10-10.
        FacilityVisitationRule::where('day_of_week', 'sun')->update(['effective_from' => '2026-10-15']);
        FacilityVisitationRule::where('day_of_week', 'fri')->where('time_slot_start', '13:00:00')
            ->update(['effective_to' => '2026-10-10']);
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability()->assertOk()->json('relationships.0');

        $sundayBefore = $this->day($relationship, '2026-10-11');
        $this->assertFalse($sundayBefore['available']);
        $this->assertSame(['not_in_effect', 'not_in_effect'], array_column($sundayBefore['slots'], 'reason'));
        $this->assertTrue($this->day($relationship, '2026-10-18')['available']);

        $this->assertTrue($this->slot($relationship, '2026-10-09', '13:00')['available']);
        $laterFriday = $this->day($relationship, '2026-10-16');
        $this->assertSame(['09:00'], array_column($laterFriday['slots'], 'startTime'));
    }

    public function test_viewing_availability_creates_no_schedule_rows(): void
    {
        $before = VisitSchedule::count();
        Sanctum::actingAs($this->visitorAccount);

        $this->availability()->assertOk();

        $this->assertSame($before, VisitSchedule::count());
    }

    public function test_invalid_range_is_rejected(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $this->availability(['from' => '2026-10-20', 'to' => '2026-10-10'])->assertUnprocessable();
        $this->availability(['from' => '2026-10-01', 'to' => '2027-03-01'])->assertUnprocessable();
        $this->availability(['from' => '10/07/2026'])->assertUnprocessable();
    }

    // ------------------------------------------- Visitor's own bookings

    /**
     * An existing visit for $relationship's visitor in the $date/$start slot,
     * using the rule already seeded for that slot (never a new rule, so the
     * calendar under test is unchanged) and the slot's schedule row.
     */
    private function visitOn(
        VisitorPdlRelationship $relationship,
        string $date,
        string $start,
        string $status = 'confirmed'
    ): VisitRequest {
        $relationship->loadMissing('pdl');
        $rule = FacilityVisitationRule::where('pdl_classification', $relationship->pdl->classification)
            ->where('day_of_week', strtolower(CarbonImmutable::parse($date)->format('D')))
            ->where('time_slot_start', $start.':00')
            ->sole();
        $schedule = VisitSchedule::where('rule_id', $rule->rule_id)->whereDate('schedule_date', $date)->first()
            ?? VisitSchedule::create([
                'rule_id' => $rule->rule_id,
                'schedule_date' => $date,
                'time_slot_start' => $rule->time_slot_start,
                'time_slot_end' => $rule->time_slot_end,
                'max_capacity' => $rule->max_capacity,
                'slots_taken' => 0,
                'status' => 'open',
            ]);

        return VisitRequest::create([
            'visitor_id' => $relationship->visitor_id,
            'pdl_id' => $relationship->pdl_id,
            'relationship_id' => $relationship->relationship_id,
            'schedule_id' => $schedule->schedule_id,
            'status' => $status,
            'confirmation_deadline' => null,
        ]);
    }

    public function test_already_scheduled_slot_is_unavailable(): void
    {
        $this->visitOn($this->relationship, '2026-10-09', '09:00');
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability()->assertOk()->json('relationships.0');

        $slot = $this->slot($relationship, '2026-10-09', '09:00');
        $this->assertFalse($slot['available']);
        $this->assertSame('already_scheduled', $slot['reason']);
        $this->assertSame(1, $slot['slotsRemaining']);
        $this->assertSame((string) $this->schedule->schedule_id, $slot['scheduleId']);
    }

    // ------------------------------------------------------ Weekly limit

    public function test_slots_in_a_week_at_the_weekly_limit_are_unavailable(): void
    {
        // Default visit.max_per_week of 2, both used in the week Mon 10-05 – Sun 10-11.
        $this->visitOn($this->relationship, '2026-10-09', '09:00');
        $this->visitOn($this->relationship, '2026-10-11', '09:00');
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability()->assertOk()->json('relationships.0');

        foreach (['2026-10-09', '2026-10-11'] as $date) {
            $slot = $this->slot($relationship, $date, '13:00');
            $this->assertFalse($slot['available'], $date);
            $this->assertSame('weekly_limit', $slot['reason'], $date);
            // Capacity is still the slot's own, not the visitor's.
            $this->assertSame(30, $slot['capacity']);
            $this->assertSame(30, $slot['slotsRemaining']);
            $this->assertFalse($this->day($relationship, $date)['available'], $date);
        }

        // The following week is unaffected.
        $this->assertTrue($this->slot($relationship, '2026-10-16', '09:00')['available']);
        $this->assertTrue($this->slot($relationship, '2026-10-16', '13:00')['available']);
        $this->assertTrue($this->day($relationship, '2026-10-18')['available']);
    }

    public function test_weekly_limit_counts_visits_before_the_requested_range(): void
    {
        // Both visits are on Fri 10-09; the requested range starts Sat 10-10,
        // still inside the same Mon 10-05 – Sun 10-11 week.
        $this->visitOn($this->relationship, '2026-10-09', '09:00');
        $this->visitOn($this->relationship, '2026-10-09', '13:00');
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability(['from' => '2026-10-10', 'to' => '2026-10-18'])
            ->assertOk()
            ->json('relationships.0');

        $this->assertSame('weekly_limit', $this->slot($relationship, '2026-10-11', '09:00')['reason']);
        $this->assertSame('weekly_limit', $this->slot($relationship, '2026-10-11', '13:00')['reason']);
        $this->assertTrue($this->slot($relationship, '2026-10-16', '13:00')['available']);
    }

    public function test_one_visit_below_the_weekly_limit_keeps_slots_available(): void
    {
        $this->visitOn($this->relationship, '2026-10-11', '09:00');
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability()->assertOk()->json('relationships.0');

        $this->assertTrue($this->slot($relationship, '2026-10-09', '09:00')['available']);
        $this->assertTrue($this->slot($relationship, '2026-10-09', '13:00')['available']);
        $this->assertTrue($this->slot($relationship, '2026-10-11', '13:00')['available']);
    }

    public function test_cancelled_and_declined_visits_do_not_count_toward_the_weekly_limit(): void
    {
        $this->visitOn($this->relationship, '2026-10-09', '09:00', 'cancelled');
        $this->visitOn($this->relationship, '2026-10-11', '09:00', 'declined');
        $this->visitOn($this->relationship, '2026-10-11', '13:00');
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability()->assertOk()->json('relationships.0');

        $this->assertTrue($this->slot($relationship, '2026-10-09', '09:00')['available']);
        $this->assertTrue($this->slot($relationship, '2026-10-09', '13:00')['available']);
        $this->assertTrue($this->slot($relationship, '2026-10-11', '09:00')['available']);
    }

    public function test_weekly_limit_applies_across_relationships(): void
    {
        // Both visits are with a different (drug-related) PDL.
        $drug = $this->makeDrugRelatedRelationship();
        $this->visitOn($drug, '2026-10-08', '09:00');
        $this->visitOn($drug, '2026-10-10', '09:00');
        Sanctum::actingAs($this->visitorAccount);

        $response = $this->availability()->assertOk();
        $own = $response->json('relationships.0');
        $other = $response->json('relationships.1');
        $this->assertSame((string) $this->relationship->relationship_id, $own['relationshipId']);
        $this->assertSame((string) $drug->relationship_id, $other['relationshipId']);

        $this->assertSame('weekly_limit', $this->slot($own, '2026-10-09', '09:00')['reason']);
        $this->assertSame('weekly_limit', $this->slot($own, '2026-10-09', '13:00')['reason']);
        $this->assertSame('weekly_limit', $this->slot($own, '2026-10-11', '13:00')['reason']);
        $this->assertSame('weekly_limit', $this->slot($other, '2026-10-08', '13:00')['reason']);

        $this->assertTrue($this->slot($own, '2026-10-16', '09:00')['available']);
        $this->assertTrue($this->slot($other, '2026-10-15', '09:00')['available']);
    }

    public function test_more_specific_reasons_take_precedence_over_weekly_limit(): void
    {
        $this->schedule->update(['slots_taken' => 2]); // Fri 09:00, capacity 2
        $this->visitOn($this->relationship, '2026-10-11', '09:00');
        $this->visitOn($this->relationship, '2026-10-11', '13:00');
        Sanctum::actingAs($this->visitorAccount);

        $relationship = $this->availability()->assertOk()->json('relationships.0');

        $this->assertSame('full', $this->slot($relationship, '2026-10-09', '09:00')['reason']);
        $this->assertSame('already_scheduled', $this->slot($relationship, '2026-10-11', '09:00')['reason']);
        $this->assertSame('already_scheduled', $this->slot($relationship, '2026-10-11', '13:00')['reason']);
        $this->assertSame('weekly_limit', $this->slot($relationship, '2026-10-09', '13:00')['reason']);
    }

    public function test_weekly_limit_follows_the_setting(): void
    {
        $setting = SystemSetting::create([
            'setting_key' => 'visit.max_per_week',
            'setting_value' => '0',
            'updated_by' => StaffProfile::first()->staff_id,
        ]);
        Sanctum::actingAs($this->visitorAccount);

        // Zero allows no visits, even in a week with none booked.
        $relationship = $this->availability()->assertOk()->json('relationships.0');
        $this->assertSame([], $this->availableDays($relationship));
        $this->assertSame('weekly_limit', $this->slot($relationship, '2026-10-16', '13:00')['reason']);

        $setting->update(['setting_value' => '3']);
        $this->visitOn($this->relationship, '2026-10-09', '09:00');
        $this->visitOn($this->relationship, '2026-10-11', '09:00');

        $relationship = $this->availability()->assertOk()->json('relationships.0');
        $this->assertTrue($this->slot($relationship, '2026-10-09', '13:00')['available']);
    }

    // ------------------------------------------------------- Announcements

    public function test_announcements_endpoint_returns_config_content(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $response = $this->getJson('/api/announcements')
            ->assertOk()
            ->assertJsonStructure(['announcements' => [['id', 'title', 'body', 'category', 'pinned', 'createdAt']]]);

        $this->assertCount(count(config('visitation.announcements')), $response->json('announcements'));
        $this->assertTrue($response->json('announcements.0.pinned'));
    }

    public function test_announcements_available_to_visitor_awaiting_approval(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);
        Sanctum::actingAs($this->visitorAccount);

        $this->getJson('/api/announcements')->assertOk();
    }
}
