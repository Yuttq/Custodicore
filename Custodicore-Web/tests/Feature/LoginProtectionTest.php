<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Group 1B: failed-login lockout (5 failures / 15 minutes per normalized
 * email-or-username + IP) for the visitor API login and the staff web
 * login, plus the staff route's per-IP throttle. Account details are only
 * revealed after the right password.
 */
class LoginProtectionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const VISITOR_EMAIL = 'maria.santos@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
    }

    private function apiLogin(string $email, string $password, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function staffLogin(string $login, string $password, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from('/login')
            ->post('/login', ['email' => $login, 'password' => $password]);
    }

    private function failApiLogin(string $email, int $times, string $ip = '10.0.0.1'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->apiLogin($email, 'wrong-password', $ip)->assertStatus(422);
        }
    }

    // ---- Visitor API login -------------------------------------------------

    public function test_five_failures_lock_the_next_attempt_even_with_the_right_password(): void
    {
        $this->failApiLogin(self::VISITOR_EMAIL, 5);

        $this->apiLogin(self::VISITOR_EMAIL, 'password')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertJsonStructure(['code', 'message', 'retryAfter'])
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_and_known_emails_fail_the_same_way(): void
    {
        $known = $this->apiLogin(self::VISITOR_EMAIL, 'wrong-password');
        $unknown = $this->apiLogin('nobody@example.com', 'wrong-password');

        $known->assertStatus(422)->assertJsonValidationErrors(['email' => 'Invalid email or password.']);
        $unknown->assertStatus(422)->assertJsonValidationErrors(['email' => 'Invalid email or password.']);
        $this->assertSame($known->json(), $unknown->json());

        // Unknown emails lock out too.
        $this->failApiLogin('nobody@example.com', 4);
        $this->apiLogin('nobody@example.com', 'wrong-password')->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    }

    public function test_email_case_and_whitespace_share_one_counter(): void
    {
        $this->failApiLogin('MARIA.SANTOS@example.com', 2);
        $this->failApiLogin(' maria.santos@EXAMPLE.com ', 2);
        $this->failApiLogin(self::VISITOR_EMAIL, 1);

        $this->apiLogin('Maria.Santos@Example.com', 'password')->assertStatus(429);
    }

    public function test_different_ips_use_separate_counters(): void
    {
        $this->failApiLogin(self::VISITOR_EMAIL, 5, '10.0.0.1');

        $this->apiLogin(self::VISITOR_EMAIL, 'password', '10.0.0.1')->assertStatus(429);
        $this->apiLogin(self::VISITOR_EMAIL, 'password', '10.0.0.2')->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_successful_login_clears_the_counter(): void
    {
        $this->failApiLogin(self::VISITOR_EMAIL, 4);
        $this->apiLogin(self::VISITOR_EMAIL, 'password')->assertOk()->assertJsonStructure(['token', 'user']);

        // Counter starts again from zero: four more failures are still allowed.
        $this->failApiLogin(self::VISITOR_EMAIL, 4);
        $this->apiLogin(self::VISITOR_EMAIL, 'password')->assertOk();
    }

    public function test_unverified_email_response_is_unchanged_and_clears_the_counter(): void
    {
        $this->visitorAccount->forceFill(['email_verified_at' => null])->save();
        $this->failApiLogin(self::VISITOR_EMAIL, 4);

        $this->apiLogin(self::VISITOR_EMAIL, 'password')
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'Please verify your email address before logging in.',
                'code' => 'email_not_verified',
                'email' => self::VISITOR_EMAIL,
            ]);

        $this->failApiLogin(self::VISITOR_EMAIL, 4);
        $this->apiLogin(self::VISITOR_EMAIL, 'password')->assertStatus(403)->assertJsonPath('code', 'email_not_verified');
    }

    public function test_inactive_visitor_response_is_unchanged(): void
    {
        $this->visitorAccount->forceFill(['status' => 'inactive'])->save();

        $this->apiLogin(self::VISITOR_EMAIL, 'password')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'This account is not active. Contact facility staff.']);
    }

    public function test_staff_account_response_on_the_api_is_unchanged(): void
    {
        $this->apiLogin('r.salcedo@bjmp.gov.ph', 'password')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'Staff accounts cannot sign in to the mobile app. Use the web console.']);
    }

    // ---- Staff web login ---------------------------------------------------

    public function test_staff_route_is_ip_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->staffLogin("unknown.user{$i}", 'wrong-password')->assertRedirect('/login');
        }

        $this->staffLogin('unknown.user10', 'wrong-password')->assertStatus(429);
        $this->staffLogin('r.salcedo', 'password', '10.0.0.2')->assertRedirect();
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');
    }

    public function test_wrong_password_and_unknown_account_get_the_same_generic_error(): void
    {
        $generic = 'These credentials do not match a staff account.';

        foreach (['r.salcedo', 'r.salcedo@bjmp.gov.ph', 'nobody', 'nobody@bjmp.gov.ph', self::VISITOR_EMAIL] as $login) {
            $this->staffLogin($login, 'wrong-password')
                ->assertRedirect('/login')
                ->assertSessionHasErrors(['email' => $generic]);
        }

        $this->assertGuest('web');
    }

    public function test_account_status_is_not_revealed_without_the_right_password(): void
    {
        $generic = 'These credentials do not match a staff account.';
        $this->frontDeskAccount->forceFill(['status' => 'inactive'])->save();
        $this->recordOfficerAccount->forceFill([
            'invitation_token_hash' => hash('sha256', 'x'),
            'invitation_expires_at' => now()->addDay(),
        ])->save();

        $this->staffLogin('n.fernandez', 'wrong-password')->assertSessionHasErrors(['email' => $generic]);
        $this->staffLogin('r.salcedo', 'wrong-password')->assertSessionHasErrors(['email' => $generic]);
    }

    public function test_five_staff_failures_lock_the_next_attempt_even_with_the_right_password(): void
    {
        $this->staffLogin('R.Salcedo ', 'wrong-password');
        $this->staffLogin(' r.salcedo', 'wrong-password');
        $this->staffLogin('r.salcedo', 'wrong-password');
        $this->staffLogin('R.SALCEDO', 'wrong-password');
        $this->staffLogin('r.salcedo', 'wrong-password');

        $this->staffLogin('r.salcedo', 'password')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Too many failed sign-in attempts. Please wait a few minutes and try again.']);
        $this->assertGuest('web');

        // A different IP has its own counter.
        $this->staffLogin('r.salcedo', 'password', '10.0.0.2')->assertRedirect();
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');
    }

    public function test_successful_staff_login_clears_the_counter(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->staffLogin('r.salcedo', 'wrong-password');
        }

        $this->staffLogin('r.salcedo', 'password')->assertRedirect();
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');
        $this->assertNotNull($this->recordOfficerAccount->fresh()->last_login_at);

        $this->post('/logout');
        for ($i = 0; $i < 4; $i++) {
            $this->staffLogin('r.salcedo', 'wrong-password');
        }
        $this->staffLogin('r.salcedo', 'password')->assertRedirect();
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');
    }

    public function test_status_messages_after_the_right_password_are_unchanged(): void
    {
        $this->staffLogin(self::VISITOR_EMAIL, 'password')
            ->assertSessionHasErrors(['email' => 'Visitor accounts sign in through the CustodiCore mobile app, not this dashboard.']);

        $this->recordOfficerAccount->forceFill([
            'invitation_token_hash' => hash('sha256', 'x'),
            'invitation_expires_at' => now()->addDay(),
        ])->save();
        $this->staffLogin('r.salcedo', 'password')
            ->assertSessionHasErrors(['email' => 'Your account setup is not finished. Open the setup link sent to your email or mobile number, or ask the Warden to send a new one.']);

        $this->frontDeskAccount->forceFill(['status' => 'inactive'])->save();
        $this->staffLogin('n.fernandez', 'password')
            ->assertSessionHasErrors(['email' => 'This account is inactive. Contact the Warden to restore access.']);

        $this->assertGuest('web');
    }

    public function test_warden_can_sign_in_by_email(): void
    {
        $warden = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'admin.warden',
            'email' => 'admin.warden@bjmp.gov.ph',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);

        $this->staffLogin('admin.warden@bjmp.gov.ph', 'password')->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($warden, 'web');
    }
}
