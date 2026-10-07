<?php

namespace Tests\Feature;

use App\Mail\VerifyVisitorEmail;
use App\Mail\VisitorAccountApproved;
use App\Models\Account;
use App\Models\EmailVerificationToken;
use App\Models\Notification;
use App\Models\Role;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GoogleIdTokenFixtures;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 2: registration → email verification → login → restricted review
 * state → staff approval/rejection. Email verification (accounts.
 * email_verified_at) and staff review (visitor_profiles.verification_status)
 * are separate states.
 */
class VisitorEmailVerificationFlowTest extends TestCase
{
    use GoogleIdTokenFixtures;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const EMAIL = 'juan.dela.cruz@example.com';
    private const PASSWORD = 'secret-pass-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        Mail::fake();
    }

    private function registrationForm(array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Juan Miguel',
            'lastName' => 'Dela Cruz',
            'fullName' => 'Juan Miguel Dela Cruz',
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'dateOfBirth' => '1995-06-15',
            'gender' => 'male',
            'address' => 'Quezon City',
            'relationshipHint' => 'Spouse',
            'contactNumber' => '09170001111',
            'acceptedTerms' => true,
            'acceptedPrivacy' => true,
            'consentVersion' => (string) config('legal.version'),
        ], $overrides);
    }

    private function register(array $overrides = [])
    {
        return $this->postJson('/api/auth/register', $this->registrationForm($overrides));
    }

    /** The plain token from the last verification email sent to $email. */
    private function sentToken(string $email = self::EMAIL): string
    {
        $mail = Mail::sent(VerifyVisitorEmail::class, fn ($m) => $m->hasTo($email))->last();
        $this->assertNotNull($mail, "No verification email was sent to {$email}.");

        return basename(parse_url($mail->verificationUrl, PHP_URL_PATH));
    }

    /**
     * The browser flow: open the emailed link exactly as sent, then submit the
     * confirmation page's form with the token it rendered (not the one the
     * test already knows), the way tapping "Verify my email" does.
     */
    private function verifyThroughBrowser(string $email = self::EMAIL)
    {
        $mail = Mail::sent(VerifyVisitorEmail::class, fn ($m) => $m->hasTo($email))->last();

        return $this->verifyLinkThroughBrowser($mail->verificationUrl);
    }

    private function verifyLinkThroughBrowser(string $verificationUrl)
    {
        $page = $this->get(parse_url($verificationUrl, PHP_URL_PATH))
            ->assertOk()
            ->assertSee('Verify my email');

        $this->assertSame(1, preg_match('/name="token" value="([^"]*)"/', $page->getContent(), $m));
        // The form must carry the URL's token unchanged (no truncation/encoding).
        $this->assertSame(basename(parse_url($verificationUrl, PHP_URL_PATH)), $m[1]);

        return $this->post(route('email-verification.verify'), ['token' => html_entity_decode($m[1])]);
    }

    private function login(string $email = self::EMAIL, string $password = self::PASSWORD)
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** A Visitor account with a verified email and the given review status. */
    private function makeVisitor(string $email, string $reviewStatus, array $profile = []): Account
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => strstr($email, '@', true),
            'email' => $email,
            'password_hash' => Hash::make(self::PASSWORD),
            'status' => 'active',
        ]);
        $account->forceFill(['email_verified_at' => now()])->save();

        VisitorProfile::create(array_merge([
            'account_id' => $account->account_id,
            'full_name' => 'Test Visitor',
            'date_of_birth' => '1990-01-01',
            'contact_number' => '09170000009',
            'verification_status' => $reviewStatus,
        ], $profile));

        return $account;
    }

    private function bearer(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    // ---------------------------------------------------------- Registration

    public function test_registration_creates_pending_visitor_without_signing_in(): void
    {
        $response = $this->register();

        $response->assertCreated()
            ->assertJsonPath('status', 'verification_required')
            ->assertJsonPath('email', self::EMAIL)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        $account = Account::where('email', self::EMAIL)->firstOrFail();
        $this->assertTrue($account->isVisitor());
        $this->assertSame('active', $account->status);
        $this->assertNull($account->email_verified_at);

        $profile = $account->visitorProfile;
        $this->assertSame('pending', $profile->verification_status);
        $this->assertNull($profile->verified_by);
        $this->assertSame('Juan Miguel Dela Cruz', $profile->full_name);
        // Phase 1 First/Last Name are kept, not just the combined name.
        $this->assertSame('Juan Miguel', $profile->first_name);
        $this->assertSame('Dela Cruz', $profile->last_name);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_registration_sends_a_verification_email_and_stores_only_a_hash(): void
    {
        $this->register()->assertCreated();

        Mail::assertSent(VerifyVisitorEmail::class, fn ($m) => $m->hasTo(self::EMAIL));
        $token = $this->sentToken();

        $record = EmailVerificationToken::sole();
        $this->assertSame(hash('sha256', $token), $record->token_hash);
        $this->assertNotSame($token, $record->token_hash);
        $this->assertTrue($record->expires_at->isFuture());
    }

    public function test_verification_links_use_app_url_not_the_request_host(): void
    {
        config(['app.url' => 'http://10.0.2.2:8000/']);

        $this->withServerVariables(['HTTP_HOST' => '127.0.0.1:8000'])->register()->assertCreated();
        $this->withServerVariables(['HTTP_HOST' => '127.0.0.1:8000'])
            ->postJson('/api/auth/email/resend', ['email' => self::EMAIL])->assertOk();

        $mails = Mail::sent(VerifyVisitorEmail::class, fn ($m) => $m->hasTo(self::EMAIL));
        $this->assertCount(2, $mails);
        foreach ($mails as $mail) {
            $this->assertMatchesRegularExpression(
                '#^http://10\.0\.2\.2:8000/email/verify/[A-Za-z0-9]{64}$#',
                $mail->verificationUrl,
            );
        }
    }

    public function test_registration_saves_the_government_id_as_pending(): void
    {
        Storage::fake('public');

        $this->post('/api/auth/register', $this->registrationForm([
            'governmentId' => UploadedFile::fake()->create('id.pdf', 120, 'application/pdf'),
            'governmentIdType' => 'National ID', // display label, as the app sends it
        ]), ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('token');

        $document = VisitorId::sole();
        $this->assertSame('national_id', $document->id_type);
        $this->assertSame('pending', $document->verification_status);
        $this->assertSame(Account::where('email', self::EMAIL)->first()->visitorProfile->visitor_id, $document->visitor_id);
        Storage::disk('public')->assertExists($document->file_path);
    }

    public function test_registration_with_a_government_id_but_no_type_creates_nothing(): void
    {
        Storage::fake('public');
        $accounts = Account::count();

        $this->post('/api/auth/register', $this->registrationForm([
            'governmentId' => UploadedFile::fake()->create('id.pdf', 120, 'application/pdf'),
        ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('governmentIdType');

        $this->assertSame($accounts, Account::count());
        Mail::assertNothingSent();
    }

    public function test_phase_1_validation_is_unchanged(): void
    {
        $this->register(['acceptedTerms' => false])->assertStatus(422)->assertJsonValidationErrors('acceptedTerms');
        $this->register(['password_confirmation' => 'different'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->register(['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertNull(Account::where('email', self::EMAIL)->first());
        Mail::assertNothingSent();
    }

    // ---------------------------------------------------- Email verification

    public function test_valid_link_verifies_the_email_without_signing_in_or_approving(): void
    {
        $this->register();
        $token = $this->sentToken();

        $this->postJson('/api/auth/email/verify', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('status', 'verified')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        $account = Account::where('email', self::EMAIL)->first();
        $this->assertNotNull($account->email_verified_at);
        // Email verification is NOT staff approval.
        $this->assertSame('pending', $account->visitorProfile->verification_status);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('email_verification_tokens', 0);
    }

    public function test_web_link_shows_a_confirmation_page_and_only_the_post_consumes_it(): void
    {
        $this->register();
        $token = $this->sentToken();

        // Opening the link (or a mail scanner prefetching it) changes nothing.
        $this->get(route('email-verification.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Verify my email');
        $this->assertNull(Account::where('email', self::EMAIL)->first()->email_verified_at);

        $this->post(route('email-verification.verify'), ['token' => $token])
            ->assertOk()
            ->assertSee('Your email has been verified')
            ->assertSee('You can now log in');

        $this->assertNotNull(Account::where('email', self::EMAIL)->first()->email_verified_at);
        $this->assertGuest();
    }

    // Regression: fresh link → GET confirmation page → POST its form.

    public function test_fresh_link_verifies_through_the_confirmation_page(): void
    {
        $this->register();

        $this->verifyThroughBrowser()
            ->assertOk()
            ->assertSee('Your email has been verified');

        $this->assertNotNull(Account::where('email', self::EMAIL)->first()->email_verified_at);
        $this->assertDatabaseCount('email_verification_tokens', 0);
    }

    public function test_used_link_fails_through_the_confirmation_page(): void
    {
        $this->register();
        $this->verifyThroughBrowser()->assertSee('Your email has been verified');

        $this->verifyThroughBrowser()
            ->assertOk()
            ->assertSee('This link is not valid');
    }

    public function test_expired_link_fails_through_the_confirmation_page(): void
    {
        $this->register();
        $this->travel(config('auth.email_verification.expire') + 1)->minutes();

        $this->verifyThroughBrowser()
            ->assertOk()
            ->assertSee('This link has expired');

        $this->assertNull(Account::where('email', self::EMAIL)->first()->email_verified_at);
    }

    public function test_link_replaced_by_a_resend_fails_and_the_newest_link_works(): void
    {
        $this->register();
        $firstUrl = Mail::sent(VerifyVisitorEmail::class)->last()->verificationUrl;

        $this->postJson('/api/auth/email/resend', ['email' => self::EMAIL])->assertOk();

        // The registration email's link no longer exists after a resend.
        $this->verifyLinkThroughBrowser($firstUrl)
            ->assertOk()
            ->assertSee('This link is not valid')
            ->assertSee('newest');
        $this->assertNull(Account::where('email', self::EMAIL)->first()->email_verified_at);

        $this->verifyThroughBrowser()
            ->assertOk()
            ->assertSee('Your email has been verified');
    }

    public function test_invalid_token_fails(): void
    {
        $this->register();

        $this->postJson('/api/auth/email/verify', ['token' => str_repeat('x', 64)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'verification_link_invalid');

        $this->assertNull(Account::where('email', self::EMAIL)->first()->email_verified_at);
    }

    public function test_expired_token_fails(): void
    {
        $this->register();
        $token = $this->sentToken();

        $this->travel(config('auth.email_verification.expire') + 1)->minutes();

        $this->postJson('/api/auth/email/verify', ['token' => $token])
            ->assertStatus(410)
            ->assertJsonPath('code', 'verification_link_expired');

        $this->assertNull(Account::where('email', self::EMAIL)->first()->email_verified_at);
    }

    public function test_used_token_cannot_be_reused(): void
    {
        $this->register();
        $token = $this->sentToken();

        $this->postJson('/api/auth/email/verify', ['token' => $token])->assertOk();
        $this->postJson('/api/auth/email/verify', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('code', 'verification_link_invalid');

        $this->post(route('email-verification.verify'), ['token' => $token])
            ->assertOk()
            ->assertSee('This link is not valid');
    }

    public function test_link_for_a_since_changed_email_does_not_verify(): void
    {
        $this->register();
        $token = $this->sentToken();

        Account::where('email', self::EMAIL)->update(['email' => 'changed@example.com']);

        $this->postJson('/api/auth/email/verify', ['token' => $token])->assertStatus(422);
        $this->assertNull(Account::where('email', 'changed@example.com')->first()->email_verified_at);
    }

    // ------------------------------------------------------------------ Login

    public function test_unverified_visitor_cannot_log_in(): void
    {
        $this->register();

        $this->login()
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified')
            ->assertJsonPath('message', 'Please verify your email address before logging in.')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unverified_check_only_runs_after_the_password(): void
    {
        $this->register();

        $this->login(self::EMAIL, 'wrong-password')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonMissingPath('code');
    }

    public function test_verified_pending_visitor_logs_in_but_stays_restricted(): void
    {
        $this->register();
        $this->postJson('/api/auth/email/verify', ['token' => $this->sentToken()])->assertOk();

        $login = $this->login()
            ->assertOk()
            ->assertJsonPath('user.emailVerified', true)
            ->assertJsonPath('user.verificationStatus', 'pending')
            ->assertJsonPath('user.rejectionReason', null);
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        // Allowed while under review.
        $this->bearer($token)->getJson('/api/me')->assertOk()->assertJsonPath('verificationStatus', 'pending');
        $this->bearer($token)->getJson('/api/documents')->assertOk();
        $this->bearer($token)->getJson('/api/notifications')->assertOk();

        // Approval-dependent visitor features are refused by the backend.
        foreach (['/api/visits', '/api/visits/upcoming', '/api/visits/history'] as $uri) {
            $this->bearer($token)->getJson($uri)
                ->assertForbidden()
                ->assertJsonPath('code', 'visitor_not_approved')
                ->assertJsonPath('verificationStatus', 'pending');
        }
    }

    public function test_pending_visitor_cannot_use_an_assigned_visit(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);
        $visit = \App\Models\VisitRequest::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
            'status' => 'confirmed',
        ]);
        $token = $this->login('maria.santos@example.com', 'password')->assertOk()->json('token');

        $this->bearer($token)->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertForbidden();
        $this->bearer($token)->getJson("/api/schedules/{$visit->visit_request_id}/timeline")->assertForbidden();
        $this->bearer($token)->postJson("/api/schedules/{$visit->visit_request_id}/confirm")->assertForbidden();
        $this->bearer($token)->postJson("/api/schedules/{$visit->visit_request_id}/decline")->assertForbidden();
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_verified_approved_visitor_has_full_access(): void
    {
        $this->makeVisitor('approved@example.com', 'verified');

        $token = $this->login('approved@example.com')
            ->assertOk()
            ->assertJsonPath('user.verificationStatus', 'verified')
            ->json('token');

        $this->bearer($token)->getJson('/api/visits')->assertOk();
        $this->bearer($token)->getJson('/api/visits/upcoming')->assertOk();
        $this->bearer($token)->getJson('/api/visits/history')->assertOk();
    }

    public function test_verified_rejected_visitor_logs_in_but_stays_restricted(): void
    {
        $this->makeVisitor('rejected@example.com', 'rejected', ['rejection_reason' => 'The ID photo is unreadable.']);

        $token = $this->login('rejected@example.com')
            ->assertOk()
            ->assertJsonPath('user.verificationStatus', 'rejected')
            ->assertJsonPath('user.rejectionReason', 'The ID photo is unreadable.')
            ->json('token');

        $this->bearer($token)->getJson('/api/visits')
            ->assertForbidden()
            ->assertJsonPath('verificationStatus', 'rejected');
    }

    public function test_suspended_account_cannot_log_in(): void
    {
        $account = $this->makeVisitor('suspended@example.com', 'verified');
        $account->update(['status' => 'suspended']);

        $this->login('suspended@example.com')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ----------------------------------------------------------------- Resend

    public function test_resend_sends_a_new_link_and_invalidates_the_old_one(): void
    {
        $this->register();
        $oldToken = $this->sentToken();

        $this->postJson('/api/auth/email/resend', ['email' => self::EMAIL])
            ->assertOk()
            ->assertJsonMissingPath('token');

        Mail::assertSent(VerifyVisitorEmail::class, 2);
        $newToken = $this->sentToken();
        $this->assertNotSame($oldToken, $newToken);
        $this->assertDatabaseCount('email_verification_tokens', 1);

        $this->postJson('/api/auth/email/verify', ['token' => $oldToken])->assertStatus(422);
        $this->postJson('/api/auth/email/verify', ['token' => $newToken])->assertOk();
    }

    public function test_resend_does_not_reveal_whether_an_account_exists(): void
    {
        $this->register();
        $this->makeVisitor('already.verified@example.com', 'pending');

        $unverified = $this->postJson('/api/auth/email/resend', ['email' => self::EMAIL])->assertOk();
        $unknown = $this->postJson('/api/auth/email/resend', ['email' => 'nobody@example.com'])->assertOk();
        $verified = $this->postJson('/api/auth/email/resend', ['email' => 'already.verified@example.com'])->assertOk();
        $staff = $this->postJson('/api/auth/email/resend', ['email' => 'r.salcedo@bjmp.gov.ph'])->assertOk();

        $this->assertSame($unverified->json(), $unknown->json());
        $this->assertSame($unverified->json(), $verified->json());
        $this->assertSame($unverified->json(), $staff->json());

        // Only the unverified visitor got mail (registration + resend).
        Mail::assertSent(VerifyVisitorEmail::class, 2);
        Mail::assertNotSent(VerifyVisitorEmail::class, fn ($m) => ! $m->hasTo(self::EMAIL));
    }

    public function test_resend_is_rate_limited_per_address_for_known_and_unknown_addresses(): void
    {
        $this->register();

        foreach ([self::EMAIL, 'nobody@example.com'] as $email) {
            for ($i = 0; $i < 3; $i++) {
                $this->postJson('/api/auth/email/resend', ['email' => $email])->assertOk();
            }
            $this->postJson('/api/auth/email/resend', ['email' => $email])
                ->assertStatus(429)
                ->assertJsonPath('code', 'too_many_requests');

            // Past the 1-minute per-IP window, still inside the per-address one.
            $this->travel(61)->seconds();
        }

        // 1 at registration + 3 resends; the 4th was refused.
        Mail::assertSent(VerifyVisitorEmail::class, 4);
    }

    public function test_resend_is_rate_limited_per_client(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/email/resend', ['email' => "user{$i}@example.com"])->assertOk();
        }

        $this->postJson('/api/auth/email/resend', ['email' => 'user7@example.com'])->assertStatus(429);
    }

    // --------------------------------------------------------------- Approval

    public function test_staff_can_approve_a_pending_visitor(): void
    {
        $account = $this->makeVisitor('pending@example.com', 'pending');
        $profile = $account->visitorProfile;
        $token = $this->login('pending@example.com')->json('token');
        $this->bearer($token)->getJson('/api/visits')->assertForbidden();

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.approve', $profile->visitor_id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile->refresh();
        $this->assertSame('verified', $profile->verification_status);
        $this->assertSame($this->recordOfficerAccount->staffProfile->staff_id, $profile->verified_by);
        $this->assertNotNull($profile->verified_at);

        $notification = Notification::where('account_id', $account->account_id)->sole();
        $this->assertSame('account_approved', $notification->notification_type);
        $this->assertSame('Account Approved', $notification->title);
        $this->assertStringContainsString('Your account has been approved.', $notification->message);
        $this->assertFalse($notification->is_read);
        Mail::assertSent(VisitorAccountApproved::class, fn ($m) => $m->hasTo('pending@example.com'));

        // The same token now has approved-visitor access.
        $this->app['auth']->forgetGuards();
        $this->bearer($token)->getJson('/api/visits')->assertOk();
        $this->bearer($token)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Account Approved']);
    }

    public function test_staff_can_reject_a_visitor_with_a_reason(): void
    {
        $account = $this->makeVisitor('pending@example.com', 'pending');
        $profile = $account->visitorProfile;

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.reject', $profile->visitor_id), [
                'rejection_reason' => 'The ID photo is unreadable.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile->refresh();
        $this->assertSame('rejected', $profile->verification_status);
        $this->assertSame('The ID photo is unreadable.', $profile->rejection_reason);
        $this->assertSame($this->recordOfficerAccount->staffProfile->staff_id, $profile->verified_by);

        $notification = Notification::where('account_id', $account->account_id)->sole();
        $this->assertSame('account_rejected', $notification->notification_type);
        $this->assertStringContainsString('The ID photo is unreadable.', $notification->message);
        Mail::assertNotSent(VisitorAccountApproved::class);

        $this->app['auth']->forgetGuards();
        $token = $this->login('pending@example.com')
            ->assertOk()
            ->assertJsonPath('user.rejectionReason', 'The ID photo is unreadable.')
            ->json('token');
        $this->bearer($token)->getJson('/api/visits')->assertForbidden();
    }

    public function test_approving_a_rejected_visitor_clears_the_reason(): void
    {
        $account = $this->makeVisitor('rejected@example.com', 'rejected', ['rejection_reason' => 'Unreadable ID.']);

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.approve', $account->visitorProfile->visitor_id))
            ->assertRedirect();

        $profile = $account->visitorProfile->fresh();
        $this->assertSame('verified', $profile->verification_status);
        $this->assertNull($profile->rejection_reason);
    }

    public function test_visitors_cannot_approve_themselves(): void
    {
        $account = $this->makeVisitor('pending@example.com', 'pending');
        $profile = $account->visitorProfile;

        // Web route: staff (Record Officer) only.
        $this->actingAs($account)
            ->post(route('visitor.approve', $profile->visitor_id))
            ->assertForbidden();
        $this->actingAs($this->frontDeskAccount)
            ->post(route('visitor.approve', $profile->visitor_id))
            ->assertForbidden();

        // Mobile API: the review fields cannot be edited.
        $this->app['auth']->forgetGuards();
        $token = $this->login('pending@example.com')->json('token');
        $this->bearer($token)->patchJson('/api/me', ['verificationStatus' => 'verified'])->assertStatus(422);
        $this->bearer($token)->patchJson('/api/me', ['verification_status' => 'verified'])->assertStatus(422);

        $this->assertSame('pending', $profile->fresh()->verification_status);
        $this->assertSame(0, Notification::count());
    }

    // ----------------------------------------------------------------- Google

    public function test_google_registration_is_email_verified_but_still_pending_review(): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());

        $this->postJson('/api/auth/google/register', [
            'idToken' => $this->googleIdToken(),
            'firstName' => 'Juan',
            'lastName' => 'Cruz',
        ] + array_diff_key($this->registrationForm(['fullName' => 'Juan Cruz']), ['email' => true, 'firstName' => true, 'lastName' => true]))
            ->assertCreated()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('user.emailVerified', true)
            ->assertJsonPath('user.verificationStatus', 'pending');

        $account = Account::where('email', 'google.visitor@gmail.com')->sole();
        $this->assertNotNull($account->email_verified_at);
        $this->assertSame('pending', $account->visitorProfile->verification_status);
        $this->assertSame('Juan', $account->visitorProfile->first_name);
        // No second verification email for a Google-verified address.
        Mail::assertNothingSent();
    }

    public function test_linking_google_verifies_an_unverified_email_but_not_the_review(): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());
        $this->register(['email' => 'google.visitor@gmail.com']);

        $this->postJson('/api/auth/google/link', [
            'idToken' => $this->googleIdToken(),
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('user.emailVerified', true);

        $account = Account::where('email', 'google.visitor@gmail.com')->sole();
        $this->assertNotNull($account->email_verified_at);
        $this->assertSame('pending', $account->visitorProfile->verification_status);
    }
}
