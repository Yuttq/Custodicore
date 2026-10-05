<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * POST /api/auth/login is the mobile (visitor) login. Staff accounts must
 * never receive a mobile Sanctum token through it, even with the right
 * password — they sign in through the web console instead.
 */
class MobileLoginRoleRestrictionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();

        Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'admin.warden',
            'email' => 'admin.warden@bjmp.gov.ph',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
    }

    public static function staffEmailProvider(): array
    {
        return [
            'Record Officer' => ['r.salcedo@bjmp.gov.ph'],
            'Front Desk Officer' => ['n.fernandez@bjmp.gov.ph'],
            'System Administrator/Warden' => ['admin.warden@bjmp.gov.ph'],
        ];
    }

    #[DataProvider('staffEmailProvider')]
    public function test_staff_with_correct_password_cannot_get_a_mobile_token(string $email): void
    {
        $response = $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(Account::where('email', $email)->first()->last_login_at);
    }

    #[DataProvider('staffEmailProvider')]
    public function test_staff_with_wrong_password_gets_the_generic_error(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Invalid email or password.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_active_visitor_still_receives_a_token(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'maria.santos@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'Visitor')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotNull($this->visitorAccount->fresh()->last_login_at);
    }

    public function test_inactive_visitor_is_still_rejected(): void
    {
        $this->visitorAccount->update(['status' => 'suspended']);

        $this->postJson('/api/auth/login', ['email' => 'maria.santos@example.com', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
