<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\GoogleIdTokenFixtures;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * POST /api/auth/google — token verification and request handling. The
 * account decision flow (authenticate / registration_required /
 * link_required / staff / inactive) is covered by GoogleAuthDecisionFlowTest.
 * No path here may create or link an account.
 */
class GoogleAuthEndpointTest extends TestCase
{
    use GoogleIdTokenFixtures;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->useGoogleVerifier($this->makeGoogleVerifier());
    }

    private function useGoogleVerifier(GoogleIdTokenVerifier $verifier): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, $verifier);
    }

    private function assertNoAccountOrTokenCreated(int $accountsBefore): void
    {
        $this->assertSame($accountsBefore, Account::count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(0, Account::whereNotNull('google_id')->count());
    }

    public function test_valid_token_for_unknown_user_issues_no_token_and_creates_no_account(): void
    {
        $accountsBefore = Account::count();

        $response = $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken()]);

        $response->assertOk()
            ->assertJsonPath('status', 'registration_required')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');
        $this->assertNoAccountOrTokenCreated($accountsBefore);
        $this->assertCount(1, $this->jwksRequests, 'Token should have gone through real verification.');
    }

    public function test_accepts_snake_case_id_token(): void
    {
        $this->postJson('/api/auth/google', ['id_token' => $this->googleIdToken()])
            ->assertOk()
            ->assertJsonPath('status', 'registration_required');
    }

    public function test_does_not_link_existing_account_with_matching_email(): void
    {
        // Verified Google email equals an existing visitor's email.
        $token = $this->googleIdToken(['email' => 'maria.santos@example.com']);

        $this->postJson('/api/auth/google', ['idToken' => $token])
            ->assertStatus(409)
            ->assertJsonPath('code', 'link_required')
            ->assertJsonMissingPath('token');

        $this->assertNull($this->visitorAccount->fresh()->google_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_client_supplied_email_and_name_cannot_substitute_for_a_valid_token(): void
    {
        $accountsBefore = Account::count();

        $this->postJson('/api/auth/google', [
            'idToken' => $this->googleIdToken([], [], 'attacker'),
            'email' => 'maria.santos@example.com',
            'name' => 'Maria D. Santos',
        ])->assertStatus(401)->assertJsonMissingPath('token');

        $this->assertNoAccountOrTokenCreated($accountsBefore);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken(['email_verified' => false])])
            ->assertStatus(401)
            ->assertJsonMissingPath('token');
    }

    public function test_wrong_audience_is_rejected(): void
    {
        $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken(['aud' => 'other.apps.googleusercontent.com'])])
            ->assertStatus(401);
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken(['iat' => time() - 7200, 'exp' => time() - 3600])])
            ->assertStatus(401);
    }

    public function test_missing_or_non_string_token_fails_validation(): void
    {
        $this->postJson('/api/auth/google', [])->assertStatus(422)->assertJsonValidationErrors('idToken');
        $this->postJson('/api/auth/google', ['idToken' => ['x']])->assertStatus(422)->assertJsonValidationErrors('idToken');
    }

    public function test_unconfigured_google_sign_in_returns_503(): void
    {
        $this->useGoogleVerifier($this->makeGoogleVerifier([]));

        $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken()])
            ->assertStatus(503)
            ->assertJsonMissingPath('token');
    }

    public function test_container_binding_reads_client_ids_from_config(): void
    {
        config(['services.google.client_ids' => []]);
        $this->app->forgetInstance(GoogleIdTokenVerifier::class);

        $this->assertFalse($this->app->make(GoogleIdTokenVerifier::class)->isConfigured());

        config(['services.google.client_ids' => [self::GOOGLE_TEST_CLIENT_ID]]);
        $this->assertTrue($this->app->make(GoogleIdTokenVerifier::class)->isConfigured());
    }

    public function test_google_unreachable_returns_503_and_never_logs_the_token(): void
    {
        $this->useGoogleVerifier($this->makeGoogleVerifier(jwks: 'network_error'));
        $token = $this->googleIdToken();
        [, $payloadSegment] = explode('.', $token);

        Log::spy();

        $this->postJson('/api/auth/google', ['idToken' => $token])->assertStatus(503);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => ! str_contains($message, $token) && ! str_contains($message, $payloadSegment)
        );
    }

    public function test_invalid_token_is_not_logged(): void
    {
        Log::spy();

        $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken([], [], 'attacker')])
            ->assertStatus(401);

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_google_id_column_is_nullable_and_unique(): void
    {
        $this->assertTrue(Schema::hasColumn('accounts', 'google_id'));

        // Existing accounts have none; multiple NULLs are allowed.
        $this->assertNull($this->visitorAccount->fresh()->google_id);
        $this->assertNull($this->recordOfficerAccount->fresh()->google_id);

        $this->visitorAccount->forceFill(['google_id' => '109876543210987654321'])->save();

        $this->expectException(QueryException::class);
        $this->frontDeskAccount->forceFill(['google_id' => '109876543210987654321'])->save();
    }

    public function test_google_id_is_not_mass_assignable_or_serialized(): void
    {
        $account = new Account(['google_id' => 'injected']);
        $this->assertNull($account->google_id);

        $this->visitorAccount->forceFill(['google_id' => '1234'])->save();
        $this->assertArrayNotHasKey('google_id', $this->visitorAccount->fresh()->toArray());
    }
}
