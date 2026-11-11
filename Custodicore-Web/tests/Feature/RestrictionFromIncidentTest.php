<?php

namespace Tests\Feature;

use App\Models\Pdl;
use App\Models\PdlDisciplinaryRecord;
use App\Models\PdlRestriction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * A restriction can name the disciplinary incident that led to it. The
 * incident shows "Led to restriction" only when such a restriction exists,
 * so the label can't disagree with what actually happened.
 */
class RestrictionFromIncidentTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    private function addIncident(): PdlDisciplinaryRecord
    {
        $this->post(route('pdl.disciplinary-records.store', $this->pdl->pdl_id), [
            'incident_date' => now()->toDateString(),
            'description' => 'Found with a mobile phone during cell inspection.',
            'action_taken' => 'Phone confiscated',
        ])->assertSessionHasNoErrors();

        return PdlDisciplinaryRecord::where('pdl_id', $this->pdl->pdl_id)->latest('disciplinary_record_id')->firstOrFail();
    }

    public function test_incident_shows_led_to_restriction_only_once_a_restriction_is_linked(): void
    {
        $incident = $this->addIncident();

        $this->get(route('pdl.show', $this->pdl->pdl_id))
            ->assertOk()
            ->assertDontSee('</span>Led to restriction', false)
            ->assertDontSee('name="triggers_restriction"', false)
            ->assertSee('name="disciplinary_record_id"', false);

        $this->post(route('pdl.restrictions.store', $this->pdl->pdl_id), [
            'restriction_type' => 'disciplinary',
            'start_date' => now()->toDateString(),
            'reason' => 'Contraband found during inspection',
            'disciplinary_record_id' => $incident->disciplinary_record_id,
        ])->assertSessionHasNoErrors();

        $restriction = PdlRestriction::where('pdl_id', $this->pdl->pdl_id)->firstOrFail();
        $this->assertSame($incident->disciplinary_record_id, (int) $restriction->disciplinary_record_id);
        $this->assertTrue($incident->fresh()->ledToRestriction());

        $this->get(route('pdl.show', $this->pdl->pdl_id))
            ->assertSee('</span>Led to restriction', false)
            ->assertSee('From incident:');
    }

    public function test_restriction_without_an_incident_still_works(): void
    {
        $this->post(route('pdl.restrictions.store', $this->pdl->pdl_id), [
            'restriction_type' => 'quarantine',
            'start_date' => now()->toDateString(),
            'reason' => 'Medical quarantine',
            'disciplinary_record_id' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull(PdlRestriction::where('pdl_id', $this->pdl->pdl_id)->value('disciplinary_record_id'));
    }

    public function test_an_incident_of_another_pdl_is_refused(): void
    {
        $incident = $this->addIncident();
        $other = Pdl::create([
            'pdl_number' => 'PDL-OTHER-1',
            'full_name' => 'Other Person',
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => $this->pdl->registered_by,
        ]);

        $this->post(route('pdl.restrictions.store', $other->pdl_id), [
            'restriction_type' => 'disciplinary',
            'start_date' => now()->toDateString(),
            'reason' => 'Contraband found during inspection',
            'disciplinary_record_id' => $incident->disciplinary_record_id,
        ])->assertSessionHasErrors('disciplinary_record_id');

        $this->assertSame(0, PdlRestriction::where('pdl_id', $other->pdl_id)->count());
    }
}
