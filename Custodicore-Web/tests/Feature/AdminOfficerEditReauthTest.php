<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\StaffProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Admin → Users: editing an officer, or switching their account on/off,
 * needs the admin's OWN password (`reauth` middleware, ConfirmActorPassword).
 * A wrong or missing password must save nothing, and the reopened edit
 * window must say so (it used to reopen silently, which looked like a save).
 *
 * A successful status change with the correct password is already covered by
 * DeactivatedAccountAccessTest (toggle()).
 */
class AdminOfficerEditReauthTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PASSWORD = 'Correct-admin-pass1';
    private const WRONG_MESSAGE = 'Incorrect password. No changes were saved. Please try again.';

    private Account $admin;
    private Account $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);
        $this->withoutVite();

        $this->admin = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'warden',
            'email' => 'warden@example.com',
            'password_hash' => Hash::make(self::ADMIN_PASSWORD),
            'status' => 'active',
        ]);

        $this->officer = Account::create([
            'role_id' => Role::where('role_name', 'Record Officer')->value('role_id'),
            'username' => 'juancruz',
            'email' => 'juan.cruz@example.com',
            'password_hash' => Hash::make('officer-pass'),
            'status' => 'active',
        ]);

        StaffProfile::create([
            'account_id' => $this->officer->account_id,
            'employee_number' => 'EMP-100',
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'position' => 'Record Officer',
            'contact_number' => '09171234567',
            'personal_email' => 'juan.cruz@example.com',
        ]);
    }

    /** The Edit form as the browser sends it, with the first name changed. */
    private function editForm(array $extra = []): array
    {
        return array_merge([
            '_edit_id' => $this->officer->account_id,
            'first_name' => 'Pedro',
            'last_name' => 'Cruz',
            'role_name' => 'Record Officer',
            'contact_number' => '09171234567',
            'personal_email' => 'juan.cruz@example.com',
        ], $extra);
    }

    private function update(array $data)
    {
        return $this->actingAs($this->admin, 'web')
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.update', $this->officer->account_id), $data);
    }

    private function firstName(): string
    {
        return (string) $this->officer->staffProfile()->value('first_name');
    }

    // ------------------------------------------------------------ Update

    public function test_incorrect_password_rejects_the_update_and_saves_nothing(): void
    {
        $this->update($this->editForm(['current_password' => 'not-my-password']))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('current_password')
            ->assertSessionMissing('status');

        $this->assertSame('Juan', $this->firstName());
        $this->assertDatabaseMissing('audit_logs', ['record_id' => $this->officer->account_id, 'action' => 'update']);
    }

    public function test_missing_password_rejects_the_update_and_saves_nothing(): void
    {
        $this->update($this->editForm())
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('current_password');

        $this->assertSame('Juan', $this->firstName());
    }

    public function test_correct_password_saves_the_update(): void
    {
        $this->update($this->editForm(['current_password' => self::ADMIN_PASSWORD]))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame('Pedro', $this->firstName());
    }

    public function test_json_request_with_incorrect_password_gets_422_and_saves_nothing(): void
    {
        $this->actingAs($this->admin, 'web')
            ->patchJson(route('admin.users.update', $this->officer->account_id), $this->editForm(['current_password' => 'nope']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertSame('Juan', $this->firstName());
    }

    public function test_reopened_edit_window_says_nothing_was_saved_and_keeps_the_typed_changes(): void
    {
        $page = $this->actingAs($this->admin, 'web')
            ->from(route('admin.users.index'))
            ->followingRedirects()
            ->patch(route('admin.users.update', $this->officer->account_id), $this->editForm(['current_password' => 'wrong']));

        $page->assertOk()
            ->assertSee(self::WRONG_MESSAGE)
            ->assertSee('role="alert" data-reauth-error', false)
            // The admin's typed change is still in the form so they can retry...
            ->assertSee('value="Pedro"', false)
            // ...and the edit window for that officer is reopened.
            ->assertSee("edit-account-modal-{$this->officer->account_id}');", false);

        $this->assertSame('Juan', $this->firstName());
    }

    public function test_other_validation_errors_still_show_their_own_list_without_the_password_notice(): void
    {
        $page = $this->actingAs($this->admin, 'web')
            ->from(route('admin.users.index'))
            ->followingRedirects()
            ->patch(route('admin.users.update', $this->officer->account_id), $this->editForm([
                'first_name' => '',
                'current_password' => self::ADMIN_PASSWORD,
            ]));

        $page->assertOk()
            ->assertDontSee(self::WRONG_MESSAGE)
            ->assertDontSee('role="alert" data-reauth-error', false);

        $this->assertSame('Juan', $this->firstName());
    }

    public function test_too_many_wrong_passwords_shows_the_lockout_message_instead(): void
    {
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit('reauth:' . $this->admin->account_id, 300);
        }

        $this->actingAs($this->admin, 'web')
            ->from(route('admin.users.index'))
            ->followingRedirects()
            ->patch(route('admin.users.update', $this->officer->account_id), $this->editForm(['current_password' => self::ADMIN_PASSWORD]))
            ->assertSee('Too many incorrect password attempts')
            ->assertDontSee(self::WRONG_MESSAGE);

        $this->assertSame('Juan', $this->firstName());
    }

    // ------------------------------------------------------------ Status change

    public function test_incorrect_password_rejects_a_status_change(): void
    {
        $this->actingAs($this->admin, 'web')
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.toggle-status', $this->officer->account_id), ['current_password' => 'wrong'])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('current_password');

        $this->assertSame('active', $this->officer->fresh()->status);
    }

    public function test_missing_password_rejects_a_status_change(): void
    {
        $this->actingAs($this->admin, 'web')
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.toggle-status', $this->officer->account_id))
            ->assertSessionHasErrors('current_password');

        $this->assertSame('active', $this->officer->fresh()->status);
    }
}
