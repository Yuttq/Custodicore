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
 * Nobody is admitted on an acquitted or dismissed case, and a PDL whose cases
 * are all closed but who is still in custody gets a "mark as Released" prompt
 * (release stays manual: it waits for the court's release order).
 */
class PdlClosedCasesTest extends TestCase
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

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'date_of_birth' => '1991-05-05',
            'gender' => 'male',
            'admission_date' => now()->subMonth()->toDateString(),
            'cell_block' => 'Dorm 1',
            'photo' => $this->fakePhoto(),
            'case_number' => 'CR-2026-0001',
            'offense_code' => 'other',
            'offense_other' => 'Theft of a motorcycle',
        ], $overrides);
    }

    public function test_register_pdl_offers_only_open_case_statuses(): void
    {
        $this->get('/pdls/create')->assertOk()
            ->assertSee('<option value="Pending trial"', false)
            ->assertSee('<option value="Convicted"', false)
            ->assertDontSee('<option value="Acquitted"', false)
            ->assertDontSee('<option value="Dismissed"', false);
    }

    public function test_register_pdl_refuses_a_closed_case(): void
    {
        foreach (PdlLegalRecord::CLOSED_STATUSES as $status) {
            $this->post('/pdls', $this->registration(['case_status' => $status]))->assertSessionHasErrors('case_status');
        }
        $this->assertSame(0, Pdl::where('last_name', 'Dela Cruz')->count());

        $this->post('/pdls', $this->registration(['case_status' => PdlLegalRecord::ON_APPEAL, 'convicted_on' => '2025-06-01', 'sentence_years' => 6]))
            ->assertSessionHasNoErrors();
    }

    public function test_re_admit_refuses_a_closed_new_case(): void
    {
        $this->post('/pdls', $this->registration())->assertSessionHasNoErrors();
        $pdl = Pdl::where('last_name', 'Dela Cruz')->sole();
        $pdl->update(['custody_status' => 'released']);

        $this->post(route('pdl.readmit', $pdl->pdl_id), [
            'admission_date' => now()->toDateString(), 'cell_block' => 'Dorm 1',
            'new_case' => '1', 'case_number' => 'CR-2026-0002', 'offense_code' => 'other', 'offense_other' => 'Robbery',
            'case_status' => PdlLegalRecord::DISMISSED, 'current_password' => 'password',
        ])->assertSessionHasErrors('case_status');
        $this->assertSame('released', $pdl->fresh()->custody_status);
    }

    public function test_add_legal_record_still_allows_closed_statuses(): void
    {
        $this->post('/pdls', $this->registration())->assertSessionHasNoErrors();
        $pdl = Pdl::where('last_name', 'Dela Cruz')->sole();

        // e.g. recording an older case that was dismissed
        $this->post(route('pdl.legal-records.store', $pdl->pdl_id), [
            'case_number' => 'CR-2019-0007', 'offense_code' => 'other', 'offense_other' => 'Slight physical injuries',
            'case_status' => PdlLegalRecord::DISMISSED,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $pdl->legalRecords()->count());
    }

    public function test_closing_the_last_open_case_prompts_release(): void
    {
        $this->post('/pdls', $this->registration())->assertSessionHasNoErrors();
        $pdl = Pdl::where('last_name', 'Dela Cruz')->sole();
        $case = $pdl->legalRecords()->sole();

        $this->get(route('pdl.show', $pdl->pdl_id))->assertDontSee('All of this PDL', false);

        $this->patch(route('pdl.legal-records.update', [$pdl->pdl_id, $case->legal_record_id]), ['case_status' => PdlLegalRecord::DISMISSED])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'cases are now closed'));

        // Still in custody: nothing is released automatically.
        $this->assertSame('active', $pdl->fresh()->custody_status);
        $this->get(route('pdl.show', $pdl->pdl_id))
            ->assertSee('All of this PDL', false)
            ->assertSee('Mark as Released');

        // Once released, the prompt is gone.
        $pdl->update(['custody_status' => 'released']);
        $this->get(route('pdl.show', $pdl->pdl_id))->assertDontSee('All of this PDL', false);
    }

    public function test_no_prompt_while_another_case_is_open(): void
    {
        $this->post('/pdls', $this->registration())->assertSessionHasNoErrors();
        $pdl = Pdl::where('last_name', 'Dela Cruz')->sole();
        $first = $pdl->legalRecords()->sole();
        $this->post(route('pdl.legal-records.store', $pdl->pdl_id), [
            'case_number' => 'CR-2026-0002', 'offense_code' => 'other', 'offense_other' => 'Estafa',
        ])->assertSessionHasNoErrors();

        $this->patch(route('pdl.legal-records.update', [$pdl->pdl_id, $first->legal_record_id]), ['case_status' => PdlLegalRecord::ACQUITTED])
            ->assertSessionHas('success', fn ($m) => ! str_contains($m, 'cases are now closed'));
        $this->get(route('pdl.show', $pdl->pdl_id))->assertDontSee('All of this PDL', false);
    }
}
