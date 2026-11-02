<?php

namespace Tests\Feature;

use App\Services\Auth\StaffMfaService;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\PassesStaffMfa;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Behind Azure (App Service front end, optionally Front Door) the visitor's
 * IP and HTTPS arrive in X-Forwarded-* headers. They are believed only from
 * proxies in TRUSTED_PROXIES, and only For/Proto/Port — never Host. Login
 * throttles and lockouts then count each real visitor separately instead
 * of everyone sharing the proxy's address.
 */
class TrustedProxiesTest extends TestCase
{
    use PassesStaffMfa;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const AZURE_FRONT_END = '10.0.0.9';
    private const CLIENT_A = '203.0.113.10';
    private const CLIENT_B = '203.0.113.20';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        // Test-only page reporting what the app concluded about the request.
        Route::middleware('web')->get('/__proxy-probe', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
            'url' => url('/x'),
        ]);
    }

    private function trust(?string $value): void
    {
        config(['trustedproxy.proxies' => TrustedProxies::parse($value)]);
    }

    /** A request arriving from $peer carrying $headers, as the app would see it. */
    private function viaPeer(string $peer, array $headers = []): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $peer])->withHeaders($headers);
    }

    /** Test requests go to APP_URL's host (and port, if it has one). */
    private function appHost(bool $withPort = true): string
    {
        $url = parse_url((string) config('app.url'));
        $port = $withPort && isset($url['port']) ? ':' . $url['port'] : '';

        return ($url['host'] ?? 'localhost') . $port;
    }

    private function staffLogin(string $login, string $password = 'password')
    {
        return $this->post('/login', ['email' => $login, 'password' => $password]);
    }

    // ---------------------------------------------------------------- detection

    public function test_nothing_is_trusted_by_default(): void
    {
        $this->trust(null);

        $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => self::CLIENT_A, 'X-Forwarded-Proto' => 'https'])
            ->get('/__proxy-probe')
            ->assertExactJson([
                'ip' => self::AZURE_FRONT_END,
                'secure' => false,
                'host' => $this->appHost(false),
                'url' => 'http://' . $this->appHost() . '/x',
            ]);
    }

    public function test_azure_front_end_reports_the_client_ip_and_https(): void
    {
        $this->trust('REMOTE_ADDR');

        // App Service sends the client as "ip:port".
        $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => self::CLIENT_A . ':51234', 'X-Forwarded-Proto' => 'https'])
            ->get('/__proxy-probe')
            ->assertJson(['ip' => self::CLIENT_A, 'secure' => true, 'url' => 'https://' . $this->appHost() . '/x']);
    }

    public function test_a_client_cannot_fake_its_address_through_the_trusted_front_end(): void
    {
        $this->trust('REMOTE_ADDR');

        // The client sent "X-Forwarded-For: 198.51.100.99"; the front end appended the real address.
        $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => '198.51.100.99, ' . self::CLIENT_A])
            ->get('/__proxy-probe')
            ->assertJson(['ip' => self::CLIENT_A]);
    }

    public function test_an_untrusted_machine_cannot_claim_a_client_ip_or_https(): void
    {
        $this->trust('10.0.0.0/8');

        $this->viaPeer('198.51.100.5', ['X-Forwarded-For' => self::CLIENT_A, 'X-Forwarded-Proto' => 'https'])
            ->get('/__proxy-probe')
            ->assertJson(['ip' => '198.51.100.5', 'secure' => false]);
    }

    public function test_front_door_then_app_service_finds_the_client(): void
    {
        $this->trust('REMOTE_ADDR, 147.243.0.0/16');

        // Client -> Front Door (147.243.x) -> App Service front end -> app.
        $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => self::CLIENT_A . ', 147.243.12.34', 'X-Forwarded-Proto' => 'https'])
            ->get('/__proxy-probe')
            ->assertJson(['ip' => self::CLIENT_A, 'secure' => true]);
    }

    public function test_forwarded_host_is_never_believed(): void
    {
        $this->trust('REMOTE_ADDR');

        $response = $this->viaPeer(self::AZURE_FRONT_END, [
            'X-Forwarded-For' => self::CLIENT_A,
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example',
            'X-Forwarded-Prefix' => '/evil',
        ])->get('/__proxy-probe');

        $response->assertJson(['host' => $this->appHost(false), 'url' => 'https://' . $this->appHost() . '/x']);
        $this->assertStringNotContainsString('evil', $response->getContent());
    }

    // ---------------------------------------------------------------- cookies

    public function test_the_session_cookie_is_secure_when_the_front_end_reports_https(): void
    {
        config(['session.secure' => null]); // the default when SESSION_SECURE_COOKIE is not set
        $this->trust('REMOTE_ADDR');
        $cookie = config('session.cookie');

        $response = $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => self::CLIENT_A, 'X-Forwarded-Proto' => 'https'])
            ->get('/login')
            ->assertCookie($cookie);

        $this->assertTrue($response->getCookie($cookie, false)->isSecure());
    }

    public function test_an_untrusted_https_claim_does_not_make_the_session_cookie_secure(): void
    {
        config(['session.secure' => null]);
        $this->trust('10.0.0.0/8');
        $cookie = config('session.cookie');

        $response = $this->viaPeer('198.51.100.5', ['X-Forwarded-For' => self::CLIENT_A, 'X-Forwarded-Proto' => 'https'])
            ->get('/login')
            ->assertCookie($cookie);

        $this->assertFalse($response->getCookie($cookie, false)->isSecure());
    }

    public function test_the_trusted_device_cookie_is_secure_behind_the_azure_front_end(): void
    {
        $this->trust('REMOTE_ADDR');
        $this->enrollStaffMfa($this->recordOfficerAccount);

        $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => self::CLIENT_A, 'X-Forwarded-Proto' => 'https']);
        $this->staffLogin('r.salcedo')->assertRedirect(route('mfa.challenge'));
        $cookie = $this->passMfaChallenge($this->recordOfficerAccount, ['trust_device' => '1'])
            ->assertRedirect(route('dashboard'))
            ->getCookie(StaffMfaService::TRUST_COOKIE);

        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
    }

    // ---------------------------------------------------------------- throttles + lockouts

    public function test_each_client_behind_the_proxy_has_its_own_staff_lockout(): void
    {
        $this->trust('REMOTE_ADDR');
        $clientA = ['X-Forwarded-For' => self::CLIENT_A, 'X-Forwarded-Proto' => 'https'];

        for ($i = 0; $i < 5; $i++) {
            $this->viaPeer(self::AZURE_FRONT_END, $clientA)->staffLogin('r.salcedo', 'wrong-password');
        }

        $locked = 'Too many failed sign-in attempts. Please wait a few minutes and try again.';
        $this->viaPeer(self::AZURE_FRONT_END, $clientA)->staffLogin('r.salcedo')->assertSessionHasErrors(['email' => $locked]);

        // Client A cannot escape its lockout by adding a made-up address in front.
        $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => '198.51.100.99, ' . self::CLIENT_A])
            ->staffLogin('r.salcedo')
            ->assertSessionHasErrors(['email' => $locked]);
        $this->assertGuest('web');

        // Another visitor arriving through the same front end is not locked out.
        $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => self::CLIENT_B, 'X-Forwarded-Proto' => 'https'])
            ->staffLogin('r.salcedo')
            ->assertRedirect(route('mfa.challenge'));
        $this->assertWaitingForMfa($this->recordOfficerAccount);
    }

    public function test_the_login_route_throttle_counts_each_client_separately(): void
    {
        $this->trust('REMOTE_ADDR');

        for ($i = 0; $i < 10; $i++) {
            $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => self::CLIENT_A])
                ->staffLogin("unknown.user{$i}", 'wrong-password')
                ->assertRedirect();
        }
        $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => self::CLIENT_A])
            ->staffLogin('unknown.user10', 'wrong-password')
            ->assertStatus(429);

        $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => self::CLIENT_B])
            ->staffLogin('r.salcedo')
            ->assertRedirect(route('mfa.challenge'));
    }

    public function test_without_trusted_proxies_everyone_behind_the_proxy_shares_one_counter(): void
    {
        // Documents why TRUSTED_PROXIES must be set on Azure.
        $this->trust(null);

        for ($i = 0; $i < 10; $i++) {
            $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => self::CLIENT_A])
                ->staffLogin("unknown.user{$i}", 'wrong-password');
        }

        $this->viaPeer(self::AZURE_FRONT_END, ['X-Forwarded-For' => self::CLIENT_B])
            ->staffLogin('r.salcedo')
            ->assertStatus(429);
    }
}
