<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PasswordResetController;
use App\Mail\ResetAccountPassword;
use App\Models\Account;
use App\Models\AuditLog;
use App\Services\Auth\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Visitor Forgot Password: POST /api/auth/password/forgot → emailed link →
 * GET/POST /password/reset → login with the new password. See
 * PasswordResetService for the eligibility rules and policy decisions.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const OLD_PASSWORD = 'password'; // SeedsVisitAssignmentFixtures
    private const NEW_PASSWORD = 'NewSecret123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        Mail::fake();
    }

    private function requestLink(string $email)
    {
        return $this->postJson('/api/auth/password/forgot', ['email' => $email]);
    }

    /** Requests a link for the visitor and returns [token, resetUrl] from the sent email. */
    private function linkFor(Account $account): array
    {
        $this->requestLink($account->email)->assertOk();

        $mail = null;
        Mail::assertSent(ResetAccountPassword::class, function (ResetAccountPassword $m) use ($account, &$mail) {
            $mail = $m;

            return $m->hasTo($account->email);
        });

        $path = parse_url($mail->resetUrl, PHP_URL_PATH);
        $token = rawurldecode(substr($path, strlen('/password/reset/')));

        return [$token, $mail->resetUrl];
    }

    private function submitReset(string $token, array $overrides = [])
    {
        return $this->post('/password/reset', array_merge([
            'token' => $token,
            'email' => $this->visitorAccount->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides));
    }

    private function assertGenericResponse($response): void
    {
        $response->assertOk()->assertExactJson([
            'status' => 'password_reset_requested',
            'message' => PasswordResetController::GENERIC_MESSAGE,
        ]);
    }

    private function makeVisitor(string $email, array $attributes = []): Account
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => strstr($email, '@', true),
            'email' => $email,
            'password_hash' => Hash::make(self::OLD_PASSWORD),
            'status' => 'active',
        ]);
        $account->forceFill($attributes)->save();

        return $account;
    }

    // ---------------------------------------------------------------
    // Requesting a link
    // ---------------------------------------------------------------

    public function test_eligible_visitor_receives_reset_email(): void
    {
        $this->assertGenericResponse($this->requestLink($this->visitorAccount->email));

        Mail::assertSent(ResetAccountPassword::class, 1);
        Mail::assertSent(ResetAccountPassword::class, fn ($m) => $m->hasTo('maria.santos@example.com')
            && $m->expiresInMinutes === 60);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $this->visitorAccount->email]);
    }

    public function test_email_lookup_ignores_case_and_surrounding_spaces(): void
    {
        $this->assertGenericResponse($this->requestLink('  Maria.Santos@Example.COM '));

        Mail::assertSent(ResetAccountPassword::class, fn ($m) => $m->hasTo('maria.santos@example.com'));
    }

    public function test_unknown_email_gets_the_same_response_and_no_email(): void
    {
        $this->assertGenericResponse($this->requestLink('nobody@example.com'));

        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_staff_inactive_and_invitation_pending_accounts_get_no_email(): void
    {
        $inactive = $this->makeVisitor('inactive.visitor@example.com', ['status' => 'inactive']);
        $suspended = $this->makeVisitor('suspended.visitor@example.com', ['status' => 'suspended']);

        $invited = Account::create([
            'role_id' => $this->frontDeskRole->role_id,
            'username' => 'new.officer',
            'email' => 'new.officer@bjmp.gov.ph',
            'password_hash' => Hash::make(\Illuminate\Support\Str::random(40)),
            'status' => 'inactive',
        ]);
        $invited->forceFill(['invitation_token_hash' => hash('sha256', 'x'), 'invitation_expires_at' => now()->addDay()])->save();

        foreach ([$this->recordOfficerAccount, $this->frontDeskAccount, $inactive, $suspended, $invited] as $account) {
            $this->assertGenericResponse($this->requestLink($account->email));
        }

        Mail::assertNothingSent();
        // The broker's token for an ineligible account is discarded at once.
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_visitor_with_pending_invitation_hash_is_not_eligible(): void
    {
        $this->visitorAccount->forceFill(['invitation_token_hash' => hash('sha256', 'y')])->save();

        $this->assertGenericResponse($this->requestLink($this->visitorAccount->email));

        Mail::assertNothingSent();
    }

    public function test_validation_errors_for_missing_or_malformed_email(): void
    {
        $this->postJson('/api/auth/password/forgot', [])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->requestLink('not-an-email')->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_requests_are_throttled_per_email(): void
    {
        $email = 'nobody@example.com'; // the per-address limit applies to every address alike

        for ($i = 0; $i < 3; $i++) {
            $this->assertGenericResponse($this->requestLink($email));
        }

        $this->requestLink($email)
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertJsonStructure(['message', 'retryAfter']);
    }

    public function test_a_second_request_within_a_minute_sends_no_second_email(): void
    {
        $this->assertGenericResponse($this->requestLink($this->visitorAccount->email));
        $this->assertGenericResponse($this->requestLink($this->visitorAccount->email));

        Mail::assertSent(ResetAccountPassword::class, 1);
    }

    public function test_response_and_email_never_leak_the_token_in_json(): void
    {
        $response = $this->requestLink($this->visitorAccount->email);
        [$token] = $this->linkFor($this->visitorAccount);

        $this->assertStringNotContainsString($token, $response->getContent());
    }

    // ---------------------------------------------------------------
    // The emailed link
    // ---------------------------------------------------------------

    public function test_reset_email_links_to_the_named_reset_page(): void
    {
        [$token, $url] = $this->linkFor($this->visitorAccount);

        $expected = rtrim(config('app.url'), '/')
            .route('password.reset', ['token' => $token, 'email' => $this->visitorAccount->email], false);
        $this->assertSame($expected, $url);
        $this->assertTrue(Route::has('password.reset'));

        $relative = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $this->get($relative)
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('name="token"', false)
            ->assertSee($this->visitorAccount->email);
    }

    public function test_reset_page_shows_an_error_for_a_bad_token(): void
    {
        $this->get('/password/reset/not-a-real-token?email='.urlencode($this->visitorAccount->email))
            ->assertOk()
            ->assertSee('This link is not valid')
            ->assertDontSee('name="password"', false);
    }

    // ---------------------------------------------------------------
    // Submitting a new password
    // ---------------------------------------------------------------

    public function test_successful_reset_changes_password_and_login_uses_the_new_one(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);
        $oldHash = $this->visitorAccount->fresh()->password_hash;

        $this->submitReset($token)->assertOk()->assertSee('Your password has been reset');

        $fresh = $this->visitorAccount->fresh();
        $this->assertNotSame($oldHash, $fresh->password_hash);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password_hash));
        $this->assertDatabaseCount('password_reset_tokens', 0);

        $this->postJson('/api/auth/login', ['email' => $fresh->email, 'password' => self::OLD_PASSWORD])
            ->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => $fresh->email, 'password' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_successful_reset_revokes_existing_sanctum_tokens(): void
    {
        $this->visitorAccount->createToken('mobile');
        $this->visitorAccount->createToken('mobile');
        $this->assertSame(2, $this->visitorAccount->tokens()->count());

        [$token] = $this->linkFor($this->visitorAccount);
        $this->submitReset($token)->assertOk();

        $this->assertSame(0, $this->visitorAccount->tokens()->count());
    }

    public function test_successful_reset_is_written_to_the_audit_log(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);
        $this->submitReset($token)->assertOk();

        $log = AuditLog::where('record_type', 'accounts')
            ->where('record_id', $this->visitorAccount->account_id)
            ->latest('audit_log_id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->visitorAccount->account_id, $log->account_id);
        $this->assertStringContainsString('reset their password', $log->description);
        $this->assertStringNotContainsString($token, $log->description);
    }

    public function test_used_token_cannot_be_reused(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);
        $this->submitReset($token)->assertOk()->assertSee('Your password has been reset');

        $this->submitReset($token, ['password' => 'Another123', 'password_confirmation' => 'Another123'])
            ->assertOk()
            ->assertSee('This link is not valid');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->visitorAccount->fresh()->password_hash));
    }

    public function test_expired_token_is_rejected(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);

        $this->travel(61)->minutes();

        $this->submitReset($token)->assertOk()->assertSee('This link is not valid');
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->visitorAccount->fresh()->password_hash));
    }

    public function test_wrong_token_or_wrong_email_is_rejected(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);
        $other = $this->makeVisitor('other.visitor@example.com', ['email_verified_at' => now()]);

        $this->submitReset('wrong-token')->assertSee('This link is not valid');
        $this->submitReset($token, ['email' => $other->email])->assertSee('This link is not valid');
        $this->submitReset($token, ['email' => 'nobody@example.com'])->assertSee('This link is not valid');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->visitorAccount->fresh()->password_hash));
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $other->fresh()->password_hash));
    }

    public function test_a_newer_link_replaces_the_older_one(): void
    {
        [$first] = $this->linkFor($this->visitorAccount);
        $this->travel(2)->minutes(); // past the broker's 60 s per-address throttle
        Mail::fake();
        [$second] = $this->linkFor($this->visitorAccount);

        $this->submitReset($first)->assertSee('This link is not valid');
        $this->submitReset($second)->assertSee('Your password has been reset');
    }

    public function test_staff_cannot_reset_even_with_a_broker_token(): void
    {
        // A token created directly (bypassing the request endpoint) must still not work.
        $token = Password::broker('accounts')->createToken($this->recordOfficerAccount);

        $this->submitReset($token, ['email' => $this->recordOfficerAccount->email])
            ->assertSee('This link is not valid');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->recordOfficerAccount->fresh()->password_hash));
    }

    public function test_account_deactivated_after_the_link_was_sent_cannot_reset(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);
        $this->visitorAccount->forceFill(['status' => 'suspended'])->save();

        $this->submitReset($token)->assertSee('This link is not valid');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->visitorAccount->fresh()->password_hash));
    }

    public function test_password_confirmation_must_match(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);

        $this->submitReset($token, ['password_confirmation' => 'Different123'])
            ->assertRedirect()
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->visitorAccount->fresh()->password_hash));
        // The link is still usable after a validation error.
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    #[DataProvider('weakPasswords')]
    public function test_password_strength_rules_are_enforced(string $password): void
    {
        [$token] = $this->linkFor($this->visitorAccount);

        $this->submitReset($token, ['password' => $password, 'password_confirmation' => $password])
            ->assertRedirect()
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->visitorAccount->fresh()->password_hash));
    }

    public static function weakPasswords(): array
    {
        return [
            'too short' => ['abc12'],
            'letters only' => ['abcdefghij'],
            'numbers only' => ['1234567890'],
            'too long' => [str_repeat('a1', 37)],
            'leading space' => [' abcdefg12'],
        ];
    }

    public function test_password_cannot_be_the_email_address(): void
    {
        // Has a letter and a number, so only the "not your email" rule can reject it.
        $visitor = $this->makeVisitor('juan2024@example.com', ['email_verified_at' => now()]);
        [$token] = $this->linkFor($visitor);

        $this->submitReset($token, [
            'email' => $visitor->email,
            'password' => 'Juan2024@Example.com',
            'password_confirmation' => 'Juan2024@Example.com',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $visitor->fresh()->password_hash));
    }

    public function test_validation_error_does_not_flash_token_or_password_to_the_session(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);

        $this->submitReset($token, ['password_confirmation' => 'Different123'])
            ->assertSessionHasInput('email')
            ->assertSessionMissing('_old_input.token')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_missing_token_shows_the_invalid_link_page(): void
    {
        // Without a token there is no reset page to send the visitor back to.
        $this->post('/password/reset', [
            'email' => $this->visitorAccount->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertSee('This link is not valid');
    }

    public function test_missing_email_is_a_validation_error(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);

        $this->submitReset($token, ['email' => ''])->assertSessionHasErrors('email');
    }

    // ---------------------------------------------------------------
    // The reset token never lands in the session
    // ---------------------------------------------------------------

    public function test_opening_the_reset_page_does_not_store_its_url_as_previous_url(): void
    {
        $this->get('/login'); // an ordinary page, which IS stored
        $before = session()->previousUrl();
        $this->assertStringEndsWith('/login', (string) $before);

        [$token, $url] = $this->linkFor($this->visitorAccount);
        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))
            ->assertOk()
            ->assertSee('Choose a new password');

        $this->assertSame($before, session()->previousUrl());
        $this->assertStringNotContainsString($token, json_encode(session()->all()));
    }

    public function test_validation_error_returns_to_the_reset_page_with_errors_and_email(): void
    {
        [$token, $url] = $this->linkFor($this->visitorAccount);
        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY));

        $response = $this->submitReset($token, ['password_confirmation' => 'Different123']);
        $response->assertRedirect(route('password.reset', ['token' => $token]))
            ->assertSessionHasErrors('password');
        $this->assertStringNotContainsString($token, json_encode(session()->all()));

        // Following the redirect shows the form again, email refilled, error shown.
        $this->get(parse_url($response->headers->get('Location'), PHP_URL_PATH))
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('value="'.$this->visitorAccount->email.'"', false)
            ->assertSee('The two passwords do not match.');

        // The link still works afterwards.
        $this->submitReset($token)->assertSee('Your password has been reset');
    }

    public function test_mistyped_email_with_a_validation_error_still_shows_the_form(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);

        $response = $this->submitReset($token, ['email' => 'not-an-email']);
        $response->assertSessionHasErrors('email');

        $this->get(parse_url($response->headers->get('Location'), PHP_URL_PATH))
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertDontSee('This link is not valid');
    }

    public function test_array_valued_email_is_a_validation_error_not_a_server_error(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);

        $response = $this->submitReset($token, ['email' => ['x']]);
        $response->assertRedirect(route('password.reset', ['token' => $token]))
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('_old_input.email');

        // The form renders again (no htmlspecialchars() TypeError) and the link still works.
        $this->get(parse_url($response->headers->get('Location'), PHP_URL_PATH))
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('id="email" type="email" name="email" value=""', false);

        $this->submitReset($token)->assertSee('Your password has been reset');
    }

    public function test_array_valued_email_in_the_link_query_is_ignored(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);

        $this->get('/password/reset/'.$token.'?email[]=x')
            ->assertOk()
            ->assertSee('Choose a new password');
    }

    // ---------------------------------------------------------------
    // Email delivery happens after the response
    // ---------------------------------------------------------------

    public function test_reset_email_is_sent_only_after_the_response(): void
    {
        app(PasswordResetService::class)->request($this->visitorAccount->email);

        // The token exists, but nothing has been mailed yet: the send is deferred.
        $this->assertDatabaseCount('password_reset_tokens', 1);
        Mail::assertNothingSent();

        app(DeferredCallbackCollection::class)->invoke();

        Mail::assertSent(ResetAccountPassword::class, fn ($m) => $m->hasTo($this->visitorAccount->email));
    }

    public function test_failed_delivery_is_logged_without_the_link_and_answer_stays_generic(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = [$e->level, $e->message.' '.json_encode($e->context)];
        });
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down: http://x/password/reset/SECRET-LINK'));

        $this->assertGenericResponse($this->requestLink($this->visitorAccount->email));

        $this->assertCount(1, $logged);
        $this->assertSame('warning', $logged[0][0]);
        $this->assertStringContainsString('Password reset email could not be sent for account #'.$this->visitorAccount->account_id, $logged[0][1]);
        $this->assertStringNotContainsString('SECRET-LINK', $logged[0][1]);
    }

    // ---------------------------------------------------------------
    // Google-linked accounts and email verification
    // ---------------------------------------------------------------

    public function test_google_linked_visitor_can_reset_and_keeps_the_google_link(): void
    {
        $this->visitorAccount->forceFill(['google_id' => 'google-sub-123'])->save();

        [$token] = $this->linkFor($this->visitorAccount);
        $this->submitReset($token)->assertSee('Your password has been reset');

        $fresh = $this->visitorAccount->fresh();
        $this->assertSame('google-sub-123', $fresh->google_id);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password_hash));
    }

    public function test_reset_does_not_change_email_verification_state(): void
    {
        // Decision: a reset never sets email_verified_at (see PasswordResetService).
        $unverified = $this->makeVisitor('unverified.visitor@example.com');
        $this->assertNull($unverified->email_verified_at);

        [$token] = $this->linkFor($unverified);
        $this->submitReset($token, ['email' => $unverified->email])->assertSee('Your password has been reset');

        $fresh = $unverified->fresh();
        $this->assertNull($fresh->email_verified_at);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password_hash));

        $this->postJson('/api/auth/login', ['email' => $fresh->email, 'password' => self::NEW_PASSWORD])
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified');

        // A verified visitor stays verified.
        $verifiedAt = $this->visitorAccount->fresh()->email_verified_at;
        Mail::fake();
        [$token2] = $this->linkFor($this->visitorAccount);
        $this->submitReset($token2)->assertSee('Your password has been reset');
        $this->assertEquals($verifiedAt, $this->visitorAccount->fresh()->email_verified_at);
    }

    public function test_reset_tokens_are_stored_hashed(): void
    {
        [$token] = $this->linkFor($this->visitorAccount);

        $stored = DB::table('password_reset_tokens')->where('email', $this->visitorAccount->email)->value('token');
        $this->assertNotSame($token, $stored);
        $this->assertTrue(Hash::check($token, $stored));
    }
}
