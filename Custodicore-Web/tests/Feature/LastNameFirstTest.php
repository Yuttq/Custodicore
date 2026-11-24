<?php

namespace Tests\Feature;

use App\Models\Pdl;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Staff screens show PDL and staff names last name first ("Dela Cruz,
 * Juan Santos Jr."), and the PDL forms ask for the last name first. The
 * stored full_name (read by the mobile app and admin) stays in natural order.
 */
class LastNameFirstTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();
    }

    public function test_pdl_list_name_is_last_name_first(): void
    {
        $this->pdl->update(['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.']);
        $pdl = $this->pdl->fresh();

        $this->assertSame('Dela Cruz, Juan Santos Jr.', $pdl->listName());
        $this->assertSame('Juan Santos Dela Cruz Jr.', $pdl->full_name, 'The stored full name keeps its natural order.');

        $pdl->update(['middle_name' => null, 'suffix' => null]);
        $this->assertSame('Dela Cruz, Juan', $pdl->fresh()->listName());
    }

    public function test_an_older_pdl_with_only_a_full_name_shows_it_as_is(): void
    {
        $pdl = Pdl::withoutEvents(fn () => tap($this->pdl)->forceFill([
            'first_name' => null, 'middle_name' => null, 'last_name' => null, 'suffix' => null, 'full_name' => 'Pedro Reyes',
        ])->save());

        $this->assertSame('Pedro Reyes', $this->pdl->fresh()->listName());
    }

    public function test_staff_list_name_is_last_name_first(): void
    {
        $staff = new StaffProfile(['first_name' => 'Rico', 'middle_name' => 'P.', 'last_name' => 'Salcedo']);

        $this->assertSame('Salcedo, Rico P.', $staff->listName());
    }

    public function test_pdl_profile_and_list_show_last_name_first(): void
    {
        $this->pdl->update(['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz']);
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->get(route('pdl.show', $this->pdl->pdl_id))->assertOk()
            ->assertSee('<h1 class="pdl-hero-name">Dela Cruz, Juan Santos</h1>', false);
        $this->get(route('pdl.index'))->assertOk()->assertSee('Dela Cruz, Juan Santos');
    }

    public function test_pdl_forms_ask_for_the_last_name_first(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web');

        foreach ([route('pdl.create'), route('pdl.show', $this->pdl->pdl_id)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $last = strpos($html, 'name="last_name"');
            $first = strpos($html, 'name="first_name"');
            $middle = strpos($html, 'name="middle_name"');

            $this->assertNotFalse($last, $url);
            $this->assertTrue($last < $first && $first < $middle, "{$url}: Last, First, Middle");
        }
    }
}
