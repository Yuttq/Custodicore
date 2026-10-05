<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\VisitorProfile;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\GoogleIdTokenFixtures;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * POST /api/auth/google/register, phase 5 — creates a new Visitor account
 * for a VERIFIED Google identity nobody owns yet (see
 * App\Services\Auth\GoogleAuthService::register()). Email and Google ID come
 * only from the verified token; the visitor still sets a Custodicore
 * password; existing accounts are never auto-linked, converted or reactivated.
 */
class GoogleRegistrationTest extends TestCase
{
    use GoogleIdTokenFixtures;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const GOOGLE_SUB = '109876543210987654321';
    private const GOOGLE_EMAIL = 'google.visitor@gmail.com'; // GoogleIdTokenFixtures default
    private const NEW_PASSWORD = 'new-secret-pw';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'fullName' => 'Juan G. Dela Cruz',
            'dateOfBirth' => '1990-05-15',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            'contactNumber' => '09170002222',
            'gender' => 'male',
            'address' => 'Quezon City',
            'relationshipHint' => 'sibling',
            'acceptedTerms' => true,
            'acceptedPrivacy' => true,
            'consentVersion' => (string) config('legal.version'),
        ], $overrides);
    }

    private function postRegister(array $claims = [], array $form = [], string $key = 'google')
    {
        return $this->postJson('/api/auth/google/register', [
            'idToken' => $this->googleIdToken($claims, [], $key),
        ] + $this->form($form));
    }

    private function assertNothingCreated($response, int $accountsBefore, int $profilesBefore): void
    {
        $response->assertJsonMissingPath('token')->assertJsonMissingPath('user');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame($accountsBefore, Account::count());
        $this->assertSame($profilesBefore, VisitorProfile::count());
        $this->assertSame(0, Account::where('google_id', self::GOOGLE_SUB)->count());
    }

    // ---------------------------------------------------------------
    // A & K. Successful registration
    // ---------------------------------------------------------------

    public function test_valid_token_and_form_create_a_linked_visitor_and_authenticate(): void
    {
        $accountsBefore = Account::count();

        $response = $this->postRegister();

        $response->assertCreated()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('user.email', self::GOOGLE_EMAIL)
            ->assertJsonPath('user.fullName', 'Juan G. Dela Cruz')
            ->assertJsonPath('user.role', 'Visitor')
            ->assertJsonPath('user.verificationStatus', 'pending')
            ->assertJsonPath('user.dateOfBirth', '1990-05-15')
            ->assertJsonPath('user.gender', 'male')
            ->assertJsonPath('user.contactNumber', '09170002222')
            ->assertJsonPath('user.relationshipHint', 'sibling')
            ->assertJsonStructure(['status', 'token', 'user' => [
                'id', 'email', 'fullName', 'role', 'verificationStatus', 'verifiedAt',
                'dateOfBirth', 'gender', 'address', 'contactNumber',
                'emergencyContactName', 'emergencyContactNumber', 'relationshipHint',
            ]])
            ->assertJsonMissingPath('user.google_id')
            ->assertJsonMissingPath('user.password_hash');

        $this->assertSame($accountsBefore + 1, Account::count());

        $account = Account::where('email', self::GOOGLE_EMAIL)->firstOrFail();
        $this->assertSame((string) $account->account_id, $response->json('user.id'));
        $this->assertTrue($account->isVisitor());
        $this->assertSame('active', $account->status);
        $this->assertSame(self::GOOGLE_SUB, $account->google_id);
        $this->assertSame('google.visitor', $account->username);

        // Normal Custodicore password, hashed.
        $this->assertNotSame(self::NEW_PASSWORD, $account->password_hash);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $account->password_hash));

        // Consent recorded server-side, as register().
        $this->assertNotNull($account->terms_accepted_at);
        $this->assertNotNull($account->privacy_accepted_at);
        $this->assertSame((string) config('legal.version'), $account->consent_version);

        $profile = $account->visitorProfile;
        $this->assertNotNull($profile);
        $this->assertSame('Juan G. Dela Cruz', $profile->full_name);
        $this->assertSame('pending', $profile->verification_status);
        $this->assertSame('Quezon City', $profile->address);
        $this->assertDatabaseMissing('visitor_pdl_relationships', ['visitor_id' => $profile->visitor_id]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertSame('mobile-google', $token->name);
        $this->assertTrue($token->tokenable->is($account));
    }

    public function test_after_registering_token_works_and_google_and_password_sign_in_authenticate(): void
    {
        $token = $this->postRegister()->assertCreated()->json('token');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('email', self::GOOGLE_EMAIL);

        $this->postJson('/api/auth/google', ['idToken' => $this->googleIdToken()])
            ->assertOk()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('user.email', self::GOOGLE_EMAIL);

        $this->postJson('/api/auth/login', ['email' => self::GOOGLE_EMAIL, 'password' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.email', self::GOOGLE_EMAIL);
    }

    public function test_accepts_snake_case_fields(): void
    {
        $this->postJson('/api/auth/google/register', [
            'id_token' => $this->googleIdToken(),
            'full_name' => 'Snake Case',
            'date_of_birth' => '1991-01-01',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            'accepted_terms' => true,
            'accepted_privacy' => true,
            'consent_version' => (string) config('legal.version'),
        ])->assertCreated()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('user.fullName', 'Snake Case')
            ->assertJsonPath('user.contactNumber', null);
    }

    // ---------------------------------------------------------------
    // B. Client-supplied identity is ignored
    // ---------------------------------------------------------------

    public function test_client_supplied_email_google_id_role_and_status_are_ignored(): void
    {
        $this->postRegister([], [
            'email' => 'attacker@example.com',
            'googleId' => '999999999999999999999',
            'google_id' => '999999999999999999999',
            'sub' => '999999999999999999999',
            'role' => 'System Administrator',
            'role_id' => $this->recordOfficerRole->role_id,
            'status' => 'inactive',
        ])->assertCreated()->assertJsonPath('user.email', self::GOOGLE_EMAIL)
            ->assertJsonPath('user.role', 'Visitor');

        $account = Account::where('email', self::GOOGLE_EMAIL)->firstOrFail();
        $this->assertSame(self::GOOGLE_SUB, $account->google_id);
        $this->assertSame($this->visitorRole->role_id, $account->role_id);
        $this->assertSame('active', $account->status);
        $this->assertSame(0, Account::where('email', 'attacker@example.com')->count());
        $this->assertSame(0, Account::where('google_id', '999999999999999999999')->count());
    }

    public function test_client_email_of_an_existing_account_does_not_block_or_redirect(): void
    {
        // Body names Maria; the verified token is a new Google user.
        $this->postRegister([], ['email' => 'maria.santos@example.com'])
            ->assertCreated()
            ->assertJsonPath('user.email', self::GOOGLE_EMAIL);

        $this->assertNull($this->visitorAccount->fresh()->google_id);
    }

    // ---------------------------------------------------------------
    // C. Google ID already linked
    // ---------------------------------------------------------------

    public function test_google_id_already_linked_creates_no_second_account(): void
    {
        $this->visitorAccount->forceFill(['google_id' => self::GOOGLE_SUB])->save();
        $before = $this->visitorAccount->fresh()->getAttributes();
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister()
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'This Google account is already linked to another Custodicore account.',
                'code' => 'google_account_mismatch',
            ]);

        $response->assertJsonMissingPath('token');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame($accountsBefore, Account::count());
        $this->assertSame($profilesBefore, VisitorProfile::count());
        $this->assertSame(0, Account::where('email', self::GOOGLE_EMAIL)->count());
        $this->assertEquals($before, $this->visitorAccount->fresh()->getAttributes());
    }

    public function test_google_id_linked_to_an_inactive_account_is_rejected_without_reactivating(): void
    {
        $this->visitorAccount->forceFill(['google_id' => self::GOOGLE_SUB, 'status' => 'suspended'])->save();

        $this->postRegister()
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_inactive');

        $this->assertSame('suspended', $this->visitorAccount->fresh()->status);
        $this->assertSame(0, Account::where('email', self::GOOGLE_EMAIL)->count());
    }

    // ---------------------------------------------------------------
    // D. Existing Visitor email — never auto-linked
    // ---------------------------------------------------------------

    public static function existingEmailProvider(): array
    {
        return [
            'same case' => ['maria.santos@example.com'],
            'different case' => ['Maria.Santos@Example.COM'],
        ];
    }

    #[DataProvider('existingEmailProvider')]
    public function test_existing_visitor_email_requires_linking_and_is_not_auto_linked(string $googleEmail): void
    {
        $before = $this->visitorAccount->fresh()->getAttributes();
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister(['email' => $googleEmail])
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'This email already has a Custodicore account. Confirm your Custodicore password before linking Google.',
                'code' => 'link_required',
            ]);

        $this->assertNothingCreated($response, $accountsBefore, $profilesBefore);
        $this->assertEquals($before, $this->visitorAccount->fresh()->getAttributes());
    }

    // ---------------------------------------------------------------
    // E. Staff
    // ---------------------------------------------------------------

    public static function staffEmailProvider(): array
    {
        return [
            'Record Officer' => ['r.salcedo@bjmp.gov.ph'],
            'Front Desk Officer' => ['n.fernandez@bjmp.gov.ph'],
        ];
    }

    #[DataProvider('staffEmailProvider')]
    public function test_staff_email_is_rejected_and_not_converted(string $email): void
    {
        $staff = Account::where('email', $email)->firstOrFail();
        $before = $staff->getAttributes();
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister(['email' => $email])
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'Google sign-in is only available for visitor accounts.',
                'code' => 'not_visitor_account',
            ]);

        $this->assertNothingCreated($response, $accountsBefore, $profilesBefore);
        $this->assertEquals($before, $staff->fresh()->getAttributes());
    }

    // ---------------------------------------------------------------
    // F. Inactive account
    // ---------------------------------------------------------------

    public static function inactiveStatusProvider(): array
    {
        return [['inactive'], ['suspended']];
    }

    #[DataProvider('inactiveStatusProvider')]
    public function test_inactive_account_email_is_rejected_and_not_reactivated(string $status): void
    {
        $this->visitorAccount->update(['status' => $status]);
        $before = $this->visitorAccount->fresh()->getAttributes();
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister(['email' => 'maria.santos@example.com'])
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'This account is not active. Contact facility staff.',
                'code' => 'account_inactive',
            ]);

        $this->assertNothingCreated($response, $accountsBefore, $profilesBefore);
        $this->assertSame($status, $this->visitorAccount->fresh()->status);
        $this->assertEquals($before, $this->visitorAccount->fresh()->getAttributes());
    }

    // ---------------------------------------------------------------
    // G & H. Form validation (same contract as /auth/register)
    // ---------------------------------------------------------------

    public static function invalidFormProvider(): array
    {
        return [
            'terms not accepted' => [['acceptedTerms' => false], 'acceptedTerms'],
            'privacy not accepted' => [['acceptedPrivacy' => false], 'acceptedPrivacy'],
            'terms missing' => [['acceptedTerms' => null], 'acceptedTerms'],
            'stale consent version' => [['consentVersion' => '2000-01-01'], 'consentVersion'],
            'password mismatch' => [['password_confirmation' => 'something-else'], 'password'],
            'password confirmation missing' => [['password_confirmation' => null], 'password_confirmation'],
            'password too short' => [['password' => 'abc', 'password_confirmation' => 'abc'], 'password'],
            'password missing' => [['password' => null, 'password_confirmation' => null], 'password'],
            'full name missing' => [['fullName' => null], 'fullName'],
            'date of birth missing' => [['dateOfBirth' => null], 'dateOfBirth'],
            'date of birth in the future' => [['dateOfBirth' => '2999-01-01'], 'dateOfBirth'],
            'invalid gender' => [['gender' => 'robot'], 'gender'],
            'contact number too long' => [['contactNumber' => str_repeat('9', 21)], 'contactNumber'],
        ];
    }

    #[DataProvider('invalidFormProvider')]
    public function test_invalid_form_is_rejected_and_nothing_is_created(array $form, string $field): void
    {
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister([], $form)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertNothingCreated($response, $accountsBefore, $profilesBefore);
    }

    public function test_consent_messages_match_normal_registration(): void
    {
        $this->postRegister([], ['acceptedTerms' => false, 'acceptedPrivacy' => false, 'consentVersion' => 'old'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'acceptedTerms' => 'You must accept the Terms and Conditions to register.',
                'acceptedPrivacy' => 'You must accept the Privacy Policy to register.',
                'consentVersion' => 'The Terms and Privacy Policy were updated. Please review and accept the latest version.',
            ]);
    }

    public function test_consent_version_is_optional_and_always_stored_from_config(): void
    {
        $this->postRegister([], ['consentVersion' => null])->assertCreated();

        $this->assertSame(
            (string) config('legal.version'),
            Account::where('email', self::GOOGLE_EMAIL)->value('consent_version'),
        );
    }

    public function test_missing_id_token_fails_validation(): void
    {
        $this->postJson('/api/auth/google/register', $this->form())
            ->assertStatus(422)
            ->assertJsonValidationErrors('idToken');

        $this->assertSame(0, Account::where('email', self::GOOGLE_EMAIL)->count());
    }

    public function test_verified_email_longer_than_the_accounts_column_is_rejected(): void
    {
        $longEmail = str_repeat('a', 64).'@'.str_repeat('b', 40).'.example.com'; // > 100
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister(['email' => $longEmail])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertNothingCreated($response, $accountsBefore, $profilesBefore);
    }

    public function test_long_local_part_fits_the_username_column(): void
    {
        $email = str_repeat('x', 64).'@gmail.com';

        $this->postRegister(['email' => $email])->assertCreated();

        $username = Account::where('email', $email)->value('username');
        $this->assertLessThanOrEqual(50, Str::length($username));
    }

    // ---------------------------------------------------------------
    // I. Invalid Google token
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
    public function test_invalid_google_token_is_rejected_without_creating_an_account(array $claims, string $key): void
    {
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister($claims, [], $key)
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Google Sign-In failed. Please try again.', 'code' => 'invalid_google_token']);

        $this->assertNothingCreated($response, $accountsBefore, $profilesBefore);
    }

    public function test_google_unavailable_returns_503_without_creating_an_account(): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier(jwks: 'network_error'));
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister()->assertStatus(503)->assertJsonPath('code', 'google_unavailable');

        $this->assertNothingCreated($response, $accountsBefore, $profilesBefore);
    }

    // ---------------------------------------------------------------
    // J. Races / duplicates / atomicity
    // ---------------------------------------------------------------

    public function test_registering_twice_does_not_create_a_second_account(): void
    {
        $this->postRegister()->assertCreated();
        $accountsAfterFirst = Account::count();

        $this->postRegister()->assertStatus(409)->assertJsonPath('code', 'google_account_mismatch');

        $this->assertSame($accountsAfterFirst, Account::count());
        $this->assertSame(1, Account::where('google_id', self::GOOGLE_SUB)->count());
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /**
     * Simulates a concurrent request winning the race: right after the
     * pre-checks (the case-insensitive email lookup) another account takes
     * the Google ID or email, so the insert trips the unique index.
     */
    private function raceAfterPreChecks(callable $winner): void
    {
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $winner) {
            if (! $fired && str_contains($query->sql, 'LOWER(email)')) {
                $fired = true;
                $winner();
            }
        });
    }

    public function test_concurrent_registration_with_the_same_google_id_returns_409(): void
    {
        $this->raceAfterPreChecks(function () {
            $winner = Account::create([
                'role_id' => $this->visitorRole->role_id,
                'username' => 'race.winner',
                'email' => 'race.winner@example.com',
                'password_hash' => Hash::make('x'),
                'status' => 'active',
            ]);
            $winner->forceFill(['google_id' => self::GOOGLE_SUB])->save();
        });
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister()
            ->assertStatus(409)
            ->assertJsonPath('code', 'google_account_mismatch');

        $response->assertJsonMissingPath('token');
        $this->assertSame(0, Account::where('email', self::GOOGLE_EMAIL)->count());
        $this->assertSame(1, Account::where('google_id', self::GOOGLE_SUB)->count());
        $this->assertSame($profilesBefore, VisitorProfile::count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_concurrent_registration_with_the_same_email_returns_409(): void
    {
        $this->raceAfterPreChecks(function () {
            Account::create([
                'role_id' => $this->visitorRole->role_id,
                'username' => 'race.winner',
                'email' => self::GOOGLE_EMAIL,
                'password_hash' => Hash::make('x'),
                'status' => 'active',
            ]);
        });
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister()
            ->assertStatus(409)
            ->assertJsonPath('code', 'link_required');

        $response->assertJsonMissingPath('token');
        $this->assertSame(1, Account::where('email', self::GOOGLE_EMAIL)->count());
        $this->assertSame(0, Account::where('google_id', self::GOOGLE_SUB)->count());
        $this->assertSame($profilesBefore, VisitorProfile::count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_concurrent_username_collision_is_retried(): void
    {
        // Another account takes the generated username after it was chosen.
        $fired = false;
        Account::creating(function (Account $account) use (&$fired) {
            if (! $fired && $account->email === self::GOOGLE_EMAIL) {
                $fired = true;
                DB::table('accounts')->insert([
                    'role_id' => $this->visitorRole->role_id,
                    'username' => $account->username,
                    'email' => 'someone.else@example.com',
                    'password_hash' => 'x',
                    'status' => 'active',
                ]);
            }
        });

        // The colliding row is rolled back with the failed attempt; the retry succeeds.
        $this->postRegister()->assertCreated()->assertJsonPath('status', 'authenticated');

        $this->assertSame(1, Account::where('google_id', self::GOOGLE_SUB)->count());
        $this->assertSame(1, VisitorProfile::whereHas('account', fn ($q) => $q->where('email', self::GOOGLE_EMAIL))->count());
    }

    public function test_failure_during_token_creation_rolls_back_everything(): void
    {
        PersonalAccessToken::creating(function () {
            throw new RuntimeException('Simulated failure after the account was created and linked.');
        });
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister()->assertStatus(500);

        $this->assertNothingCreated($response, $accountsBefore, $profilesBefore);
        $this->assertSame(0, Account::where('email', self::GOOGLE_EMAIL)->count());
    }

    public function test_failure_during_profile_creation_leaves_no_account(): void
    {
        VisitorProfile::creating(function () {
            throw new RuntimeException('Simulated profile failure.');
        });
        $accountsBefore = Account::count();
        $profilesBefore = VisitorProfile::count();

        $response = $this->postRegister()->assertStatus(500);

        $this->assertNothingCreated($response, $accountsBefore, $profilesBefore);
    }

    // ---------------------------------------------------------------
    // Secrets
    // ---------------------------------------------------------------

    public function test_responses_and_logs_never_contain_the_token_password_or_google_id(): void
    {
        Log::spy();
        $token = $this->googleIdToken();
        [, $payloadSegment] = explode('.', $token);

        $responses = [
            $this->postJson('/api/auth/google/register', ['idToken' => $token] + $this->form()),           // 201
            $this->postJson('/api/auth/google/register', ['idToken' => $token] + $this->form()),           // 409
            $this->postJson('/api/auth/google/register', ['idToken' => $token] + $this->form(['password_confirmation' => 'nope'])), // 422
        ];

        foreach ($responses as $response) {
            $body = $response->getContent();
            $this->assertStringNotContainsString($payloadSegment, $body);
            $this->assertStringNotContainsString(self::GOOGLE_SUB, $body);
            $this->assertStringNotContainsString(self::NEW_PASSWORD, $body);
            $this->assertStringNotContainsString('$2y$', $body);
        }

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }
}
