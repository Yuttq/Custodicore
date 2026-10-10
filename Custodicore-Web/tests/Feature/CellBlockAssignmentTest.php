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

    public function test_register_form_lists_blocks_with_occupancy_and_disables_full_ones(): void
    {
        $open = CellBlock::create(['name' => 'Dorm 1', 'capacity' => 3, 'designation' => 'any']);
        $full = CellBlock::create(['name' => 'Dorm 2', 'capacity' => 1, 'designation' => 'any']);
        CellBlock::create(['name' => 'Old Wing', 'capacity' => 5, 'designation' => 'any', 'is_active' => false]);
        $this->occupy($open, 1);
        $this->occupy($full, 1);

        $this->get('/pdls/create')
            ->assertOk()
            ->assertSee('2 available')
            ->assertSee('type="radio" name="cell_block" value="Dorm 1"', false)
            ->assertSee('Full')
            ->assertDontSee('<select id="cell_block"', false)
            ->assertDontSee('Old Wing');
    }

    public function test_pdl_can_be_registered_into_a_block_with_space(): void
    {
        CellBlock::create(['name' => 'Dorm 1', 'capacity' => 2, 'designation' => 'any']);

        $this->post('/pdls', $this->newPdl(['cell_block' => 'Dorm 1']))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pdl_profiles', ['full_name' => 'Juan Dela Cruz', 'cell_block' => 'Dorm 1']);
    }

    public function test_full_block_is_rejected(): void
    {
        $block = CellBlock::create(['name' => 'Dorm 1', 'capacity' => 1, 'designation' => 'any']);
        $this->occupy($block, 1);

        $this->post('/pdls', $this->newPdl(['cell_block' => 'Dorm 1']))->assertSessionHasErrors('cell_block');
        $this->assertDatabaseMissing('pdl_profiles', ['full_name' => 'Juan Dela Cruz']);
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
            'last_name' => 'PDL',
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'cell_block' => 'Dorm 1',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed PDL', $this->pdl->fresh()->full_name);
        $this->assertSame(1, $block->fresh()->occupied());
    }

    public function test_moving_a_pdl_into_a_full_block_is_rejected(): void
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
        ])->assertSessionHasErrors('cell_block');

        $this->assertNull($this->pdl->fresh()->cell_block);
    }

    public function test_record_officer_manages_cell_blocks(): void
    {
        $this->get('/cell-blocks')->assertOk()->assertSee('Add Cell Block');

        $this->post('/cell-blocks', ['name' => 'Dorm 6', 'capacity' => 10, 'designation' => 'male'])
            ->assertSessionHasNoErrors();
        $block = CellBlock::where('name', 'Dorm 6')->firstOrFail();

        $this->occupy($block, 3);

        // Capacity can't drop below current occupants.
        $this->put("/cell-blocks/{$block->cell_block_id}", [
            'name' => 'Dorm 6', 'capacity' => 2, 'designation' => 'male', 'is_active' => 1,
        ])->assertSessionHas('error');
        $this->assertSame(10, $block->fresh()->capacity);

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
}
