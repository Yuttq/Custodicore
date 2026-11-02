<?php

namespace Tests\Feature;

use App\Support\TrustedProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * App\Http\Middleware\SecurityHeaders: the same browser security headers on
 * pages, API responses and private files; HSTS only over HTTPS and only when
 * HSTS_MAX_AGE is set. All accounts and files here are synthetic.
 */
class SecurityHeadersTest extends TestCase
{
    use MakesTestImages;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const AZURE_FRONT_END = '10.0.0.9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
        $response->assertHeaderMissing('Cross-Origin-Resource-Policy');
    }

    public function test_web_page_has_the_security_headers(): void
    {
        $response = $this->get('/login')->assertOk();

        $this->assertSecurityHeaders($response);
        // Scripts and styles are not restricted: the views rely on inline ones.
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('script-src', $csp);
        $this->assertStringNotContainsString('style-src', $csp);
        $this->assertStringNotContainsString('default-src', $csp);
    }

    public function test_api_responses_have_the_security_headers(): void
    {
        $this->assertSecurityHeaders($this->getJson('/api/me')->assertUnauthorized());

        Sanctum::actingAs($this->visitorAccount);
        $this->assertSecurityHeaders($this->getJson('/api/me')->assertOk());
    }

    public function test_private_file_response_has_the_security_headers(): void
    {
        Storage::disk('local')->put('visitor-photos/synthetic.png', $this->fakePhoto()->getContent());
        $this->visitor->update(['photo_path' => 'visitor-photos/synthetic.png']);
        Sanctum::actingAs($this->visitorAccount->fresh());

        $response = $this->get('/api/me/photo')->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSecurityHeaders($response);
        // The controller's own caching header is kept.
        $this->assertStringContainsString('max-age=300', $response->headers->get('Cache-Control'));
    }

    public function test_error_responses_have_the_security_headers(): void
    {
        $this->assertSecurityHeaders($this->get('/no-such-page')->assertNotFound());
    }

    public function test_camera_stays_allowed_for_the_front_desk_qr_scanner(): void
    {
        $response = $this->actingAs($this->frontDeskAccount)->get(route('frontdesk.checkin-checkout'))->assertOk();

        $this->assertStringContainsString('camera=(self)', $response->headers->get('Permissions-Policy'));
    }

    public function test_staff_sign_in_still_works(): void
    {
        $response = $this->post('/login', ['email' => $this->frontDeskAccount->email, 'password' => 'password']);

        $response->assertRedirect();
        $this->assertNotSame(route('login'), $response->headers->get('Location'));
        $this->assertSecurityHeaders($response);
    }

    // -------------------------------------------------------------------- HSTS

    private function viaAzureOverHttps(): static
    {
        config(['trustedproxy.proxies' => TrustedProxies::parse('REMOTE_ADDR')]);

        return $this->withServerVariables(['REMOTE_ADDR' => self::AZURE_FRONT_END])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443']);
    }

    public function test_hsts_is_off_by_default(): void
    {
        $this->assertSame(0, config('app.hsts_max_age'));

        $this->viaAzureOverHttps()->get('/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        config(['app.hsts_max_age' => 86400]);

        $this->get('http://localhost/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_forwarded_https_from_an_untrusted_peer_gets_no_hsts(): void
    {
        config(['app.hsts_max_age' => 86400, 'trustedproxy.proxies' => null]);

        $this->withServerVariables(['REMOTE_ADDR' => self::AZURE_FRONT_END])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://localhost/login')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_on_https_through_a_trusted_proxy(): void
    {
        config(['app.hsts_max_age' => 86400]);

        $this->viaAzureOverHttps()->get('http://localhost/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=86400');
    }

    public function test_hsts_uses_the_configured_max_age_without_extra_directives(): void
    {
        config(['app.hsts_max_age' => 31536000]);

        $hsts = $this->viaAzureOverHttps()->getJson('http://localhost/api/me')->headers->get('Strict-Transport-Security');

        $this->assertSame('max-age=31536000', $hsts);
        $this->assertStringNotContainsStringIgnoringCase('includeSubDomains', $hsts);
        $this->assertStringNotContainsStringIgnoringCase('preload', $hsts);
    }
}
