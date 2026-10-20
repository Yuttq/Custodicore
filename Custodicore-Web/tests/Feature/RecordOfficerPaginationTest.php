<?php

namespace Tests\Feature;

use App\Models\Pdl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Record Officer pages use plain-CSS page links (partials.pagination), not
 * Laravel's Tailwind view, whose unstyled SVG arrows rendered huge.
 */
class RecordOfficerPaginationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    public function test_pdl_list_uses_the_plain_css_page_links(): void
    {
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        for ($i = 1; $i <= 30; $i++) {
            Pdl::create([
                'pdl_number' => sprintf('PDL-PG-%03d', $i),
                'full_name' => "Pager Test {$i}",
                'date_of_birth' => '1990-01-01',
                'gender' => 'male',
                'classification' => 'drug_related',
                'admission_date' => '2024-01-01',
                'custody_status' => 'active',
                'registered_by' => $this->pdl->registered_by,
            ]);
        }

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->get('/pdls')
            ->assertOk()
            ->assertSee('class="pager"', false)
            ->assertSee('Next ›')
            ->assertSee('aria-current="page"', false)
            ->assertDontSee('Showing 1 to'); // Laravel's duplicate "Showing … results" line is gone
    }
}
