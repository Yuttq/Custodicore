<?php

namespace Tests\Feature;

use App\Models\Pdl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * PDL search box on the visitor page's "Add PDL Relationship" form.
 */
class RelatablePdlSearchTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private Pdl $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->other = Pdl::create([
            'pdl_number' => 'PDL-2026-00042',
            'full_name' => 'Ramon Bautista',
            'alias' => 'Monching',
            'date_of_birth' => '1985-01-01',
            'gender' => 'male',
            'classification' => 'drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => $this->pdl->registered_by,
        ]);
    }

    private function search(string $q)
    {
        return $this->getJson("/visitors/{$this->visitor->visitor_id}/relatable-pdls?q=" . urlencode($q))->assertOk();
    }

    public function test_finds_pdls_by_name_alias_number_or_id(): void
    {
        foreach (['ramon', 'Monch', '00042', (string) $this->other->pdl_id] as $q) {
            $this->search($q)->assertJsonPath('results.0.id', $this->other->pdl_id)
                ->assertJsonPath('results.0.number', 'PDL-2026-00042')
                ->assertJsonPath('results.0.status', 'active');
        }
    }

    public function test_excludes_pdls_already_linked_to_the_visitor(): void
    {
        // The fixture visitor is already related to the fixture PDL.
        $this->search($this->pdl->full_name)->assertJsonCount(0, 'results');
        $this->search('')->assertJsonCount(0, 'results');
    }

    public function test_form_uses_the_search_box_instead_of_a_dropdown(): void
    {
        $this->get("/visitors/{$this->visitor->visitor_id}")
            ->assertOk()
            ->assertSee('data-pdl-search', false)
            ->assertSee('Search name, alias, PDL number or ID')
            ->assertDontSee('— Select PDL —');
    }

    public function test_only_record_officers_can_search(): void
    {
        $this->actingAs($this->frontDeskAccount, 'web')
            ->getJson("/visitors/{$this->visitor->visitor_id}/relatable-pdls?q=ramon")
            ->assertForbidden();
    }
}
