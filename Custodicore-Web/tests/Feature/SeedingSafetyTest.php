<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FacilityVisitationRule;
use App\Models\Module;
use App\Models\Pdl;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\StaffProfile;
use App\Models\SystemSetting;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesTestImages;
use Tests\TestCase;

/**
 * Seeding never puts demo accounts or a shared password into production:
 * ReferenceDataSeeder holds the rows the app needs (no people), and
 * DatabaseSeeder adds demo data only outside production or with
 * SEED_DEMO_DATA=true, and falls back to "password" only in local/testing.
 */
class SeedingSafetyTest extends TestCase
{
    use MakesTestImages;
    use RefreshDatabase;

    /** Runs a seeder directly (artisan db:seed would ask for confirmation in production). */
    private function runSeeder(string $class): void
    {
        $this->app->make($class)->setContainer($this->app)->__invoke();
    }

    private function useEnvironment(string $env, ?string $password = null, bool $demo = false): void
    {
        $this->app['env'] = $env;
        config(['app.seed_password' => $password, 'app.seed_demo_data' => $demo]);
    }

    private function assertReferenceDataOnly(): void
    {
        $this->assertSame(array_keys(ReferenceDataSeeder::ROLES), Role::orderBy('role_id')->pluck('role_name')->all());
        $this->assertSame(9, Module::count());
        $this->assertSame(18, RolePermission::count());
        $this->assertSame(8, FacilityVisitationRule::count());

        $this->assertSame(0, Account::count());
        $this->assertSame(0, StaffProfile::count());
        $this->assertSame(0, VisitorProfile::count());
        $this->assertSame(0, Pdl::count());
        $this->assertSame(0, VisitRequest::count());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame(0, SystemSetting::count());
    }

    // ---------------------------------------------------------------- reference data

    public function test_reference_data_has_what_the_app_needs_and_no_people(): void
    {
        $this->runSeeder(ReferenceDataSeeder::class);

        $this->assertReferenceDataOnly();
        $this->assertTrue(FacilityVisitationRule::where(['pdl_classification' => 'drug_related', 'day_of_week' => 'thu', 'max_capacity' => 30])->exists());
        $warden = Role::where('role_name', 'System Administrator/Warden')->first();
        $this->assertSame(9, RolePermission::where('role_id', $warden->role_id)->count());
    }

    public function test_reference_data_can_be_seeded_again_without_changes(): void
    {
        $this->runSeeder(ReferenceDataSeeder::class);
        $before = [Role::pluck('role_id')->all(), Module::pluck('module_id')->all(), FacilityVisitationRule::pluck('rule_id')->all()];

        $this->runSeeder(ReferenceDataSeeder::class);

        $this->assertReferenceDataOnly();
        $this->assertSame($before, [Role::pluck('role_id')->all(), Module::pluck('module_id')->all(), FacilityVisitationRule::pluck('rule_id')->all()]);
    }

    public function test_visitor_registration_works_on_reference_data_alone(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->runSeeder(ReferenceDataSeeder::class);

        $this->postJson('/api/auth/register', [
            'firstName' => 'Juan Miguel',
            'lastName' => 'Dela Cruz',
            'fullName' => 'Juan Miguel Dela Cruz',
            'email' => 'juan.dela.cruz@example.com',
            'password' => 'Secret-pass-1',
            'password_confirmation' => 'Secret-pass-1',
            'dateOfBirth' => '1995-06-15',
            'gender' => 'male',
            'address' => 'Quezon City',
            'relationshipHint' => 'Spouse',
            'intendedPdlName' => 'Pedro Santos Reyes',
            'contactNumber' => '09170001111',
            'acceptedTerms' => true,
            'acceptedPrivacy' => true,
            'photo' => $this->fakePhoto(),
            'consentVersion' => (string) config('legal.version'),
        ])->assertCreated();

        $this->assertSame('Visitor', Account::where('email', 'juan.dela.cruz@example.com')->first()->role->role_name);
    }

    // ---------------------------------------------------------------- DatabaseSeeder guards

    public function test_production_seeds_reference_data_only(): void
    {
        $this->useEnvironment('production', 'Some-strong-demo-1');

        $this->runSeeder(DatabaseSeeder::class);

        $this->assertReferenceDataOnly();
    }

    public function test_production_demo_seeding_needs_seed_password(): void
    {
        $this->useEnvironment('production', null, demo: true);

        try {
            $this->runSeeder(DatabaseSeeder::class);
            $this->fail('Demo seeding without SEED_PASSWORD must stop.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SEED_PASSWORD', $e->getMessage());
        }

        $this->assertSame(0, Account::count());
    }

    public function test_production_demo_server_uses_its_own_password(): void
    {
        $this->useEnvironment('production', 'Demo-server-pass-1', demo: true);

        $this->runSeeder(DatabaseSeeder::class);

        $warden = Account::where('email', 'a.domingo@bjmp.gov.ph')->firstOrFail();
        $this->assertTrue(Hash::check('Demo-server-pass-1', $warden->password_hash));
        $this->assertFalse(Hash::check('password', $warden->password_hash));
    }

    public function test_other_environments_must_set_seed_password(): void
    {
        foreach (['staging', 'demo', 'prod'] as $env) {
            $this->useEnvironment($env);

            try {
                $this->runSeeder(DatabaseSeeder::class);
                $this->fail("{$env}: demo seeding without SEED_PASSWORD must stop.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('SEED_PASSWORD', $e->getMessage());
            }

            $this->assertSame(0, Account::count(), $env);
        }
    }

    public function test_local_development_still_seeds_demo_data_with_the_default_password(): void
    {
        $this->useEnvironment('local');

        $this->runSeeder(DatabaseSeeder::class);

        $warden = Account::where('email', 'a.domingo@bjmp.gov.ph')->firstOrFail();
        $this->assertSame('System Administrator/Warden', $warden->role->role_name);
        $this->assertTrue(Hash::check('password', $warden->password_hash));
        $this->assertSame(10, Account::count());
        $this->assertSame(5, Pdl::count());
        $this->assertSame(8, FacilityVisitationRule::count(), 'Reference rows are reused, not duplicated.');
        $this->assertSame(9, Module::count());
    }
}
