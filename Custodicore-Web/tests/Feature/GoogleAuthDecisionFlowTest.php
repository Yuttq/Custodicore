<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\VisitorProfile;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GoogleIdTokenFixtures;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * POST /api/auth/google, phase 2 — the account decision flow for a
 * VERIFIED Google identity (see App\Services\Auth\GoogleAuthService):
 * authenticate a linked active Visitor, ask a new user to register, require
 * password linking for an existing email, and reject Staff / inactive
 * accounts. Nothing here may create an account or link a Google identity.
 */
class GoogleAuthDecisionFlowTest extends TestCase
{
    use GoogleIdTokenFixtures;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const GOOGLE_SUB = '109876543210987654321';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());
    }

    private function linkGoogle(Account $account, string $sub = self::GOOGLE_SUB): void
    {
        $account->forceFill(['google_id' => $sub])->save();
    }

    private function postGoogle(array $claims = [], array $extra = [])
    {
        return $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken($claims)] + $extra);
    }

    private function assertNoTokenIssued($response): void
    {
        $response->assertJsonMissingPath('token')->assertJsonMissingPath('user');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ---------------------------------------------------------------
    // 1. Existing linked Google visitor
    // ---------------------------------------------------------------

    public function test_linked_active_visitor_is_authenticated(): void
    {
        $this->linkGoogle($this->visitorAccount);
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();
        $profileBefore = $this->visitor->fresh()->getAttributes();
        $this->assertNull($this->visitorAccount->fresh()->last_login_at);

        $response = $this->postGoogle();

        $response->assertOk()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('user.id', (string) $this->visitorAccount->account_id)
            ->assertJsonPath('user.email', 'maria.santos@example.com')
            ->assertJsonPath('user.fullName', 'Maria D. Santos')
            ->assertJsonPath('user.role', 'Visitor')
            ->assertJsonPath('user.dateOfBirth', '1985-03-14')
            ->assertJsonStructure(['status', 'token', 'user' => [
                'id', 'email', 'fullName', 'role', 'verificationStatus', 'verifiedAt',
                'dateOfBirth', 'gender', 'address', 'contactNumber',
                'emergencyContactName', 'emergencyContactNumber', 'relationshipHint',
            ]])
            ->assertJsonMissingPath('user.google_id')
            ->assertJsonMissingPath('user.password_hash');

        // Exactly one Sanctum token, named for Google, belonging to this visitor.
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertNotNull($token);
        $this->assertSame('mobile-google', $token->name);
        $this->assertTrue($token->tokenable->is($this->visitorAccount));

        $this->assertNotNull($this->visitorAccount->fresh()->last_login_at);
        $this->assertSame($accountsBefore, Account::count());
        $this->assertSame($profilesBefore, VisitorProfile::count());
        $this->assertEquals($profileBefore, $this->visitor->fresh()->getAttributes());
        $this->assertSame(self::GOOGLE_SUB, $this->visitorAccount->fresh()->google_id);
    }

    public function test_issued_token_works_on_authenticated_routes(): void
    {
        $this->linkGoogle($this->visitorAccount);
        $token = $this->postGoogle()->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('id', (string) $this->visitorAccount->account_id);
    }

    public function test_linked_visitor_is_matched_by_sub_even_if_google_email_changed(): void
    {
        $this->linkGoogle($this->visitorAccount);

        $this->postGoogle(['email' => 'maria.new.address@gmail.com'])
            ->assertOk()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('user.email', 'maria.santos@example.com');

        // The account email is not overwritten from Google.
        $this->assertSame('maria.santos@example.com', $this->visitorAccount->fresh()->email);
    }

    public function test_linked_staff_account_is_rejected(): void
    {
        $this->linkGoogle($this->recordOfficerAccount);

        $response = $this->postGoogle()
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'Google sign-in is only available for visitor accounts.',
                'code' => 'not_visitor_account',
            ]);

        $this->assertNoTokenIssued($response);
        $this->assertNull($this->recordOfficerAccount->fresh()->last_login_at);
    }

    // ---------------------------------------------------------------
    // 2. New Google user
    // ---------------------------------------------------------------

    public function test_new_google_user_gets_registration_required_and_nothing_is_created(): void
    {
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postGoogle(['email' => 'new.person@gmail.com', 'name' => 'New Person'])
            ->assertOk()
            ->assertExactJson([
                'status' => 'registration_required',
                'profile' => ['email' => 'new.person@gmail.com', 'fullName' => 'New Person'],
                'consentVersion' => (string) config('legal.version'),
            ]);

        $this->assertNoTokenIssued($response);
        $this->assertSame($accountsBefore, Account::count());
        $this->assertSame($profilesBefore, VisitorProfile::count());
        $this->assertSame(0, Account::whereNotNull('google_id')->count());
        $this->assertDatabaseMissing('accounts', ['email' => 'new.person@gmail.com']);
    }

    public function test_registration_required_without_name_claim_returns_null_full_name(): void
    {
        $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken([], ['name'])])
            ->assertOk()
            ->assertJsonPath('status', 'registration_required')
            ->assertJsonPath('profile.fullName', null);
    }

    public function test_consent_version_follows_legal_config(): void
    {
        config(['legal.version' => '2099-01-01']);

        $this->postGoogle()->assertOk()->assertJsonPath('consentVersion', '2099-01-01');
    }

    // ---------------------------------------------------------------
    // 3. Existing password account with the same email
    // ---------------------------------------------------------------

    public function test_existing_visitor_with_same_email_requires_linking(): void
    {
        $before = $this->visitorAccount->fresh()->getAttributes();

        $response = $this->postGoogle(['email' => 'maria.santos@example.com'])
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'This email already has a Custodicore account. Confirm your Custodicore password before linking Google.',
                'code' => 'link_required',
            ]);

        $this->assertNoTokenIssued($response);
        $fresh = $this->visitorAccount->fresh();
        $this->assertNull($fresh->google_id);
        $this->assertNull($fresh->last_login_at);
        $this->assertEquals($before, $fresh->getAttributes());
    }

    public function test_email_match_is_case_insensitive_and_still_never_links(): void
    {
        $this->postGoogle(['email' => 'Maria.Santos@Example.COM'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'link_required');

        $this->assertNull($this->visitorAccount->fresh()->google_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_email_linked_to_a_different_google_identity_requires_linking(): void
    {
        // The account is linked, but to another Google `sub`.
        $this->linkGoogle($this->visitorAccount, '200000000000000000000');

        $this->postGoogle(['email' => 'maria.santos@example.com'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'link_required');

        $this->assertSame('200000000000000000000', $this->visitorAccount->fresh()->google_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_repeated_attempts_never_auto_link_on_email(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postGoogle(['email' => 'maria.santos@example.com'])->assertStatus(409);
        }

        $this->assertSame(0, Account::whereNotNull('google_id')->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ---------------------------------------------------------------
    // 4. Staff account by email
    // ---------------------------------------------------------------

    public static function staffEmailProvider(): array
    {
        return [
            'Record Officer' => ['r.salcedo@bjmp.gov.ph'],
            'Front Desk Officer' => ['n.fernandez@bjmp.gov.ph'],
        ];
    }

    #[DataProvider('staffEmailProvider')]
    public function test_staff_email_is_rejected_without_linking(string $email): void
    {
        $staff = Account::where('email', $email)->firstOrFail();
        $before = $staff->getAttributes();

        $response = $this->postGoogle(['email' => $email])
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_visitor_account');

        $this->assertNoTokenIssued($response);
        $this->assertEquals($before, $staff->fresh()->getAttributes());
        $this->assertSame(0, VisitorProfile::where('account_id', $staff->account_id)->count());
    }

    public function test_inactive_staff_is_reported_as_not_visitor(): void
    {
        // Role is checked before status, as in the password login.
        $this->recordOfficerAccount->update(['status' => 'inactive']);

        $this->postGoogle(['email' => 'r.salcedo@bjmp.gov.ph'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_visitor_account');
    }

    // ---------------------------------------------------------------
    // 5. Inactive accounts
    // ---------------------------------------------------------------

    public static function inactiveStatusProvider(): array
    {
        return [['inactive'], ['suspended']];
    }

    #[DataProvider('inactiveStatusProvider')]
    public function test_inactive_linked_visitor_is_rejected(string $status): void
    {
        $this->linkGoogle($this->visitorAccount);
        $this->visitorAccount->update(['status' => $status]);

        $response = $this->postGoogle()
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'This account is not active. Contact facility staff.',
                'code' => 'account_inactive',
            ]);

        $this->assertNoTokenIssued($response);
        $fresh = $this->visitorAccount->fresh();
        $this->assertSame($status, $fresh->status);
        $this->assertNull($fresh->last_login_at);
    }

    public function test_inactive_visitor_by_email_is_rejected_without_linking(): void
    {
        $this->visitorAccount->update(['status' => 'suspended']);

        $response = $this->postGoogle(['email' => 'maria.santos@example.com'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_inactive');

        $this->assertNoTokenIssued($response);
        $fresh = $this->visitorAccount->fresh();
        $this->assertSame('suspended', $fresh->status);
        $this->assertNull($fresh->google_id);
    }

    // ---------------------------------------------------------------
    // Security
    // ---------------------------------------------------------------

    public function test_client_supplied_email_and_name_cannot_override_verified_claims(): void
    {
        $this->postGoogle(['email' => 'new.person@gmail.com', 'name' => 'New Person'], [
            'email' => 'maria.santos@example.com',
            'name' => 'Spoofed Name',
            'fullName' => 'Spoofed Name',
            'full_name' => 'Spoofed Name',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'registration_required')
            ->assertJsonPath('profile.email', 'new.person@gmail.com')
            ->assertJsonPath('profile.fullName', 'New Person');

        $this->assertNull($this->visitorAccount->fresh()->google_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_client_supplied_google_id_cannot_select_a_linked_account(): void
    {
        $this->linkGoogle($this->visitorAccount);

        // Token is for a different Google user; the body claims Maria's sub.
        $response = $this->postGoogle(['sub' => '300000000000000000000', 'email' => 'other@gmail.com'], [
            'googleId' => self::GOOGLE_SUB,
            'google_id' => self::GOOGLE_SUB,
            'sub' => self::GOOGLE_SUB,
        ])->assertOk()->assertJsonPath('status', 'registration_required');

        $this->assertNoTokenIssued($response);
    }

    public function test_client_supplied_email_cannot_trigger_staff_or_link_outcomes(): void
    {
        $this->postGoogle(['email' => 'new.person@gmail.com'], ['email' => 'r.salcedo@bjmp.gov.ph'])
            ->assertOk()
            ->assertJsonPath('status', 'registration_required');
    }

    public function test_invalid_token_is_rejected_even_for_a_linked_account(): void
    {
        $this->linkGoogle($this->visitorAccount);

        // Signed by a key Google does not publish.
        $response = $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken([], [], 'attacker')])
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Google Sign-In failed. Please try again.', 'code' => 'invalid_google_token']);

        $this->assertNoTokenIssued($response);
    }

    public function test_access_token_is_not_accepted_as_an_id_token(): void
    {
        // Google OAuth access tokens are opaque strings, not JWTs.
        $this->postJson('/api/auth/google', ['idToken' => 'ya29.a0AfH6SMBexampleOpaqueAccessToken'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_google_token');

        $this->postJson('/api/auth/google', ['accessToken' => 'ya29.a0AfH6SMBexampleOpaqueAccessToken'])
            ->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_responses_never_echo_the_token_or_its_claims(): void
    {
        $token = $this->googleIdToken(['email' => 'maria.santos@example.com']);
        [, $payloadSegment] = explode('.', $token);

        $cases = [
            fn () => $this->postJson('/api/auth/google', ['idToken' => $token]), // 409
            fn () => $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken(['email' => 'r.salcedo@bjmp.gov.ph'])]), // 403
            fn () => $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken(['aud' => 'x.apps.googleusercontent.com'])]), // 401
        ];

        foreach ($cases as $case) {
            $body = $case()->getContent();
            $this->assertStringNotContainsString($payloadSegment, $body);
            $this->assertStringNotContainsString(self::GOOGLE_SUB, $body);
            $this->assertStringNotContainsString(self::GOOGLE_TEST_CLIENT_ID, $body);
            $this->assertStringNotContainsString('maria.santos@example.com', $body);
        }
    }
}
