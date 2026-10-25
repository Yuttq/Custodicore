<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\VisitCheckin;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * SEC-02: an account that is no longer active (inactive or suspended) loses
 * access straight away — open staff browser sessions and mobile bearer
 * tokens alike — and deactivating it from User Management also removes its
 * tokens and stored sessions. Mobile tokens also expire after
 * config('sanctum.expiration') minutes (30 days by default).
 *
 * "Now" starts at Wednesday 2026-10-07 10:00 Asia/Manila; the fixture
 * schedule is Fri 2026-10-09 09:00.
 */
class DeactivatedAccountAccessTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    private Account $adminAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        $this->adminAccount = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'warden',
            'email' => 'warden@bjmp.gov.ph',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
    }

    // ------------------------------------------------------------ Helpers

    private function setStatus(Account $account, string $status): void
    {
        $account->forceFill(['status' => $status])->save();
    }

    /** Next request re-authenticates (the test app keeps guards between calls). */
    private function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function api(string $token, string $method, string $uri): \Illuminate\Testing\TestResponse
    {
        $this->freshRequest();

        return $this->withToken($token)->json($method, $uri);
    }

    private function toggle(Account $account): \Illuminate\Testing\TestResponse
    {
        $this->freshRequest();
        $this->actingAs($this->adminAccount, 'web');

        return $this->patch(route('admin.users.toggle-status', $account->account_id), ['current_password' => 'password']);
    }

    private function storeSession(Account $account): string
    {
        $id = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $account->account_id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);

        return $id;
    }

    /** A confirmed Friday 09:00 visit for the fixture visitor, with its QR token. */
    private function confirmedVisitWithQr(string $apiToken): array
    {
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'x.png',
            'verification_status' => 'verified',
        ]);

        Sanctum::actingAs($this->visitorAccount);
        $id = $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::FRIDAY,
            'startTimes' => ['09:00'],
        ])->assertCreated()->json('id');
        $this->completeRequirements();
        $visit = app(VisitAssignmentService::class)->approveVisitorRequest(VisitRequest::findOrFail($id));

        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 09:30:00', 'Asia/Manila'));
        $qr = $this->api($apiToken, 'GET', "/api/schedules/{$visit->visit_request_id}/qr")->assertOk()->json('qrToken');

        return [$visit, $qr];
    }

    // ------------------------------------------------------------ Staff web

    public function test_active_staff_keep_access(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web')->get('/dashboard')->assertOk();
        $this->actingAs($this->frontDeskAccount, 'web')->get(route('frontdesk.dashboard'))->assertOk();
    }

    public function test_staff_deactivated_mid_session_are_signed_out_on_the_next_request(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web')->get('/dashboard')->assertOk();

        $this->setStatus($this->recordOfficerAccount, 'inactive');

        $this->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Your account is not active. Contact the Warden to restore access.']);
        $this->assertGuest('web');

        // The login page itself is reachable (no redirect loop) and the
        // dashboard stays closed.
        $this->get(route('login'))->assertOk();
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_suspended_staff_are_signed_out_too(): void
    {
        $this->setStatus($this->frontDeskAccount, 'suspended');

        $this->actingAs($this->frontDeskAccount, 'web')
            ->get(route('frontdesk.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_deactivated_front_desk_json_gate_request_gets_401_and_does_nothing(): void
    {
        $visitorToken = $this->visitorAccount->createToken('mobile')->plainTextToken;
        [, $qr] = $this->confirmedVisitWithQr($visitorToken);

        $this->setStatus($this->frontDeskAccount, 'inactive');
        $this->freshRequest();

        $this->actingAs($this->frontDeskAccount, 'web')
            ->postJson('/front-desk/checkin-checkout/confirm', [
                'qr_token' => $qr,
                'id_surrendered_type' => 'national_id',
                'id_match_status' => 'matched',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'account_inactive');

        $this->assertGuest('web');
        $this->assertSame(0, VisitCheckin::count());
    }

    public function test_reactivated_staff_can_log_in_again(): void
    {
        $this->toggle($this->recordOfficerAccount)->assertRedirect(route('admin.users.index'));
        $this->assertSame('inactive', $this->recordOfficerAccount->fresh()->status);

        $this->toggle($this->recordOfficerAccount)->assertRedirect(route('admin.users.index'));
        $this->assertSame('active', $this->recordOfficerAccount->fresh()->status);

        $this->post('/logout');
        $this->freshRequest();

        $this->from('/login')
            ->post('/login', ['email' => 'r.salcedo@bjmp.gov.ph', 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->recordOfficerAccount->fresh(), 'web');
        $this->get('/dashboard')->assertOk();
    }

    // ------------------------------------------------------------ Visitor API

    public function test_active_visitor_token_keeps_working(): void
    {
        $token = $this->visitorAccount->createToken('mobile')->plainTextToken;

        $this->api($token, 'GET', '/api/me')->assertOk();
        $this->api($token, 'GET', '/api/notifications')->assertOk();
    }

    public function test_visitor_token_is_rejected_once_the_account_is_inactive(): void
    {
        $token = $this->visitorAccount->createToken('mobile')->plainTextToken;
        [$visit] = $this->confirmedVisitWithQr($token);

        $this->setStatus($this->visitorAccount, 'inactive');

        $this->api($token, 'GET', '/api/me')->assertUnauthorized();
        $this->api($token, 'GET', "/api/schedules/{$visit->visit_request_id}/qr")->assertUnauthorized();
        $this->api($token, 'GET', '/api/visits')->assertUnauthorized();
    }

    public function test_suspended_visitor_token_is_rejected(): void
    {
        $token = $this->visitorAccount->createToken('mobile')->plainTextToken;
        $this->api($token, 'GET', '/api/me')->assertOk();

        $this->setStatus($this->visitorAccount, 'suspended');

        $this->api($token, 'GET', '/api/me')->assertUnauthorized();
    }

    public function test_an_active_account_does_not_rescue_an_invalid_token(): void
    {
        // expires_at in the past, account active.
        $expired = $this->visitorAccount->createToken('mobile', ['*'], now()->subMinute())->plainTextToken;
        $this->api($expired, 'GET', '/api/me')->assertUnauthorized();

        // Unknown token.
        $this->api('999|not-a-real-token', 'GET', '/api/me')->assertUnauthorized();
    }

    // ------------------------------------------------ Revocation on toggle

    public function test_deactivation_revokes_only_that_accounts_tokens_and_sessions(): void
    {
        config(['session.driver' => 'database']);

        $other = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'juan',
            'email' => 'juan@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $visitorToken = $this->visitorAccount->createToken('mobile')->plainTextToken;
        $otherToken = $other->createToken('mobile')->plainTextToken;

        $targetSession = $this->storeSession($this->recordOfficerAccount);
        $otherSession = $this->storeSession($this->frontDeskAccount);

        $this->toggle($this->recordOfficerAccount)->assertRedirect(route('admin.users.index'));
        $this->toggle($this->visitorAccount)->assertRedirect(route('admin.users.index'));

        $this->assertSame('inactive', $this->recordOfficerAccount->fresh()->status);
        $this->assertSame('inactive', $this->visitorAccount->fresh()->status);

        $this->assertDatabaseMissing('sessions', ['id' => $targetSession]);
        $this->assertDatabaseHas('sessions', ['id' => $otherSession]);

        $this->assertSame(0, $this->visitorAccount->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->api($visitorToken, 'GET', '/api/me')->assertUnauthorized();
        $this->api($otherToken, 'GET', '/api/me')->assertOk();
    }

    public function test_reactivation_does_not_restore_or_revoke_tokens(): void
    {
        $revoked = $this->visitorAccount->createToken('mobile')->plainTextToken;

        $this->toggle($this->visitorAccount);
        $this->assertSame(0, $this->visitorAccount->tokens()->count());

        // A token that exists while the account is inactive is left alone by
        // reactivation (and starts working again once active).
        $kept = $this->visitorAccount->createToken('mobile')->plainTextToken;
        $this->toggle($this->visitorAccount);
        $this->assertSame('active', $this->visitorAccount->fresh()->status);

        $this->api($revoked, 'GET', '/api/me')->assertUnauthorized();
        $this->api($kept, 'GET', '/api/me')->assertOk();
    }

    public function test_api_logout_still_revokes_only_the_current_token(): void
    {
        $first = $this->visitorAccount->createToken('mobile')->plainTextToken;
        $second = $this->visitorAccount->createToken('mobile')->plainTextToken;

        $this->api($first, 'POST', '/api/auth/logout')->assertOk();

        $this->assertSame(1, $this->visitorAccount->tokens()->count());
        $this->api($first, 'GET', '/api/me')->assertUnauthorized();
        $this->api($second, 'GET', '/api/me')->assertOk();
    }

    // ------------------------------------------------------------ Expiration

    /**
     * config/sanctum.php's 'expiration' with SANCTUM_EXPIRATION set to $value
     * (null = not set), whatever the developer's own .env says. env() reads
     * $_SERVER, $_ENV and getenv(), so all three are set, then restored.
     */
    private function sanctumExpirationWith(?string $value): mixed
    {
        $key = 'SANCTUM_EXPIRATION';
        $saved = [
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'hasServer' => array_key_exists($key, $_SERVER),
            'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            'hasEnv' => array_key_exists($key, $_ENV),
            'putenv' => getenv($key),
        ];

        try {
            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);
            } else {
                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }

            return (require config_path('sanctum.php'))['expiration'];
        } finally {
            if ($saved['hasServer']) {
                $_SERVER[$key] = $saved['server'];
            } else {
                unset($_SERVER[$key]);
            }
            if ($saved['hasEnv']) {
                $_ENV[$key] = $saved['env'];
            } else {
                unset($_ENV[$key]);
            }
            $saved['putenv'] === false ? putenv($key) : putenv("{$key}={$saved['putenv']}");
        }
    }

    public function test_token_expiration_defaults_to_30_days(): void
    {
        $before = [$_SERVER['SANCTUM_EXPIRATION'] ?? null, $_ENV['SANCTUM_EXPIRATION'] ?? null, getenv('SANCTUM_EXPIRATION')];

        $this->assertSame(43200, $this->sanctumExpirationWith(null), 'Not set: 30 days.');
        $this->assertSame(43200, $this->sanctumExpirationWith(''), 'Empty: 30 days, not "never expires".');
        $this->assertSame(43200, $this->sanctumExpirationWith('0'), 'Zero: 30 days, not "never expires".');
        $this->assertSame(1440, $this->sanctumExpirationWith('1440'), 'An explicit positive value is kept.');

        $this->assertSame(
            $before,
            [$_SERVER['SANCTUM_EXPIRATION'] ?? null, $_ENV['SANCTUM_EXPIRATION'] ?? null, getenv('SANCTUM_EXPIRATION')],
            'The environment is restored.'
        );
    }

    public function test_token_works_until_it_expires(): void
    {
        $token = $this->visitorAccount->createToken('mobile')->plainTextToken;
        $issued = now();

        $this->travelTo($issued->copy()->addMinutes(43200 - 1));
        $this->api($token, 'GET', '/api/me')->assertOk();

        $this->travelTo($issued->copy()->addMinutes(43200 + 1));
        $this->api($token, 'GET', '/api/me')->assertUnauthorized();

        // Still in the table (no pruning); simply no longer accepted.
        $this->assertSame(1, PersonalAccessToken::count());
    }
}
