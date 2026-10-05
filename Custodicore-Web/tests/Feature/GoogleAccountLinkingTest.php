<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\VisitorProfile;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\GoogleIdTokenFixtures;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * POST /api/auth/google/link, phase 3 — explicit linking of a VERIFIED
 * Google identity to the existing Visitor account with the same verified
 * email, proven by that account's Custodicore password (see
 * App\Services\Auth\GoogleAuthService::link()). Never creates an account,
 * never links Staff, never moves a Google identity between accounts.
 */
class GoogleAccountLinkingTest extends TestCase
{
    use GoogleIdTokenFixtures;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const GOOGLE_SUB = '109876543210987654321';
    private const VISITOR_EMAIL = 'maria.santos@example.com';
    private const PASSWORD = 'password'; // SeedsVisitAssignmentFixtures

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());
    }

    private function postLink(array $claims = [], array $extra = [], string $password = self::PASSWORD)
    {
        return $this->postJson('/api/auth/google/link', [
            'idToken' => $this->googleIdToken($claims + ['email' => self::VISITOR_EMAIL]),
            'password' => $password,
        ] + $extra);
    }

    private function assertNoTokenIssued($response): void
    {
        $response->assertJsonMissingPath('token')->assertJsonMissingPath('user');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ---------------------------------------------------------------
    // 1. Successful linking
    // ---------------------------------------------------------------

    public function test_active_visitor_with_correct_password_is_linked_and_authenticated(): void
    {
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();
        $profileBefore = $this->visitor->fresh()->getAttributes();
        $passwordHashBefore = $this->visitorAccount->fresh()->password_hash;
        $this->assertNull($this->visitorAccount->fresh()->last_login_at);

        $response = $this->postLink(['name' => 'Google Display Name']);

        $response->assertOk()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('user.id', (string) $this->visitorAccount->account_id)
            ->assertJsonPath('user.email', self::VISITOR_EMAIL)
            ->assertJsonPath('user.fullName', 'Maria D. Santos')
            ->assertJsonPath('user.role', 'Visitor')
            ->assertJsonStructure(['status', 'token', 'user' => [
                'id', 'email', 'fullName', 'role', 'verificationStatus', 'verifiedAt',
                'dateOfBirth', 'gender', 'address', 'contactNumber',
                'emergencyContactName', 'emergencyContactNumber', 'relationshipHint',
            ]])
            ->assertJsonMissingPath('user.google_id')
            ->assertJsonMissingPath('user.password_hash');

        $fresh = $this->visitorAccount->fresh();
        $this->assertSame(self::GOOGLE_SUB, $fresh->google_id);
        $this->assertNotNull($fresh->last_login_at);
        // Only the `sub` is stored: email, password and profile are untouched.
        $this->assertSame(self::VISITOR_EMAIL, $fresh->email);
        $this->assertSame($passwordHashBefore, $fresh->password_hash);
        $this->assertEquals($profileBefore, $this->visitor->fresh()->getAttributes());
        $this->assertSame($accountsBefore, Account::count());
        $this->assertSame($profilesBefore, VisitorProfile::count());

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertSame('mobile-google', $token->name);
        $this->assertTrue($token->tokenable->is($this->visitorAccount));
    }

    public function test_after_linking_google_sign_in_authenticates_directly(): void
    {
        $token = $this->postLink()->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('id', (string) $this->visitorAccount->account_id);

        // /auth/google now finds the account by `sub`: no more link_required.
        $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken(['email' => self::VISITOR_EMAIL])])
            ->assertOk()
            ->assertJsonPath('status', 'authenticated');
    }

    public function test_email_match_is_case_insensitive(): void
    {
        $this->postLink(['email' => 'Maria.Santos@Example.COM'])
            ->assertOk()
            ->assertJsonPath('user.email', self::VISITOR_EMAIL);

        $this->assertSame(self::GOOGLE_SUB, $this->visitorAccount->fresh()->google_id);
    }

    public function test_accepts_snake_case_id_token(): void
    {
        $this->postJson('/api/auth/google/link', [
            'id_token' => $this->googleIdToken(['email' => self::VISITOR_EMAIL]),
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('status', 'authenticated');
    }

    // ---------------------------------------------------------------
    // 2. Wrong password
    // ---------------------------------------------------------------

    public function test_wrong_password_is_rejected_without_linking(): void
    {
        $before = $this->visitorAccount->fresh()->getAttributes();

        $response = $this->postLink(password: 'not-the-password')
            ->assertStatus(401)
            ->assertExactJson([
                'message' => 'The provided password is incorrect.',
                'code' => 'invalid_password',
            ]);

        $this->assertNoTokenIssued($response);
        $this->assertEquals($before, $this->visitorAccount->fresh()->getAttributes());
    }

    public function test_wrong_password_does_not_reveal_an_existing_link_to_another_identity(): void
    {
        $this->visitorAccount->forceFill(['google_id' => '200000000000000000000'])->save();

        $this->postLink(password: 'not-the-password')
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_password');

        $this->assertSame('200000000000000000000', $this->visitorAccount->fresh()->google_id);
    }

    public function test_password_hash_itself_is_not_accepted_as_the_password(): void
    {
        $this->postLink(password: $this->visitorAccount->fresh()->password_hash)
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_password');

        $this->assertNull($this->visitorAccount->fresh()->google_id);
    }

    // ---------------------------------------------------------------
    // 3. Staff
    // ---------------------------------------------------------------

    public static function staffEmailProvider(): array
    {
        return [
            'Record Officer' => ['r.salcedo@bjmp.gov.ph'],
            'Front Desk Officer' => ['n.fernandez@bjmp.gov.ph'],
        ];
    }

    #[DataProvider('staffEmailProvider')]
    public function test_staff_with_correct_password_is_rejected(string $email): void
    {
        $staff = Account::where('email', $email)->firstOrFail();
        $before = $staff->getAttributes();

        $response = $this->postLink(['email' => $email])
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'Google sign-in is only available for visitor accounts.',
                'code' => 'not_visitor_account',
            ]);

        $this->assertNoTokenIssued($response);
        $this->assertEquals($before, $staff->fresh()->getAttributes());
    }

    public function test_staff_is_rejected_before_the_password_is_checked(): void
    {
        // A wrong password still gets not_visitor_account: staff is refused
        // before the password is ever considered.
        $this->postLink(['email' => 'r.salcedo@bjmp.gov.ph'], password: 'wrong')
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_visitor_account');

        $this->assertNull($this->recordOfficerAccount->fresh()->google_id);
    }

    // ---------------------------------------------------------------
    // 4. Inactive Visitor
    // ---------------------------------------------------------------

    public static function inactiveStatusProvider(): array
    {
        return [['inactive'], ['suspended']];
    }

    #[DataProvider('inactiveStatusProvider')]
    public function test_inactive_visitor_with_correct_password_is_rejected(string $status): void
    {
        $this->visitorAccount->update(['status' => $status]);
        $before = $this->visitorAccount->fresh()->getAttributes();

        $response = $this->postLink()
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'This account is not active. Contact facility staff.',
                'code' => 'account_inactive',
            ]);

        $this->assertNoTokenIssued($response);
        $fresh = $this->visitorAccount->fresh();
        $this->assertSame($status, $fresh->status);
        $this->assertNull($fresh->google_id);
        $this->assertNull($fresh->last_login_at);
        $this->assertEquals($before, $fresh->getAttributes());
    }

    // ---------------------------------------------------------------
    // 5. Account not found
    // ---------------------------------------------------------------

    public function test_unknown_google_email_is_rejected_and_nothing_is_created(): void
    {
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postLink(['email' => 'new.person@gmail.com'])
            ->assertStatus(404)
            ->assertExactJson([
                'message' => 'No existing Custodicore account was found for this Google account.',
                'code' => 'account_not_found',
            ]);

        $this->assertNoTokenIssued($response);
        $this->assertSame($accountsBefore, Account::count());
        $this->assertSame($profilesBefore, VisitorProfile::count());
        $this->assertSame(0, Account::whereNotNull('google_id')->count());
    }

    // ---------------------------------------------------------------
    // 6. Google identity collision
    // ---------------------------------------------------------------

    public function test_google_sub_linked_to_another_account_is_rejected(): void
    {
        // Another visitor already owns this Google identity.
        $other = $this->makeVisitorAccount('other.visitor@example.com');
        $other->forceFill(['google_id' => self::GOOGLE_SUB])->save();
        $otherBefore = $other->fresh()->getAttributes();
        $mariaBefore = $this->visitorAccount->fresh()->getAttributes();

        $response = $this->postLink()
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'This Google account is already linked to another Custodicore account.',
                'code' => 'google_account_mismatch',
            ]);

        $this->assertNoTokenIssued($response);
        $this->assertEquals($otherBefore, $other->fresh()->getAttributes());
        $this->assertEquals($mariaBefore, $this->visitorAccount->fresh()->getAttributes());
    }

    public function test_account_linked_to_a_different_google_identity_is_not_replaced(): void
    {
        $this->visitorAccount->forceFill(['google_id' => '200000000000000000000'])->save();
        $before = $this->visitorAccount->fresh()->getAttributes();

        $response = $this->postLink()
            ->assertStatus(409)
            ->assertJsonPath('code', 'google_account_mismatch');

        $this->assertNoTokenIssued($response);
        $this->assertEquals($before, $this->visitorAccount->fresh()->getAttributes());
        $this->assertSame(0, Account::where('google_id', self::GOOGLE_SUB)->count());
    }

    // ---------------------------------------------------------------
    // 7. Already linked to the same identity
    // ---------------------------------------------------------------

    public function test_already_linked_same_identity_is_idempotent(): void
    {
        $this->visitorAccount->forceFill(['google_id' => self::GOOGLE_SUB])->save();
        $accountsBefore = Account::count();

        $first = $this->postLink()->assertOk()->assertJsonPath('status', 'authenticated');
        $second = $this->postLink()->assertOk()->assertJsonPath('status', 'authenticated');

        $this->assertNotSame($first->json('token'), $second->json('token'));
        $this->assertSame($accountsBefore, Account::count());
        $this->assertSame(1, Account::where('google_id', self::GOOGLE_SUB)->count());
        $this->assertSame(self::GOOGLE_SUB, $this->visitorAccount->fresh()->google_id);
        // One token per sign-in, nothing extra.
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_already_linked_same_identity_does_not_need_the_password_again(): void
    {
        // The verified token alone already authenticates this account on
        // /auth/google; the link endpoint behaves the same.
        $this->visitorAccount->forceFill(['google_id' => self::GOOGLE_SUB])->save();

        $this->postLink(password: 'anything')
            ->assertOk()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('user.id', (string) $this->visitorAccount->account_id);
    }

    public function test_linking_twice_does_not_create_duplicates(): void
    {
        $this->postLink()->assertOk();
        $this->postLink()->assertOk();

        $this->assertSame(1, Account::where('google_id', self::GOOGLE_SUB)->count());
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    // ---------------------------------------------------------------
    // 8 & 9. Client spoofing
    // ---------------------------------------------------------------

    public function test_client_supplied_email_is_ignored(): void
    {
        // Token is for an unknown Google email; the body claims Maria's.
        $response = $this->postLink(['email' => 'new.person@gmail.com'], [
            'email' => self::VISITOR_EMAIL,
            'account_id' => $this->visitorAccount->account_id,
            'accountId' => $this->visitorAccount->account_id,
        ])->assertStatus(404)->assertJsonPath('code', 'account_not_found');

        $this->assertNoTokenIssued($response);
        $this->assertNull($this->visitorAccount->fresh()->google_id);
    }

    public function test_client_supplied_email_cannot_redirect_the_link_to_another_account(): void
    {
        // Verified email is Maria's; the body names a staff account.
        $this->postLink([], ['email' => 'r.salcedo@bjmp.gov.ph'])
            ->assertOk()
            ->assertJsonPath('user.email', self::VISITOR_EMAIL);

        $this->assertSame(self::GOOGLE_SUB, $this->visitorAccount->fresh()->google_id);
        $this->assertNull($this->recordOfficerAccount->fresh()->google_id);
    }

    public function test_client_supplied_google_id_is_ignored(): void
    {
        $this->postLink([], [
            'googleId' => '999999999999999999999',
            'google_id' => '999999999999999999999',
            'sub' => '999999999999999999999',
        ])->assertOk();

        $this->assertSame(self::GOOGLE_SUB, $this->visitorAccount->fresh()->google_id);
        $this->assertSame(0, Account::where('google_id', '999999999999999999999')->count());
    }

    // ---------------------------------------------------------------
    // 10. Token validation
    // ---------------------------------------------------------------

    public static function invalidTokenProvider(): array
    {
        $now = time();

        return [
            'expired' => [['iat' => $now - 7200, 'exp' => $now - 3600], 'google'],
            'wrong audience' => [['aud' => 'other.apps.googleusercontent.com'], 'google'],
            'unverified email' => [['email_verified' => false], 'google'],
            'forged signature' => [[], 'attacker'],
        ];
    }

    #[DataProvider('invalidTokenProvider')]
    public function test_invalid_google_token_is_rejected_without_changes(array $claims, string $key): void
    {
        $before = $this->visitorAccount->fresh()->getAttributes();

        $response = $this->postJson('/api/auth/google/link', [
            'idToken' => $this->googleIdToken($claims + ['email' => self::VISITOR_EMAIL], [], $key),
            'password' => self::PASSWORD,
        ])
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Google Sign-In failed. Please try again.', 'code' => 'invalid_google_token']);

        $this->assertNoTokenIssued($response);
        $this->assertEquals($before, $this->visitorAccount->fresh()->getAttributes());
    }

    public function test_google_unavailable_returns_503_without_changes(): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier(jwks: 'network_error'));

        $response = $this->postLink()->assertStatus(503)->assertJsonPath('code', 'google_unavailable');

        $this->assertNoTokenIssued($response);
        $this->assertNull($this->visitorAccount->fresh()->google_id);
    }

    public function test_missing_token_or_password_fails_validation(): void
    {
        $token = $this->googleIdToken(['email' => self::VISITOR_EMAIL]);

        $this->postJson('/api/auth/google/link', ['password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors('idToken');
        $this->postJson('/api/auth/google/link', ['idToken' => $token])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/google/link', ['idToken' => $token, 'password' => ['x']])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertNull($this->visitorAccount->fresh()->google_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ---------------------------------------------------------------
    // 11. Atomicity
    // ---------------------------------------------------------------

    public function test_failure_during_token_creation_rolls_back_the_link(): void
    {
        PersonalAccessToken::creating(function () {
            throw new RuntimeException('Simulated failure after google_id was assigned.');
        });

        $this->postLink()->assertStatus(500);

        $fresh = $this->visitorAccount->fresh();
        $this->assertNull($fresh->google_id);
        $this->assertNull($fresh->last_login_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ---------------------------------------------------------------
    // Secrets
    // ---------------------------------------------------------------

    public function test_responses_and_logs_never_contain_the_token_or_password(): void
    {
        Log::spy();
        $token = $this->googleIdToken(['email' => self::VISITOR_EMAIL]);
        [, $payloadSegment] = explode('.', $token);

        foreach (['wrong-secret-pw', self::PASSWORD] as $password) {
            $body = $this->postJson('/api/auth/google/link', ['idToken' => $token, 'password' => $password])->getContent();

            $this->assertStringNotContainsString($payloadSegment, $body);
            $this->assertStringNotContainsString(self::GOOGLE_SUB, $body);
            $this->assertStringNotContainsString($password, $body);
        }

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    private function makeVisitorAccount(string $email): Account
    {
        return Account::create([
            'role_id' => $this->visitorAccount->role_id,
            'username' => strstr($email, '@', true),
            'email' => $email,
            'password_hash' => Hash::make(self::PASSWORD),
            'status' => 'active',
        ]);
    }
}
