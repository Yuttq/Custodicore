<?php

namespace Tests\Feature;

use App\Models\Pdl;
use App\Models\StaffProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * PDL Records: Drug-related / Non-drug-related cards count the PDLs in
 * custody (together they make up Total Population) and open the list
 * filtered to that classification.
 */
class PdlClassificationCardsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures(); // one non-drug-related PDL in custody
        $this->withoutVite();
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    private function pdl(string $number, string $classification, string $status = 'active'): Pdl
    {
        return Pdl::create([
            'pdl_number' => $number, 'first_name' => 'Test', 'last_name' => $number,
            'date_of_birth' => '1990-01-01', 'gender' => 'male', 'classification' => $classification,
            'admission_date' => '2025-01-01', 'custody_status' => $status,
            'registered_by' => StaffProfile::first()->staff_id,
        ]);
    }

    public function test_cards_count_pdls_in_custody_by_classification(): void
    {
        $this->pdl('DRUG-1', 'drug_related');
        $this->pdl('DRUG-2', 'drug_related');
        $this->pdl('DRUG-OUT', 'drug_related', 'released'); // not in custody: not counted

        $stats = $this->get(route('pdl.index'))->assertOk()
            ->assertSee('Drug-related')
            ->assertSee('Non-drug-related')
            ->assertSee(route('pdl.index', ['status' => 'active', 'classification' => 'drug_related']))
            ->viewData('stats');

        $this->assertSame(2, $stats['drug_related']);
        $this->assertSame(1, $stats['non_drug_related']);
        $this->assertSame($stats['total_population'], $stats['drug_related'] + $stats['non_drug_related']);
    }

    public function test_a_card_filters_the_list(): void
    {
        $this->pdl('DRUG-1', 'drug_related');

        $this->get(route('pdl.index', ['status' => 'active', 'classification' => 'drug_related']))->assertOk()
            ->assertSee('Showing only drug-related PDLs.')
            ->assertSee('DRUG-1')
            ->assertDontSee('PDL-TEST-001');

        $this->get(route('pdl.index', ['status' => 'active', 'classification' => 'non_drug_related']))->assertOk()
            ->assertSee('PDL-TEST-001')
            ->assertDontSee('DRUG-1');

        // An unknown value is ignored.
        $this->get(route('pdl.index', ['classification' => 'other']))->assertOk()
            ->assertSee('DRUG-1')->assertSee('PDL-TEST-001');
    }
}
