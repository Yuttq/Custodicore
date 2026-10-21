<?php

namespace Tests\Feature;

use App\Models\CellBlock;
use App\Models\FacilityVisitationRule;
use App\Models\PdlRestriction;
use App\Services\PdlVisitingAvailability;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Each dorm can have its own visiting schedule. A PDL whose dorm has hours
 * follows them instead of the classification days; dorms without hours keep
 * the classification days. Times are 24-hour.
 *
 * Fixture: non_drug_related PDL, classification rule Friday 09:00–11:30.
 * "Today" is Wed 2026-10-07 10:00 (Asia/Manila).
 */
class DormVisitingScheduleTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private CellBlock $dorm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        $this->dorm = CellBlock::create(['name' => 'Dorm 1', 'capacity' => 10, 'designation' => 'any']);
        $this->pdl->update(['cell_block' => 'Dorm 1']);
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    private function addHours(array $days, string $start, string $end, int $capacity = 15)
    {
        return $this->post("/cell-blocks/{$this->dorm->cell_block_id}/visiting-hours", [
            'days' => $days, 'start' => $start, 'end' => $end, 'max_capacity' => $capacity,
        ]);
    }

    private function book(string $date, string $start)
    {
        Sanctum::actingAs($this->visitorAccount);

        return $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id, 'date' => $date, 'startTime' => $start,
        ]);
    }

    public function test_officer_sets_dorm_hours_in_24_hour_time(): void
    {
        $this->addHours(['mon', 'thu'], '08:00', '11:30')->assertSessionHasNoErrors();

        $rules = FacilityVisitationRule::where('cell_block_id', $this->dorm->cell_block_id)->get();
        $this->assertCount(2, $rules);
        $this->assertNull($rules->first()->pdl_classification);
        $this->assertSame('08:00–11:30', $rules->first()->hoursLabel());

        $this->get("/cell-blocks/{$this->dorm->cell_block_id}/visiting-hours")->assertOk()->assertSee('08:00–11:30')->assertSee('15 visitors / session');
        $this->get('/cell-blocks')->assertSee('Mon, Thu 08:00–11:30');
    }

    public function test_hours_are_validated(): void
    {
        $this->addHours(['mon'], '8:00 AM', '11:30')->assertSessionHasErrors('start');   // not 24-hour
        $this->addHours(['mon'], '11:30', '08:00')->assertSessionHasErrors('end');       // end before start
        $this->addHours([], '08:00', '11:30')->assertSessionHasErrors('days');

        $this->addHours(['mon'], '08:00', '11:30')->assertSessionHasNoErrors();
        $this->addHours(['mon'], '10:00', '12:00')->assertSessionHasErrors('start');     // overlaps
        $this->addHours(['mon'], '11:45', '11:55')->assertSessionHasErrors('start');     // second morning session
        $this->addHours(['mon'], '13:00', '16:30')->assertSessionHasNoErrors();          // afternoon is fine
    }

    public function test_pdl_follows_dorm_schedule_instead_of_classification_days(): void
    {
        $this->assertSame('non_drug_related', FacilityVisitationRule::groupKeyForPdl($this->pdl));

        $this->addHours(['mon', 'thu'], '08:00', '11:30');
        $this->assertSame('dorm:' . $this->dorm->cell_block_id, FacilityVisitationRule::groupKeyForPdl($this->pdl->fresh()));

        // The app's availability now shows the dorm days, not Friday.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/schedules/availability')->assertOk()
            ->assertJsonPath('relationships.0.visitingDays', ['mon', 'thu']);

        // Friday (classification day) is refused; Monday 08:00 (dorm day) books.
        $this->book('2026-10-09', '09:00')->assertStatus(422);
        $this->book('2026-10-12', '08:00')->assertCreated();
    }

    public function test_staff_cannot_assign_a_classification_slot_to_a_pdl_on_a_dorm_schedule(): void
    {
        $this->addHours(['mon'], '08:00', '11:30');

        $this->expectException(ValidationException::class);
        app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id, // Friday classification slot
        ]);
    }

    public function test_dorm_without_hours_keeps_classification_days(): void
    {
        $other = CellBlock::create(['name' => 'Dorm 2', 'capacity' => 10, 'designation' => 'any']);
        FacilityVisitationRule::create([
            'cell_block_id' => $other->cell_block_id, 'day_of_week' => 'mon',
            'time_slot_start' => '08:00:00', 'time_slot_end' => '11:30:00', 'max_capacity' => 10, 'effective_from' => '2026-01-01',
        ]);

        // Dorm 1 (the PDL's) has no hours of its own → still Friday.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/schedules/availability')->assertJsonPath('relationships.0.visitingDays', ['fri']);
    }

    public function test_removing_hours_is_blocked_while_visits_are_booked_then_falls_back(): void
    {
        $this->addHours(['mon'], '08:00', '11:30');
        $rule = FacilityVisitationRule::where('cell_block_id', $this->dorm->cell_block_id)->sole();
        $this->book('2026-10-12', '08:00')->assertCreated();

        $this->actingAs($this->recordOfficerAccount, 'web');
        $url = "/cell-blocks/{$this->dorm->cell_block_id}/visiting-hours/{$rule->rule_id}";
        $this->delete($url)->assertSessionHas('error', fn ($m) => str_contains($m, 'booked visits'));

        // Once the booking is gone the hours can be removed; the PDL falls back to classification days.
        \App\Models\VisitRequest::query()->update(['status' => 'cancelled']);
        $this->delete($url)->assertSessionHas('success');
        $this->assertSame('non_drug_related', FacilityVisitationRule::groupKeyForPdl($this->pdl->fresh()));
    }

    public function test_availability_label_for_the_pdl(): void
    {
        $this->addHours(['wed'], '08:00', '11:30');
        $this->addHours(['thu'], '13:00', '16:30');
        $pdl = $this->pdl->fresh();
        $at = fn (string $t) => CarbonImmutable::parse($t, 'Asia/Manila');

        $this->assertSame('Available now · until 11:30', PdlVisitingAvailability::for($pdl, null, $at('2026-10-07 10:00'))['label']);
        $this->assertSame('Today 08:00–11:30', PdlVisitingAvailability::for($pdl, null, $at('2026-10-07 07:00'))['label']);
        $this->assertSame('Next: Tomorrow 13:00–16:30', PdlVisitingAvailability::for($pdl, null, $at('2026-10-07 12:00'))['label']);
        $this->assertSame('Dorm 1 schedule', PdlVisitingAvailability::for($pdl)['source']);

        PdlRestriction::create([
            'pdl_id' => $pdl->pdl_id, 'restriction_type' => 'quarantine', 'status' => 'active',
            'start_date' => '2026-10-01', 'reason' => 'Medical', 'imposed_by' => $pdl->registered_by,
        ]);
        $this->assertSame('Restricted: Quarantine', PdlVisitingAvailability::for($pdl->fresh())['label']);

        $this->get("/pdls/{$pdl->pdl_id}")->assertOk()->assertSee('Restricted: Quarantine')->assertSee('Wed 08:00–11:30');
        $this->get('/pdls')->assertOk()->assertSee('Restricted: Quarantine');
    }

    public function test_slot_page_offers_the_dorm_as_a_group(): void
    {
        $this->addHours(['mon'], '08:00', '11:30');
        $group = 'dorm:' . $this->dorm->cell_block_id;

        $this->get('/visitor-slots')->assertOk()->assertSee('Dorm: Dorm 1');

        $this->post('/visitor-slots', ['schedule_date' => '2026-10-12', 'pdl_classification' => $group, 'session' => 'morning', 'max_capacity' => 12, 'is_open' => '1'])
            ->assertSessionHasNoErrors();
        $this->post('/visitor-slots', ['schedule_date' => '2026-10-09', 'pdl_classification' => $group, 'session' => 'morning', 'max_capacity' => 12, 'is_open' => '1'])
            ->assertSessionHasErrors();
        $this->assertStringContainsString('Dorm 1 has no visiting on Fridays', collect(session('errors')->all())->join(' '));
    }
}
