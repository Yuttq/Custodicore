<?php

namespace Tests\Feature;

use App\Mail\ResetAccountPassword;
use App\Models\Account;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Support\VisitorPasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GoogleIdTokenFixtures;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * The visitor password policy (App\Support\VisitorPasswordPolicy) on every
 * path that creates a password — API registration, Google registration,
 * emailed reset — and NOT on the paths that check an existing one (login,
 * Google linking), so accounts with older, shorter passwords keep working.
 */
class VisitorPasswordPolicyTest extends TestCase
{
    use GoogleIdTokenFixtures;
    use MakesTestImages;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const REGISTER_EMAIL = 'policy.visitor@example.com';
    private const GOOGLE_EMAIL = 'google.visitor@gmail.com'; // GoogleIdTokenFixtures default

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedPhase1Fixtures();
        Mail::fake();
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());
    }

    public static function validPasswords(): array
    {
        return [
            'exactly 12 characters' => ['Abcdefgh12!x'],
            'exactly 64 characters' => ['Aa1!'.str_repeat('x', 60)],
            'every kind of symbol' => ['Zz9!"#$%&\'()*+,-./:;<=>?@[\]^_`{|}~'],
        ];
    }

    public static function invalidPasswords(): array
    {
        return [
            '11 characters' => ['Abcdefgh1!x'],
            '65 characters' => ['Aa1!'.str_repeat('x', 61)],
            'no uppercase letter' => ['abcdefgh123!'],
            'no lowercase letter' => ['ABCDEFGH123!'],
            'no number' => ['Abcdefghijk!'],
            'no special character' => ['Abcdefghij12'],
            'leading space' => [' Abcdefgh12!'],
            'space in the middle' => ['Abcdef gh12!'],
            'trailing space' => ['Abcdefgh12! '],
            // Laravel's Password::symbols() would count the space as the symbol.
            'space as the only symbol' => ['Abcdef gh123'],
            'tab' => ["Abcdef\tgh12!"],
            'non-breaking space' => ["Abcdef\u{00A0}gh12!"],
            // Non-ASCII could push 64 characters past bcrypt's 72 bytes.
            'non-ASCII letter' => ['Contraseña12!'],
            // Password::numbers() would accept any Unicode digit.
            'non-ASCII digit as the only number' => ["Abcdefghijk\u{0663}!"],
        ];
    }

    // ---------------------------------------------------------------
    // API registration
    // ---------------------------------------------------------------

    private function registration(array $overrides = []): array
    {
        return array_filter(array_merge([
            'fullName' => 'Ana Reyes',
            'email' => self::REGISTER_EMAIL,
            'password' => 'Secret-pass-1',
            'password_confirmation' => 'Secret-pass-1',
            'dateOfBirth' => '1992-02-02',
            'gender' => 'female',
            'intendedPdlName' => 'Pedro Santos Reyes',
            'contactNumber' => '09170002222',
            'acceptedTerms' => true,
            'acceptedPrivacy' => true,
            'photo' => $this->fakePhoto(),
            'consentVersion' => (string) config('legal.version'),
        ], $overrides), fn ($value) => $value !== null);
    }

    #[DataProvider('validPasswords')]
    public function test_registration_accepts_a_valid_password_and_stores_only_a_hash(string $password): void
    {
        $this->postJson('/api/auth/register', $this->registration([
            'password' => $password,
            'password_confirmation' => $password,
        ]))
            ->assertCreated()
            ->assertExactJson([
                'status' => 'verification_required',
                'message' => 'Your account has been created. We sent a verification link to your email address. Please verify your email before logging in.',
                'email' => self::REGISTER_EMAIL,
            ]);

        $hash = Account::where('email', self::REGISTER_EMAIL)->value('password_hash');
        $this->assertNotSame($password, $hash);
        $this->assertTrue(Hash::isHashed($hash));
        $this->assertTrue(Hash::check($password, $hash));
    }

    #[DataProvider('invalidPasswords')]
    public function test_registration_rejects_a_password_that_breaks_the_policy(string $password): void
    {
        $this->postJson('/api/auth/register', $this->registration([
            'password' => $password,
            'password_confirmation' => $password,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('accounts', ['email' => self::REGISTER_EMAIL]);
    }

    public function test_registration_requires_the_confirmation(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['password_confirmation' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password_confirmation');

        $this->assertDatabaseMissing('accounts', ['email' => self::REGISTER_EMAIL]);
    }

    public function test_registration_rejects_a_mismatched_confirmation(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['password_confirmation' => 'secret-pass-1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('accounts', ['email' => self::REGISTER_EMAIL]);
    }

    public function test_registration_lists_every_unmet_requirement(): void
    {
        $errors = $this->postJson('/api/auth/register', $this->registration([
            'password' => 'abc def',
            'password_confirmation' => 'abc def',
        ]))->assertStatus(422)->json('errors.password');

        $this->assertEqualsCanonicalizing([
            'Your password must be at least 12 characters.',
            'Your password cannot contain spaces.',
            'Your password must contain at least one uppercase letter (A-Z).',
            'Your password must contain at least one number (0-9).',
            'Your password must contain at least one special character (such as ! @ # $ %).',
        ], $errors);
    }

    // ---------------------------------------------------------------
    // Google registration (creates a Custodicore password)
    // ---------------------------------------------------------------

    private function postGoogleRegister(string $password, ?string $confirmation = null)
    {
        return $this->postJson('/api/auth/google/register', array_filter([
            'idToken' => $this->googleIdToken(),
            'fullName' => 'Juan G. Dela Cruz',
            'dateOfBirth' => '1990-05-15',
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
            'gender' => 'male',
            'intendedPdlName' => 'Pedro Santos Reyes',
            'acceptedTerms' => true,
            'acceptedPrivacy' => true,
            'photo' => $this->fakePhoto(),
            'consentVersion' => (string) config('legal.version'),
        ], fn ($value) => $value !== null));
    }

    #[DataProvider('validPasswords')]
    public function test_google_registration_accepts_a_valid_password(string $password): void
    {
        $this->postGoogleRegister($password)
            ->assertCreated()
            ->assertJsonPath('status', 'authenticated');

        $hash = Account::where('email', self::GOOGLE_EMAIL)->value('password_hash');
        $this->assertNotSame($password, $hash);
        $this->assertTrue(Hash::check($password, $hash));
    }

    #[DataProvider('invalidPasswords')]
    public function test_google_registration_rejects_a_password_that_breaks_the_policy(string $password): void
    {
        $this->postGoogleRegister($password)
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('accounts', ['email' => self::GOOGLE_EMAIL]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_google_registration_rejects_a_mismatched_confirmation(): void
    {
        $this->postGoogleRegister('Abcdefgh12!x', 'Abcdefgh12!y')
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('accounts', ['email' => self::GOOGLE_EMAIL]);
    }

    // ---------------------------------------------------------------
    // Password reset
    // ---------------------------------------------------------------

    private function resetToken(): string
    {
        $this->postJson('/api/auth/password/forgot', ['email' => $this->visitorAccount->email])->assertOk();

        $url = null;
        Mail::assertSent(ResetAccountPassword::class, function (ResetAccountPassword $m) use (&$url) {
            $url = $m->resetUrl;

            return true;
        });

        return rawurldecode(substr(parse_url($url, PHP_URL_PATH), strlen('/password/reset/')));
    }

    private function submitReset(string $password, ?string $confirmation = null)
    {
        return $this->post('/password/reset', [
            'token' => $this->resetToken(),
            'email' => $this->visitorAccount->email,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    #[DataProvider('validPasswords')]
    public function test_reset_accepts_a_valid_password(string $password): void
    {
        $this->submitReset($password)->assertOk()->assertSee('Your password has been reset');

        $hash = $this->visitorAccount->fresh()->password_hash;
        $this->assertNotSame($password, $hash);
        $this->assertTrue(Hash::check($password, $hash));
    }

    #[DataProvider('invalidPasswords')]
    public function test_reset_rejects_a_password_that_breaks_the_policy(string $password): void
    {
        $this->submitReset($password)->assertRedirect()->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $this->visitorAccount->fresh()->password_hash));
    }

    public function test_reset_rejects_a_mismatched_confirmation(): void
    {
        $this->submitReset('Abcdefgh12!x', 'Abcdefgh12!y')->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $this->visitorAccount->fresh()->password_hash));
    }

    public function test_reset_still_refuses_the_email_address_as_the_password(): void
    {
        $email = 'Maria.Santos1@example.com'; // passes every other rule
        $this->visitorAccount->forceFill(['email' => $email])->save();

        $this->submitReset($email)->assertSessionHasErrors([
            'password' => 'Your password cannot be the same as your email address.',
        ]);

        $this->assertTrue(Hash::check('password', $this->visitorAccount->fresh()->password_hash));
    }

    public function test_reset_page_shows_the_policy(): void
    {
        $this->get('/password/reset/some-token')
            ->assertOk()
            ->assertSee(VisitorPasswordPolicy::REQUIREMENTS);
    }

    // ---------------------------------------------------------------
    // Existing passwords are NOT held to the policy
    // ---------------------------------------------------------------

    public function test_existing_account_with_a_short_password_can_still_log_in(): void
    {
        $this->visitorAccount->forceFill(['password_hash' => Hash::make('abc123')])->save();

        $this->postJson('/api/auth/login', ['email' => $this->visitorAccount->email, 'password' => 'abc123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.email', $this->visitorAccount->email);
    }

    public function test_google_linking_accepts_an_existing_short_password(): void
    {
        $this->visitorAccount->forceFill(['password_hash' => Hash::make('abc123')])->save();
        $hashBefore = $this->visitorAccount->fresh()->password_hash;

        $this->postJson('/api/auth/google/link', [
            'idToken' => $this->googleIdToken(['email' => $this->visitorAccount->email]),
            'password' => 'abc123',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'authenticated');

        $this->assertSame($hashBefore, $this->visitorAccount->fresh()->password_hash);
    }
}
