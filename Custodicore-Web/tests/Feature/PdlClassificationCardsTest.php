<?php

namespace Tests\Feature;

use App\Models\Pdl;
use App\Models\StaffProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * PDL Records analytics cards: PDLs in custody by their cases (drug only,
 * non-drug only, both; each PDL in exactly one, adding up to Total
 * Population), cases on file by kind, and PDLs with a restriction today.
 * Each card opens the list filtered to it.
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

    private function caseOf(Pdl $pdl, string $code, string $number): void
    {
        \App\Models\PdlLegalRecord::create([
            'pdl_id' => $pdl->pdl_id, 'case_number' => $number, 'offense_code' => $code,
            'offense' => \App\Support\Offenses::label($code), 'recorded_by' => $pdl->registered_by,
        ]);
    }

    public function test_case_group_cards_count_each_pdl_in_custody_once(): void
    {
        $drug = $this->pdl('DRUG-ONLY', 'drug_related');
        $this->caseOf($drug, 'ra9165_sale', 'CR-1');
        $both = $this->pdl('BOTH-1', 'drug_related');
        $this->caseOf($both, 'ra9165_sale', 'CR-2');
        $this->caseOf($both, 'theft', 'CR-3');
        $nonDrug = $this->pdl('THEFT-1', 'non_drug_related');
        $this->caseOf($nonDrug, 'theft', 'CR-4');
        $out = $this->pdl('BOTH-OUT', 'drug_related', 'released'); // not in custody: not counted
        $this->caseOf($out, 'ra9165_sale', 'CR-5');
        $this->caseOf($out, 'theft', 'CR-6');

        $this->assertSame('drug_only', $drug->caseGroup());
        $this->assertSame('both', $both->caseGroup());
        $this->assertSame('non_drug_only', $nonDrug->caseGroup());

        $stats = $this->get(route('pdl.index'))->assertOk()
            ->assertSee('Drug Cases Only')->assertSee('Non-drug Cases Only')->assertSee('Both Drug &amp; Non-drug', false)
            ->assertSee(route('pdl.index', ['status' => 'active', 'cases' => 'both']))
            ->viewData('stats');

        $this->assertSame(1, $stats['drug_only']);
        $this->assertSame(1, $stats['both']);
        $this->assertSame(2, $stats['non_drug_only'], 'THEFT-1 and the fixture PDL (no case on file, non-drug-related).');
        $this->assertSame($stats['total_population'], $stats['drug_only'] + $stats['non_drug_only'] + $stats['both']);
        // Cases, not people: CR-1, CR-2 drug; CR-3, CR-4 non-drug (the released PDL's cases are left out).
        $this->assertSame(2, $stats['drug_cases']);
        $this->assertSame(4, $stats['all_cases']);
    }

    private function restrict(Pdl $pdl, string $start, ?string $end, string $status = 'active'): void
    {
        \App\Models\PdlRestriction::withoutEvents(fn () => \App\Models\PdlRestriction::create([
            'pdl_id' => $pdl->pdl_id, 'restriction_type' => 'quarantine', 'status' => $status,
            'start_date' => $start, 'end_date' => $end, 'reason' => 'Medical quarantine per infirmary order',
            'imposed_by' => StaffProfile::first()->staff_id,
        ]));
    }

    public function test_with_restrictions_counts_and_lists_pdls_restricted_today(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-25 10:00:00', 'Asia/Manila'));
        $ending = $this->pdl('REST-END', 'drug_related');
        $openEnded = $this->pdl('REST-OPEN', 'drug_related');
        $ended = $this->pdl('REST-PAST', 'drug_related');
        $lifted = $this->pdl('REST-LIFT', 'drug_related');
        $this->restrict($ending, '2026-11-20', '2026-11-30');
        $this->restrict($openEnded, '2026-11-01', null);
        $this->restrict($ended, '2026-11-01', '2026-11-24');     // ended yesterday
        $this->restrict($lifted, '2026-11-01', null, 'lifted');

        $stats = $this->get(route('pdl.index'))->assertOk()
            ->assertSee('With Restrictions')
            ->assertSee(route('pdl.index', ['status' => 'active', 'restricted' => 1]))
            ->viewData('stats');
        $this->assertSame(2, $stats['restricted']);

        $this->get(route('pdl.index', ['status' => 'active', 'restricted' => 1]))->assertOk()
            ->assertSee('Showing only PDLs with a restriction in effect today.')
            ->assertSee('REST-END')->assertSee('Until Nov 30, 2026')
            ->assertSee('REST-OPEN')->assertSee('Until lifted')
            ->assertSee('Medical quarantine per infirmary order')
            ->assertDontSee('REST-PAST')->assertDontSee('REST-LIFT')->assertDontSee('PDL-TEST-001');
    }

    public function test_a_case_group_card_filters_the_list(): void
    {
        $both = $this->pdl('BOTH-1', 'drug_related');
        $this->caseOf($both, 'ra9165_sale', 'CR-2');
        $this->caseOf($both, 'theft', 'CR-3');
        $drug = $this->pdl('DRUG-ONLY', 'drug_related');
        $this->caseOf($drug, 'ra9165_sale', 'CR-1');

        $this->get(route('pdl.index', ['status' => 'active', 'cases' => 'both']))->assertOk()
            ->assertSee('Showing only PDLs in custody with both drug and non-drug cases.')
            ->assertSee('BOTH-1')->assertDontSee('DRUG-ONLY')->assertDontSee('PDL-TEST-001');

        $this->get(route('pdl.index', ['status' => 'active', 'cases' => 'drug_only']))->assertOk()
            ->assertSee('DRUG-ONLY')->assertDontSee('BOTH-1');

        // An unknown value is ignored.
        $this->get(route('pdl.index', ['cases' => 'other']))->assertOk()
            ->assertSee('BOTH-1')->assertSee('DRUG-ONLY')->assertSee('PDL-TEST-001');
    }
}
