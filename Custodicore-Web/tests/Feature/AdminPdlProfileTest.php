<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PdlDisciplinaryRecord;
use App\Models\PdlRestriction;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/** The Warden's read-only PDL Profile renders for a PDL with records, visitors and a restriction. */
class AdminPdlProfileTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    public function test_admin_pdl_profile_renders_with_records(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->completeRequirements();
        $this->withoutVite();

        $incident = PdlDisciplinaryRecord::create([
            'pdl_id' => $this->pdl->pdl_id,
            'incident_date' => now()->toDateString(),
            'description' => 'Found with a mobile phone.',
            'action_taken' => 'Item confiscated',
            'recorded_by' => $this->pdl->registered_by,
        ]);
        PdlRestriction::create([
            'pdl_id' => $this->pdl->pdl_id,
            'disciplinary_record_id' => $incident->disciplinary_record_id,
            'restriction_type' => 'disciplinary',
            'status' => 'active',
            'start_date' => now()->toDateString(),
            'reason' => 'Contraband',
            'imposed_by' => $this->pdl->registered_by,
        ]);

        $role = Role::firstOrCreate(['role_name' => 'System Administrator/Warden'], ['description' => 'Admin']);
        $admin = Account::create([
            'role_id' => $role->role_id,
            'username' => 'warden',
            'email' => 'warden@bjmp.gov.ph',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'web')
            ->get(route('admin.pdls.show', $this->pdl->pdl_id))
            ->assertOk()
            ->assertSee($this->pdl->full_name)
            ->assertSee('Led to restriction');
    }
}
