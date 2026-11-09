<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\StaffProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Admin → Users → Register Officer must refuse an email that already belongs
 * to any account (staff or visitor) or any officer's personal email — whatever
 * its capitals or surrounding spaces — and save nothing. The form's live check
 * (GET admin/users/check-email) gives the same answer before submitting.
 */
class StaffEmailDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private Account $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);
        $this->withoutVite();
        Mail::fake();

        $this->admin = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'warden',
            'email' => 'warden@example.com',
            'password_hash' => Hash::make('Correct-admin-pass1'),
            'status' => 'active',
        ]);

        $officer = Account::create([
            'role_id' => Role::where('role_name', 'Record Officer')->value('role_id'),
            'username' => 'juancruz',
            'email' => 'juan.cruz@example.com',
            'password_hash' => Hash::make('officer-pass'),
            'status' => 'active',
        ]);
        StaffProfile::create([
            'account_id' => $officer->account_id,
            'employee_number' => 'EMP-100',
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'position' => 'Record Officer',
            'contact_number' => '09171234567',
            'personal_email' => 'juan.cruz@example.com',
        ]);

        Account::create([
            'role_id' => Role::where('role_name', 'Visitor')->value('role_id'),
            'username' => 'maria',
            'email' => 'maria.visitor@example.com',
            'password_hash' => Hash::make('visitor-pass'),
            'status' => 'active',
        ]);
    }

    private function register(string $email)
    {
        return $this->actingAs($this->admin, 'web')->post(route('admin.users.store'), [
            'first_name' => 'Pedro',
            'last_name' => 'Santos',
            'role_name' => 'Front Desk Officer',
            'contact_number' => '09181234567',
            'personal_email' => $email,
        ]);
    }

    public function test_an_email_already_used_by_staff_is_refused_and_nothing_is_saved(): void
    {
        $before = Account::count();

        $this->register('  JUAN.Cruz@Example.com ')->assertSessionHasErrors('personal_email');

        $this->assertSame($before, Account::count());
        $this->assertDatabaseMissing('staff_profiles', ['last_name' => 'Santos']);
    }

    public function test_an_email_already_used_by_a_visitor_is_refused(): void
    {
        $before = Account::count();

        $this->register('maria.visitor@example.com')->assertSessionHasErrors('personal_email');

        $this->assertSame($before, Account::count());
    }

    public function test_a_new_email_is_saved(): void
    {
        $this->register('pedro.santos@example.com')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('accounts', ['email' => 'pedro.santos@example.com']);
    }

    public function test_live_check_reports_taken_and_free_emails(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson(route('admin.users.check-email', ['email' => 'Juan.Cruz@example.com']))
            ->assertOk()->assertJson(['available' => false]);

        $this->actingAs($this->admin, 'web')
            ->getJson(route('admin.users.check-email', ['email' => 'someone.new@example.com']))
            ->assertOk()->assertJson(['available' => true]);
    }

    public function test_live_check_ignores_the_officer_being_edited(): void
    {
        $officerId = Account::where('email', 'juan.cruz@example.com')->value('account_id');

        $this->actingAs($this->admin, 'web')
            ->getJson(route('admin.users.check-email', ['email' => 'juan.cruz@example.com', 'ignore' => $officerId]))
            ->assertOk()->assertJson(['available' => true]);
    }
}
