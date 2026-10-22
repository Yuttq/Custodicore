<?php

namespace Tests\Feature;

use App\Models\CellBlock;
use App\Models\Pdl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Record Officer assigns PDLs to cell blocks picked from a managed list
 * with capacity, instead of typing free text.
 */
class CellBlockAssignmentTest extends TestCase
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
    }

    private function newPdl(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'date_of_birth' => '1991-05-05',
            'gender' => 'male',
            'classification' => 'drug_related',
            'admission_date' => '2026-10-01',
            'photo' => $this->fakePhoto(),
            'case_number' => 'CR-2026-0001',
            'offense_code' => 'theft',
        ], $overrides);
    }

    private function occupy(CellBlock $block, int $count, string $status = 'active'): void
    {
        for ($i = 0; $i < $count; $i++) {
            Pdl::create([
                'pdl_number' => "PDL-OCC-{$block->cell_block_id}-{$status}-{$i}",
                'full_name' => "Occupant {$i}",
                'date_of_birth' => '1990-01-01',
                'gender' => 'male',
                'classification' => 'drug_related',
                'cell_block' => $block->name,
                'admission_date' => '2024-01-01',
                'custody_status' => $status,
                'registered_by' => $this->pdl->registered_by,
            ]);
        }
    }

    public function test_register_form_is_a_dropdown_that_flags_dorms_over_capacity(): void
    {
        $open = CellBlock::create(['name' => 'Dorm 1', 'capacity' => 3, 'designation' => 'any']);
        $over = CellBlock::create(['name' => 'Dorm 2', 'capacity' => 1, 'designation' => 'any']);
        CellBlock::create(['name' => 'Old Wing', 'capacity' => 5, 'designation' => 'any', 'is_active' => false]);
        $this->occupy($open, 1);
        $this->occupy($over, 3);

        $this->get('/pdls/create')
            ->assertOk()
            ->assertSee('<select id="cell_block" name="cell_block"', false)
            // Count, recommended number and space left; most space first, dorms over it last.
            ->assertSeeInOrder(['Dorm 1 — 1/3 · 2 spaces left', 'Dorm 2 — 3/1 · ⚠ over by 2'])
            ->assertSee('data-exceeds="1"', false)   // picking Dorm 2 shows the yellow warning
            ->assertSee('data-dorm-card', false)     // summary card under the dropdown
            ->assertDontSee('Old Wing');
    }

    public function test_dropdown_carries_the_visiting_schedule_the_pdl_will_get(): void
    {
        $own = CellBlock::create(['name' => 'Dorm 1', 'capacity' => 20, 'designation' => 'any']);
        CellBlock::create(['name' => 'Dorm 2', 'capacity' => 20, 'designation' => 'any']);
        \App\Models\FacilityVisitationRule::create([
            'cell_block_id' => $own->cell_block_id, 'day_of_week' => 'mon',
            'time_slot_start' => '08:00', 'time_slot_end' => '11:30', 'max_capacity' => 10, 'effective_from' => '2024-01-01',
        ]);
        \App\Models\FacilityVisitationRule::create([
            'pdl_classification' => 'drug_related', 'day_of_week' => 'thu',
            'time_slot_start' => '09:00', 'time_slot_end' => '11:30', 'max_capacity' => 30, 'effective_from' => '2024-01-01',
        ]);

        $this->get('/pdls/create')->assertOk()
            // A dorm with its own hours carries them; others follow the classification.
            ->assertSee('data-schedule="Mon 08:00–11:30"', false)
            ->assertSee('data-schedule=""', false)
            ->assertSee('data-schedule-drug="Thu 09:00–11:30"', false);
    }

    public function test_pdl_can_be_registered_into_a_block_with_space(): void
    {
        CellBlock::create(['name' => 'Dorm 1', 'capacity' => 2, 'designation' => 'any']);

        $this->post('/pdls', $this->newPdl(['cell_block' => 'Dorm 1']))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pdl_profiles', ['full_name' => 'Juan Dela Cruz', 'cell_block' => 'Dorm 1']);
    }

    public function test_full_block_still_accepts_pdls_because_capacity_is_a_recommendation(): void
    {
        $block = CellBlock::create(['name' => 'Dorm 1', 'capacity' => 1, 'designation' => 'any']);
        $this->occupy($block, 1);

        $this->post('/pdls', $this->newPdl(['cell_block' => 'Dorm 1']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pdl_profiles', ['full_name' => 'Juan Dela Cruz', 'cell_block' => 'Dorm 1']);
        $this->assertTrue($block->fresh()->isOver());
        $this->assertSame('Over by 1', $block->fresh()->occupancyLabel());
    }

    public function test_released_pdls_do_not_take_up_a_bed(): void
    {
        $block = CellBlock::create(['name' => 'Dorm 1', 'capacity' => 1, 'designation' => 'any']);
        $this->occupy($block, 2, 'released');

        $this->post('/pdls', $this->newPdl(['cell_block' => 'Dorm 1']))->assertSessionHasNoErrors();
    }

    public function test_unknown_inactive_and_wrong_gender_blocks_are_rejected(): void
    {
        CellBlock::create(['name' => 'Old Wing', 'capacity' => 5, 'designation' => 'any', 'is_active' => false]);
        CellBlock::create(['name' => 'Dorm 5 (F)', 'capacity' => 5, 'designation' => 'female']);

        $this->post('/pdls', $this->newPdl(['cell_block' => 'Block Z']))->assertSessionHasErrors('cell_block');
        $this->post('/pdls', $this->newPdl(['cell_block' => 'Old Wing']))->assertSessionHasErrors('cell_block');
        $this->post('/pdls', $this->newPdl(['cell_block' => 'Dorm 5 (F)']))->assertSessionHasErrors('cell_block');
    }

    public function test_cell_block_is_required_when_registering(): void
    {
        $this->post('/pdls', $this->newPdl())->assertSessionHasErrors('cell_block');
        $this->assertDatabaseMissing('pdl_profiles', ['full_name' => 'Juan Dela Cruz']);
    }

    public function test_name_is_registered_as_separate_parts_with_optional_middle_name(): void
    {
        CellBlock::create(['name' => 'Dorm 1', 'capacity' => 5, 'designation' => 'any']);

        $this->post('/pdls', $this->newPdl(['middle_name' => 'Santos', 'cell_block' => 'Dorm 1']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pdl_profiles', [
            'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz',
            'full_name' => 'Juan Santos Dela Cruz',
        ]);

        $this->post('/pdls', $this->newPdl(['first_name' => 'Pedro', 'cell_block' => 'Dorm 1']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pdl_profiles', ['first_name' => 'Pedro', 'middle_name' => null, 'full_name' => 'Pedro Dela Cruz']);

        $this->post('/pdls', $this->newPdl(['first_name' => '', 'last_name' => '', 'cell_block' => 'Dorm 1']))
            ->assertSessionHasErrors(['first_name', 'last_name']);
    }

    public function test_edit_form_prefills_a_guess_for_older_full_name_only_records(): void
    {
        $this->pdl->update(['full_name' => 'Maria Clara Dela Paz']);

        $this->get("/pdls/{$this->pdl->pdl_id}")
            ->assertOk()
            ->assertSee('value="Maria"', false)
            ->assertSee('value="Clara Dela"', false)
            ->assertSee('value="Paz"', false)
            ->assertSee('best guess');
    }

    public function test_editing_a_pdl_in_a_full_block_keeps_their_bed(): void
    {
        $block = CellBlock::create(['name' => 'Dorm 1', 'capacity' => 1, 'designation' => 'any']);
        $this->pdl->update(['cell_block' => 'Dorm 1']);

        $this->put("/pdls/{$this->pdl->pdl_id}", [
            'first_name' => 'Renamed',
            'last_name' => 'Santos', // names are case-normalized (Sanitizer::name), so avoid ALL CAPS here
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'cell_block' => 'Dorm 1',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed Santos', $this->pdl->fresh()->full_name);
        $this->assertSame(1, $block->fresh()->occupied());
    }

    public function test_moving_a_pdl_into_a_full_block_is_allowed(): void
    {
        $full = CellBlock::create(['name' => 'Dorm 2', 'capacity' => 1, 'designation' => 'any']);
        $this->occupy($full, 1);

        $this->put("/pdls/{$this->pdl->pdl_id}", [
            'first_name' => 'Example',
            'last_name' => 'PDL',
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'cell_block' => 'Dorm 2',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Dorm 2', $this->pdl->fresh()->cell_block);
    }

    public function test_record_officer_manages_cell_blocks(): void
    {
        $this->get('/cell-blocks')->assertOk()->assertSee('Add Cell Block');

        $this->post('/cell-blocks', ['name' => 'Dorm 6', 'capacity' => 10, 'designation' => 'male'])
            ->assertSessionHasNoErrors();
        $block = CellBlock::where('name', 'Dorm 6')->firstOrFail();

        $this->occupy($block, 3);

        // Capacity may be set below the headcount; the dorm then shows as over capacity.
        $this->put("/cell-blocks/{$block->cell_block_id}", [
            'name' => 'Dorm 6', 'capacity' => 2, 'designation' => 'male', 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $block->fresh()->capacity);
        $this->get('/cell-blocks')->assertSee('Over by 1')->assertSee('row-over', false);

        // Renaming carries over to the PDLs already in the block.
        $this->put("/cell-blocks/{$block->cell_block_id}", [
            'name' => 'Dorm 6A', 'capacity' => 12, 'designation' => 'male', 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, Pdl::where('cell_block', 'Dorm 6A')->count());
        $this->assertSame(0, Pdl::where('cell_block', 'Dorm 6')->count());
    }

    public function test_other_roles_cannot_manage_cell_blocks(): void
    {
        $this->actingAs($this->frontDeskAccount, 'web');

        $this->get('/cell-blocks')->assertForbidden();
    }

    private function giveCase(Pdl $pdl, string $caseNumber): void
    {
        \App\Models\PdlLegalRecord::create([
            'pdl_id' => $pdl->pdl_id, 'case_number' => $caseNumber, 'offense_code' => 'theft',
            'offense' => 'Theft', 'case_status' => 'pending', 'recorded_by' => $this->recordOfficerAccount->getKey(),
        ]);
    }

    public function test_picker_knows_co_accused_in_custody_by_case_number(): void
    {
        $dorm = CellBlock::create(['name' => 'Dorm 1', 'capacity' => 20, 'designation' => 'any']);
        $this->occupy($dorm, 1);
        $coAccused = Pdl::where('cell_block', 'Dorm 1')->firstOrFail();
        $this->giveCase($coAccused, 'CR-2026-0012');

        // Someone released on the same case is no longer a concern.
        $this->occupy($dorm, 1, 'released');
        $this->giveCase(Pdl::where('custody_status', 'released')->firstOrFail(), 'CR-2026-0012');

        $map = $this->coAccusedMapOn('/pdls/create');
        $this->assertSame([['pdl_id' => $coAccused->pdl_id, 'name' => $coAccused->full_name, 'dorm' => 'Dorm 1']], $map['CR-2026-0012']);
    }

    public function test_edit_page_checks_the_pdls_own_cases_and_leaves_them_out(): void
    {
        $dorm = CellBlock::create(['name' => 'Dorm 1', 'capacity' => 20, 'designation' => 'any']);
        $this->pdl->update(['cell_block' => 'Dorm 1', 'custody_status' => 'active']);
        $this->giveCase($this->pdl, 'cr-2026-0012 ');

        $html = $this->get("/pdls/{$this->pdl->pdl_id}")->assertOk()->getContent();
        // Stored as typed ("cr-2026-0012 "), compared normalized.
        $this->assertStringContainsString('data-own-cases=\'["CR-2026-0012"]\'', $html);
        $this->assertStringContainsString('data-self-id="' . $this->pdl->pdl_id . '"', $html);
    }

    /** The co-accused lookup the Cell/Block picker on $url carries. */
    private function coAccusedMapOn(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertSame(1, preg_match("#data-coaccused='([^']*)'#", $html, $m));

        return json_decode(html_entity_decode($m[1]), true);
    }

    public function test_dorm_can_be_marked_for_a_classification_and_still_takes_either(): void
    {
        $this->post('/cell-blocks', ['name' => 'Dorm 7', 'capacity' => 20, 'designation' => 'any', 'intended_classification' => 'drug_related'])
            ->assertSessionHasNoErrors();
        $block = CellBlock::where('name', 'Dorm 7')->firstOrFail();
        $this->assertSame('drug_related', $block->intended_classification);
        $this->assertSame('Drug-related cases', $block->intendedLabel());

        $this->get('/cell-blocks')->assertSee('Intended For')->assertSee('Drug-related cases');
        $this->get('/pdls/create')
            ->assertSee('Dorm 7 — 0/20 · 20 spaces left · Drug-related dorm')
            ->assertSee('data-intended="drug_related"', false);

        // Guidance only: a non-drug PDL (theft) can still be registered into it.
        $this->post('/pdls', $this->newPdl(['cell_block' => 'Dorm 7', 'first_name' => 'Pedro']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pdl_profiles', ['full_name' => 'Pedro Dela Cruz', 'cell_block' => 'Dorm 7', 'classification' => 'non_drug_related']);

        // And it can be set back to any case.
        $this->put("/cell-blocks/{$block->cell_block_id}", ['name' => 'Dorm 7', 'capacity' => 20, 'designation' => 'any', 'intended_classification' => '', 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $this->assertNull($block->fresh()->intended_classification);
        $this->post('/cell-blocks', ['name' => 'Dorm 8', 'capacity' => 20, 'designation' => 'any', 'intended_classification' => 'violent'])
            ->assertSessionHasErrors('intended_classification');
    }
}
