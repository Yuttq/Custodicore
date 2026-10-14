<?php

namespace Tests\Feature;

use App\Models\CellBlock;
use App\Models\Pdl;
use App\Models\PdlLegalRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * The PDL's classification (visiting-day group) follows their offenses:
 * any RA 9165 case = drug-related, otherwise non-drug-related.
 */
class OffenseClassificationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;
    use MakesTestImages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Storage::fake('local');
        $this->actingAs($this->recordOfficerAccount, 'web');
        CellBlock::create(['name' => 'Dorm 1', 'capacity' => 10, 'designation' => 'any']);
    }

    private function register(array $overrides = []): void
    {
        $this->post('/pdls', array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'date_of_birth' => '1991-05-05',
            'gender' => 'male',
            'admission_date' => now()->subMonth()->toDateString(),
            'cell_block' => 'Dorm 1',
            'photo' => $this->fakePhoto(),
            'case_number' => 'cr-2026-0142',
            'offense_code' => 'ra9165_sale',
            'court' => 'RTC Branch 12',
        ], $overrides))->assertSessionHasNoErrors();
    }

    public function test_registering_with_a_drug_offense_makes_the_pdl_drug_related(): void
    {
        $this->register(['classification' => 'non_drug_related']); // ignored: it follows the offense

        $pdl = Pdl::where('full_name', 'Juan Dela Cruz')->firstOrFail();
        $this->assertSame('drug_related', $pdl->classification);

        $case = $pdl->legalRecords()->sole();
        $this->assertSame('CR-2026-0142', $case->case_number);
        $this->assertSame('ra9165_sale', $case->offense_code);
        $this->assertSame('Sale / trading of dangerous drugs (Sec. 5)', $case->offense);
        $this->assertSame('RTC Branch 12', $case->court);
    }

    public function test_other_offenses_are_non_drug_related_and_need_wording(): void
    {
        $this->post('/pdls', [
            'first_name' => 'Pedro', 'last_name' => 'Reyes', 'date_of_birth' => '1990-01-01', 'gender' => 'male',
            'admission_date' => now()->subMonth()->toDateString(), 'cell_block' => 'Dorm 1', 'photo' => $this->fakePhoto(),
            'case_number' => 'CR-1', 'offense_code' => 'other',
        ])->assertSessionHasErrors('offense_other');

        $this->register(['offense_code' => 'other', 'offense_other' => 'Reckless imprudence resulting in homicide']);
        $pdl = Pdl::where('full_name', 'Juan Dela Cruz')->firstOrFail();
        $this->assertSame('non_drug_related', $pdl->classification);
        $this->assertSame('Reckless imprudence resulting in homicide', $pdl->primaryOffense());
    }

    public function test_offense_is_required_and_must_be_from_the_list(): void
    {
        $this->post('/pdls', ['first_name' => 'Juan', 'offense_code' => 'jaywalking'])->assertSessionHasErrors(['offense_code', 'case_number']);
    }

    public function test_adding_a_drug_case_switches_the_classification(): void
    {
        // Fixture PDL: non-drug-related, give it a non-drug case first.
        $url = "/pdls/{$this->pdl->pdl_id}/legal-records";
        $this->post($url, ['case_number' => 'CR-1', 'offense_code' => 'theft'])->assertSessionHas('success', 'Legal record added.');
        $this->assertSame('non_drug_related', $this->pdl->fresh()->classification);

        $this->post($url, ['case_number' => 'CR-2', 'offense_code' => 'ra9165_possession'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'now classified as Drug-related'));
        $this->assertSame('drug_related', $this->pdl->fresh()->classification);
    }

    public function test_classification_cannot_be_edited_directly(): void
    {
        PdlLegalRecord::create([
            'pdl_id' => $this->pdl->pdl_id, 'case_number' => 'CR-9', 'offense_code' => 'theft', 'offense' => 'Theft',
            'recorded_by' => $this->pdl->registered_by,
        ]);

        $this->put("/pdls/{$this->pdl->pdl_id}", [
            'first_name' => 'Example', 'last_name' => 'Santos', 'date_of_birth' => '1990-01-01', 'gender' => 'male',
            'classification' => 'drug_related', 'admission_date' => '2024-01-01', 'custody_status' => 'released',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertSame('non_drug_related', $this->pdl->fresh()->classification);
    }

    public function test_list_shows_and_filters_by_offense(): void
    {
        $this->register();

        $this->get('/pdls')->assertOk()->assertSee('Sale / trading of dangerous drugs (Sec. 5)')->assertSee('Drug-related');
        $this->get('/pdls?offense=ra9165_sale')->assertSee('Juan Dela Cruz')->assertDontSee($this->pdl->full_name);
        $this->get('/pdls?offense=theft')->assertDontSee('Juan Dela Cruz');
    }

    public function test_older_free_text_drug_cases_still_count(): void
    {
        PdlLegalRecord::create([
            'pdl_id' => $this->pdl->pdl_id, 'case_number' => 'OLD-1', 'offense' => 'Violation of RA 9165 (shabu)',
            'recorded_by' => $this->pdl->registered_by,
        ]);

        $this->assertTrue($this->pdl->refreshClassification());
        $this->assertSame('drug_related', $this->pdl->fresh()->classification);
    }
}
