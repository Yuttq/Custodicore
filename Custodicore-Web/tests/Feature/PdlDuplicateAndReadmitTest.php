<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CellBlock;
use App\Models\Pdl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Same name + date of birth as a PDL on file: Register PDL shows that record
 * and offers re-admitting it; a different person can still be registered
 * after the officer confirms it (with a reason in the audit log). The
 * suffix (Jr., Sr. …) tells a father and son apart.
 */
class PdlDuplicateAndReadmitTest extends TestCase
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
        CellBlock::create(['name' => 'Dorm 2', 'capacity' => 10, 'designation' => 'any']);
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
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

    private function registerFirst(): Pdl
    {
        $this->post('/pdls', $this->registration())->assertSessionHasNoErrors();

        return Pdl::where('full_name', 'Juan Santos Dela Cruz')->sole();
    }

    public function test_suffix_is_saved_and_added_to_the_full_name(): void
    {
        $this->post('/pdls', $this->registration(['suffix' => 'jr']))->assertSessionHasNoErrors();

        $pdl = Pdl::where('full_name', 'Juan Santos Dela Cruz Jr.')->sole();
        $this->assertSame('Jr.', $pdl->suffix);
        $this->assertSame('Jr.', $pdl->nameParts()['suffix']);
    }

    public function test_unknown_suffix_is_refused(): void
    {
        $this->post('/pdls', $this->registration(['suffix' => 'Esq.']))->assertSessionHasErrors('suffix');
    }

    public function test_same_name_and_birthday_is_refused_and_the_existing_record_is_shown(): void
    {
        $first = $this->registerFirst();

        $this->from('/pdls/create')
            ->post('/pdls', $this->registration(['first_name' => 'JUAN', 'case_number' => 'CR-2026-0002']))
            ->assertRedirect('/pdls/create')
            ->assertSessionHasErrors('first_name')
            ->assertSessionHas('duplicate_pdl_id', $first->pdl_id);

        $this->assertSame(1, Pdl::whereDate('date_of_birth', '1991-05-05')->count());

        $this->get('/pdls/create')
            ->assertOk()
            ->assertSee('This PDL may already be registered')
            ->assertSee($first->pdl_number)
            ->assertSee('already in custody');
    }

    public function test_a_suffix_makes_it_a_different_name(): void
    {
        $this->registerFirst();

        $this->post('/pdls', $this->registration(['suffix' => 'Jr.', 'case_number' => 'CR-2026-0002']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Pdl::whereDate('date_of_birth', '1991-05-05')->count());
    }

    public function test_released_match_offers_re_admission(): void
    {
        $first = $this->registerFirst();
        $first->update(['custody_status' => 'released']);

        $this->from('/pdls/create')->post('/pdls', $this->registration(['case_number' => 'CR-2026-0002']));

        $this->get('/pdls/create')
            ->assertSee('Re-admit ' . $first->pdl_number)
            ->assertSee(route('pdl.show', ['pdl' => $first->pdl_id, 'readmit' => 1]), false);
    }

    public function test_override_needs_a_reason(): void
    {
        $this->registerFirst();

        $this->post('/pdls', $this->registration(['case_number' => 'CR-2026-0002', 'not_duplicate' => '1', 'duplicate_reason' => '']))
            ->assertSessionHasErrors('duplicate_reason');
        $this->post('/pdls', $this->registration(['case_number' => 'CR-2026-0002', 'not_duplicate' => '1', 'duplicate_reason' => 'short']))
            ->assertSessionHasErrors('duplicate_reason');

        $this->assertSame(1, Pdl::whereDate('date_of_birth', '1991-05-05')->count());
    }

    public function test_confirmed_different_person_is_registered_and_audited(): void
    {
        $first = $this->registerFirst();

        $this->post('/pdls', $this->registration([
            'case_number' => 'CR-2026-0002',
            'not_duplicate' => '1',
            'duplicate_reason' => 'Different face in the photo and different parents on the commitment order',
        ]))->assertSessionHasNoErrors();

        $second = Pdl::where('full_name', 'Juan Santos Dela Cruz')->where('pdl_id', '!=', $first->pdl_id)->sole();
        $log = AuditLog::where('record_type', 'pdl_profiles')->where('record_id', $second->pdl_id)
            ->where('description', 'like', 'Duplicate check overridden%')->sole();
        $this->assertStringContainsString($first->pdl_number, $log->description);
        $this->assertStringContainsString('different parents', $log->description);

        // Both records can still be edited without tripping the check.
        $this->put(route('pdl.update', $second->pdl_id), [
            'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz',
            'date_of_birth' => '1991-05-05', 'gender' => 'male', 'alias' => 'Bunso',
            'admission_date' => $second->admission_date->toDateString(), 'cell_block' => 'Dorm 1',
            'custody_status' => 'active', 'current_password' => 'password',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Bunso', $second->fresh()->alias);
    }

    public function test_editing_a_name_into_a_match_is_refused(): void
    {
        $this->registerFirst();
        $this->post('/pdls', $this->registration(['first_name' => 'Pedro', 'case_number' => 'CR-2026-0002']))->assertSessionHasNoErrors();
        $pedro = Pdl::where('first_name', 'Pedro')->sole();

        $this->put(route('pdl.update', $pedro->pdl_id), [
            'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz',
            'date_of_birth' => '1991-05-05', 'gender' => 'male',
            'admission_date' => $pedro->admission_date->toDateString(), 'cell_block' => 'Dorm 1',
            'custody_status' => 'active', 'current_password' => 'password',
        ])->assertSessionHasErrors('first_name');

        $this->assertSame('Pedro', $pedro->fresh()->first_name);
    }

    // --- Re-admit --------------------------------------------------------

    private function releasedPdl(): Pdl
    {
        $pdl = $this->registerFirst();
        $pdl->update(['custody_status' => 'released', 'admission_date' => '2025-01-10']);

        return $pdl->fresh();
    }

    public function test_re_admit_with_a_new_case_keeps_the_record(): void
    {
        $pdl = $this->releasedPdl();
        $today = now()->toDateString();

        $this->post(route('pdl.readmit', $pdl->pdl_id), [
            'admission_date' => $today,
            'cell_block' => 'Dorm 2',
            'new_case' => '1',
            'case_number' => 'cr-2026-0099',
            'offense_code' => 'ra9165_sale',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('pdl.show', $pdl->pdl_id));

        $pdl->refresh();
        $this->assertSame('active', $pdl->custody_status);
        $this->assertSame($today, $pdl->admission_date->toDateString());
        $this->assertSame('Dorm 2', $pdl->cell_block);
        $this->assertSame(2, $pdl->legalRecords()->count());
        $this->assertSame('drug_related', $pdl->classification);
        $this->assertSame(2, Pdl::count()); // no new record (the other one is the fixture PDL)

        $log = AuditLog::where('record_type', 'pdl_custody_status')->where('record_id', $pdl->pdl_id)->latest('audit_log_id')->first();
        $this->assertStringContainsString('Re-admitted', $log->description);
        $this->assertStringContainsString('Jan 10, 2025', $log->description);
        $this->assertStringContainsString('CR-2026-0099', $log->description);
    }

    public function test_re_admit_without_a_new_case(): void
    {
        $pdl = $this->releasedPdl();
        $pdl->update(['custody_status' => 'transferred']);

        $this->post(route('pdl.readmit', $pdl->pdl_id), [
            'admission_date' => now()->toDateString(),
            'cell_block' => 'Dorm 1',
            'case_number' => 'ignored when no new case',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertSame('active', $pdl->fresh()->custody_status);
        $this->assertSame(1, $pdl->legalRecords()->count());
    }

    public function test_re_admit_checks_password_dates_and_status(): void
    {
        $pdl = $this->releasedPdl();
        $base = ['admission_date' => now()->toDateString(), 'cell_block' => 'Dorm 1'];

        $this->post(route('pdl.readmit', $pdl->pdl_id), [...$base, 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->post(route('pdl.readmit', $pdl->pdl_id), [...$base, 'admission_date' => '2024-12-01', 'current_password' => 'password'])
            ->assertSessionHasErrors('admission_date');
        $this->post(route('pdl.readmit', $pdl->pdl_id), [...$base, 'cell_block' => '', 'current_password' => 'password'])
            ->assertSessionHasErrors('cell_block');
        $this->post(route('pdl.readmit', $pdl->pdl_id), [...$base, 'new_case' => '1', 'current_password' => 'password'])
            ->assertSessionHasErrors(['case_number', 'offense_code']);
        $this->assertSame('released', $pdl->fresh()->custody_status);

        // Already in custody, or deceased: nothing to re-admit.
        foreach (['active', 'deceased'] as $status) {
            $pdl->update(['custody_status' => $status]);
            $this->post(route('pdl.readmit', $pdl->pdl_id), [...$base, 'current_password' => 'password'])
                ->assertSessionHas('error');
            $this->assertSame($status, $pdl->fresh()->custody_status);
        }
    }

    public function test_profile_page_shows_the_re_admit_form_only_when_allowed(): void
    {
        $pdl = $this->releasedPdl();

        $this->get(route('pdl.show', ['pdl' => $pdl->pdl_id, 'readmit' => 1]))
            ->assertOk()
            ->assertSee('Re-admit ' . $pdl->full_name)
            ->assertSee(route('pdl.readmit', $pdl->pdl_id), false);

        $pdl->update(['custody_status' => 'active']);
        $this->get(route('pdl.show', ['pdl' => $pdl->pdl_id, 'readmit' => 1]))
            ->assertOk()
            ->assertDontSee(route('pdl.readmit', $pdl->pdl_id), false);
    }
}
