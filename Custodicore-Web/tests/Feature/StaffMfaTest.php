<?php

namespace Tests\Feature;

use App\Mail\StaffMfaCode;
use App\Models\Account;
use App\Models\Role;
use App\Services\Auth\StaffMfaService;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PassesStaffMfa;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Staff two-step verification on the web dashboards (StaffMfaService,
 * MfaController). After the right password the officer is still a guest:
 * only a correct second proof — authenticator code, emailed code or backup
 * code — or a valid "trust this device" cookie creates the web session.
 * Visitors and the mobile API are not involved.
 */
class StaffMfaTest extends TestCase
{
    use PassesStaffMfa;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private Account $warden;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        $this->warden = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'warden',
            'email' => 'warden@bjmp.gov.ph',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
    }

    private function staffLogin(string $login, string $password = 'password')
    {
        return $this->from('/login')->post('/login', ['email' => $login, 'password' => $password]);
    }

    private function signOut(): void
    {
        $this->post('/logout');
        $this->app['auth']->forgetGuards();
    }

    // ---------------------------------------------------------------- the gate

    public function test_the_right_password_alone_never_signs_staff_in(): void
    {
        foreach (['r.salcedo' => $this->recordOfficerAccount, 'n.fernandez' => $this->frontDeskAccount, 'warden' => $this->warden] as $login => $account) {
            $this->staffLogin($login)->assertRedirect(route('mfa.challenge'));
            $this->assertWaitingForMfa($account);

            // A pending sign-in opens no dashboard.
            $this->get('/dashboard')->assertRedirect(route('login'));
            $this->get('/admin')->assertRedirect(route('login'));
            $this->get('/front-desk')->assertRedirect(route('login'));
            $this->assertGuest('web');

            $this->get(route('mfa.cancel'))->assertRedirect(route('login'));
            $this->assertNull(session('mfa_pending'));
        }

        $this->assertNull($this->recordOfficerAccount->fresh()->last_login_at);
    }

    public function test_mfa_pages_need_a_pending_sign_in(): void
    {
        $this->get(route('mfa.challenge'))->assertRedirect(route('login'));
        $this->get(route('mfa.setup'))->assertRedirect(route('login'));
        $this->post(route('mfa.verify'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->post(route('mfa.setup.confirm'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->post(route('mfa.email'))->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_the_session_id_changes_after_the_password_and_again_after_the_code(): void
    {
        $this->enrollStaffMfa($this->recordOfficerAccount);

        $this->get('/login');
        $before = session()->getId();

        $this->staffLogin('r.salcedo');
        $pending = session()->getId();

        $this->passMfaChallenge($this->recordOfficerAccount)->assertRedirect(route('dashboard'));
        $signedIn = session()->getId();

        $this->assertNotSame($before, $pending);
        $this->assertNotSame($pending, $signedIn);
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');
        $this->assertNull(session('mfa_pending'));
    }

    // ---------------------------------------------------------------- first-time setup

    public function test_first_sign_in_sets_up_the_app_and_shows_backup_codes_once(): void
    {
        $this->staffLogin('r.salcedo')->assertRedirect(route('mfa.challenge'));
        $this->get(route('mfa.challenge'))->assertRedirect(route('mfa.setup'));

        $page = $this->get(route('mfa.setup'))->assertOk();
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $secret = session('mfa_setup_secret');
        $page->assertSee(Totp::groupSecret($secret));

        // A wrong code turns nothing on and signs nobody in.
        $this->post(route('mfa.setup.confirm'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        $this->assertGuest('web');
        $this->assertNull($this->recordOfficerAccount->fresh()->mfa_enabled_at);

        $this->post(route('mfa.setup.confirm'), ['code' => Totp::code($secret, Totp::stepAt())])
            ->assertRedirect(route('account.mfa.backup-codes'));
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');

        $account = $this->recordOfficerAccount->fresh();
        $this->assertNotNull($account->mfa_enabled_at);
        $this->assertNotSame($secret, $account->mfa_secret, 'The secret is stored encrypted.');
        $this->assertSame($secret, app(StaffMfaService::class)->secret($account));
        $this->assertNotNull($account->last_login_at);

        $codesPage = $this->get(route('account.mfa.backup-codes'))->assertOk();
        $this->assertStringContainsString('no-store', (string) $codesPage->headers->get('Cache-Control'));
        preg_match_all('/[A-Z2-9]{5}-[A-Z2-9]{5}/', $codesPage->getContent(), $found);
        $codes = array_values(array_unique($found[0]));
        $this->assertCount(8, $codes);
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, (string) $account->mfa_backup_codes, 'Only hashes are stored.');
            $this->assertStringNotContainsString(str_replace('-', '', $code), (string) $account->mfa_backup_codes);
        }

        // Shown once.
        $this->get(route('account.mfa.backup-codes'))->assertRedirect(route('account.profile'));
    }

    // ---------------------------------------------------------------- authenticator code

    // ---------------------------------------------------------------- first-time setup: emailed codes (no app)

    /** Picks "Use email codes" at first sign-in and returns the emailed code. */
    private function chooseEmailCodes(): string
    {
        $this->staffLogin('r.salcedo')->assertRedirect(route('mfa.challenge'));
        $this->get(route('mfa.setup'))->assertOk()->assertSee('Use email codes');

        $this->post(route('mfa.setup.email'))->assertRedirect(route('mfa.setup.email.form'));
        $this->get(route('mfa.setup.email.form'))->assertOk()->assertSee('Check your email');

        return Mail::sent(StaffMfaCode::class)->last()->code;
    }

    public function test_first_sign_in_can_choose_emailed_codes_instead_of_an_app(): void
    {
        Mail::fake();
        $code = $this->chooseEmailCodes();
        Mail::assertSent(StaffMfaCode::class, fn (StaffMfaCode $m) => $m->hasTo('r.salcedo@bjmp.gov.ph'));

        // A wrong code turns nothing on.
        $this->post(route('mfa.setup.email.confirm'), ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
        $this->assertGuest('web');
        $this->assertNull($this->recordOfficerAccount->fresh()->mfa_enabled_at);

        $this->post(route('mfa.setup.email.confirm'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');

        $fresh = $this->recordOfficerAccount->fresh();
        $this->assertSame('email', $fresh->mfa_method);
        $this->assertNull($fresh->mfa_secret);
        $this->assertNotNull($fresh->mfa_enabled_at);
        $this->assertTrue(app(StaffMfaService::class)->isEnabled($fresh));
    }

    public function test_email_code_officers_get_a_code_automatically_at_each_sign_in(): void
    {
        Mail::fake();
        $this->post(route('mfa.setup.email.confirm'), ['code' => $this->chooseEmailCodes()]);
        $this->signOut();

        // Next sign-in: the code page emails a fresh code by itself (a refresh does not resend).
        $this->staffLogin('r.salcedo')->assertRedirect(route('mfa.challenge'));
        $this->get(route('mfa.challenge'))->assertOk()->assertSee('We emailed a 6-digit code')->assertDontSee('authenticator app to finish');
        $this->get(route('mfa.challenge'))->assertOk();
        Mail::assertSent(StaffMfaCode::class, 2);

        $code = Mail::sent(StaffMfaCode::class)->last()->code;
        $this->post(route('mfa.verify'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');

        // The code was single-use.
        $this->signOut();
        $this->staffLogin('r.salcedo');
        $this->post(route('mfa.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_email_code_officers_see_their_method_on_the_profile_and_have_no_backup_codes(): void
    {
        Mail::fake();
        $this->post(route('mfa.setup.email.confirm'), ['code' => $this->chooseEmailCodes()]);

        $this->get(route('account.profile'))->assertOk()
            ->assertSee('On (emailed codes).')
            ->assertDontSee('Make new backup codes');
    }

    public function test_reset_sends_an_email_code_officer_back_to_the_choice(): void
    {
        Mail::fake();
        $this->post(route('mfa.setup.email.confirm'), ['code' => $this->chooseEmailCodes()]);

        app(StaffMfaService::class)->reset($this->recordOfficerAccount->fresh());
        $fresh = $this->recordOfficerAccount->fresh();
        $this->assertNull($fresh->mfa_method);
        $this->assertNull($fresh->mfa_enabled_at);

        $this->signOut();
        $this->staffLogin('r.salcedo');
        $this->get(route('mfa.challenge'))->assertRedirect(route('mfa.setup'));
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $secret = $this->enrollStaffMfa($this->recordOfficerAccount);
        $this->staffLogin('r.salcedo');

        $this->post(route('mfa.verify'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        $this->post(route('mfa.verify'), ['code' => 'not-a-code'])->assertSessionHasErrors('code');

        $this->assertWaitingForMfa($this->recordOfficerAccount);
    }

    public function test_an_authenticator_code_works_only_once(): void
    {
        $this->enrollStaffMfa($this->recordOfficerAccount);
        $code = $this->nextTotpCode($this->recordOfficerAccount);

        $this->staffLogin('r.salcedo');
        $this->post(route('mfa.verify'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');

        $this->signOut();
        $this->staffLogin('r.salcedo');
        $this->post(route('mfa.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_five_wrong_codes_end_the_pending_sign_in(): void
    {
        $secret = $this->enrollStaffMfa($this->recordOfficerAccount);
        $this->staffLogin('r.salcedo');

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('mfa.verify'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        }

        $this->post(route('mfa.verify'), ['code' => $this->nextTotpCode($this->recordOfficerAccount)])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Too many wrong codes. Please wait a few minutes and sign in again.']);
        $this->assertGuest('web');
        $this->assertNull(session('mfa_pending'));

        // Signing in again with the password does not reset the code limit.
        $this->staffLogin('r.salcedo')->assertRedirect(route('mfa.challenge'));
        $this->passMfaChallenge($this->recordOfficerAccount)->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_the_pending_sign_in_expires(): void
    {
        $this->enrollStaffMfa($this->recordOfficerAccount);
        $this->staffLogin('r.salcedo');

        $minutes = (int) config('mfa.pending_minutes');
        $this->withSession(['mfa_pending' => ['id' => $this->recordOfficerAccount->getKey(), 'at' => time() - $minutes * 60 - 1]]);

        $this->passMfaChallenge($this->recordOfficerAccount)
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Your sign-in took too long. Please sign in again.']);
        $this->assertGuest('web');
    }

    public function test_an_account_deactivated_while_pending_cannot_finish_signing_in(): void
    {
        $this->enrollStaffMfa($this->recordOfficerAccount);
        $this->staffLogin('r.salcedo');

        $this->recordOfficerAccount->forceFill(['status' => 'inactive'])->save();

        $this->passMfaChallenge($this->recordOfficerAccount)->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    // ---------------------------------------------------------------- backup + emailed codes

    public function test_a_backup_code_works_once(): void
    {
        $this->enrollStaffMfa($this->recordOfficerAccount);
        $codes = app(StaffMfaService::class)->generateBackupCodes($this->recordOfficerAccount);

        $this->staffLogin('r.salcedo');
        $this->post(route('mfa.verify'), ['code' => strtolower(str_replace('-', '', $codes[0]))])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');
        $this->assertSame(7, app(StaffMfaService::class)->backupCodesLeft($this->recordOfficerAccount->fresh()));

        $this->signOut();
        $this->staffLogin('r.salcedo');
        $this->post(route('mfa.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_an_emailed_code_signs_in_once_and_expires(): void
    {
        Mail::fake();
        $this->enrollStaffMfa($this->recordOfficerAccount);

        $this->staffLogin('r.salcedo');
        $this->post(route('mfa.email'))->assertSessionHasNoErrors();
        $sent = null;
        Mail::assertSent(StaffMfaCode::class, function (StaffMfaCode $mail) use (&$sent) {
            $sent = $mail->code;

            return $mail->hasTo('r.salcedo@bjmp.gov.ph');
        });
        $this->assertStringNotContainsString($sent, json_encode(session('mfa_email')), 'Only a hash is kept in the session.');

        $this->post(route('mfa.verify'), ['code' => $sent])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');

        // A new emailed code that has expired is refused.
        $this->signOut();
        $this->staffLogin('r.salcedo');
        $this->post(route('mfa.email'));
        Mail::assertSent(StaffMfaCode::class, 2);
        $second = Mail::sent(StaffMfaCode::class)->last()->code;
        $this->withSession(['mfa_email' => ['exp' => time() - 1] + session('mfa_email')]);

        $this->post(route('mfa.verify'), ['code' => $second])->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_a_failed_email_does_not_bypass_the_code(): void
    {
        $this->enrollStaffMfa($this->recordOfficerAccount);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $this->staffLogin('r.salcedo');
        $this->post(route('mfa.email'))->assertSessionHasErrors('code');

        $this->assertWaitingForMfa($this->recordOfficerAccount);
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- trusted device + reset

    public function test_a_trusted_device_skips_the_code_until_the_mfa_is_reset(): void
    {
        $this->enrollStaffMfa($this->recordOfficerAccount);

        $this->staffLogin('r.salcedo');
        $cookie = $this->passMfaChallenge($this->recordOfficerAccount, ['trust_device' => '1'])
            ->assertCookie(StaffMfaService::TRUST_COOKIE)
            ->getCookie(StaffMfaService::TRUST_COOKIE);
        $this->assertTrue($cookie->isHttpOnly());

        $this->signOut();
        $this->withCookie(StaffMfaService::TRUST_COOKIE, $this->decryptedCookie($cookie))
            ->staffLogin('r.salcedo')
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');

        // A forged cookie for another officer does not work.
        $this->signOut();
        $this->enrollStaffMfa($this->frontDeskAccount);
        $forged = str_replace((string) $this->recordOfficerAccount->getKey() . '.', $this->frontDeskAccount->getKey() . '.', $this->decryptedCookie($cookie));
        $this->withCookie(StaffMfaService::TRUST_COOKIE, $forged)
            ->staffLogin('n.fernandez')
            ->assertRedirect(route('mfa.challenge'));
        $this->assertGuest('web');
        $this->get(route('mfa.cancel'));

        // Resetting the officer's MFA cancels the trusted device.
        $this->artisan('cc:mfa-reset', ['login' => 'r.salcedo'])->assertSuccessful();
        $this->withCookie(StaffMfaService::TRUST_COOKIE, $this->decryptedCookie($cookie))
            ->staffLogin('r.salcedo')
            ->assertRedirect(route('mfa.challenge'));
        $this->assertGuest('web');
    }

    public function test_the_reset_command_makes_the_officer_set_up_again(): void
    {
        $this->enrollStaffMfa($this->recordOfficerAccount);
        app(StaffMfaService::class)->generateBackupCodes($this->recordOfficerAccount);

        $this->artisan('cc:mfa-reset', ['login' => 'r.salcedo@bjmp.gov.ph'])->assertSuccessful();

        $account = $this->recordOfficerAccount->fresh();
        $this->assertNull($account->mfa_secret);
        $this->assertNull($account->mfa_enabled_at);
        $this->assertNull($account->mfa_backup_codes);

        $this->staffLogin('r.salcedo')->assertRedirect(route('mfa.challenge'));
        $this->get(route('mfa.challenge'))->assertRedirect(route('mfa.setup'));
        $this->assertGuest('web');

        $this->artisan('cc:mfa-reset', ['login' => 'maria.santos@example.com'])->assertFailed();
    }

    // ---------------------------------------------------------------- My Profile

    public function test_new_backup_codes_need_the_password_and_replace_the_old_ones(): void
    {
        $this->enrollStaffMfa($this->recordOfficerAccount);
        $old = app(StaffMfaService::class)->generateBackupCodes($this->recordOfficerAccount);
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->from(route('account.profile'))->post(route('account.mfa.regenerate'))
            ->assertRedirect(route('account.profile'))
            ->assertSessionHasErrors('current_password');
        $this->from(route('account.profile'))->post(route('account.mfa.regenerate'), ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame(8, app(StaffMfaService::class)->backupCodesLeft($this->recordOfficerAccount->fresh()));
        $before = $this->recordOfficerAccount->fresh()->mfa_backup_codes;

        $this->post(route('account.mfa.regenerate'), ['current_password' => 'password'])
            ->assertRedirect(route('account.mfa.backup-codes'));
        $this->assertNotSame($before, $this->recordOfficerAccount->fresh()->mfa_backup_codes);
        $this->get(route('account.mfa.backup-codes'))->assertOk()->assertSee('Your new backup codes');

        // The old codes no longer sign in.
        $this->signOut();
        $this->staffLogin('r.salcedo');
        $this->post(route('mfa.verify'), ['code' => $old[0]])->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_profile_shows_the_status_but_never_the_secret_or_codes(): void
    {
        $secret = $this->enrollStaffMfa($this->recordOfficerAccount);
        $codes = app(StaffMfaService::class)->generateBackupCodes($this->recordOfficerAccount);
        $stored = $this->recordOfficerAccount->fresh();

        $page = $this->actingAs($this->recordOfficerAccount, 'web')->get(route('account.profile'))->assertOk();
        $page->assertSee('Two-step verification')
            ->assertSee('Backup codes left: 8')
            ->assertSee('Make new backup codes')
            ->assertDontSee($secret)
            ->assertDontSee(Totp::groupSecret($secret))
            ->assertDontSee($stored->mfa_secret)
            ->assertDontSee($codes[0]);

        $array = $stored->toArray();
        $this->assertArrayNotHasKey('mfa_secret', $array);
        $this->assertArrayNotHasKey('mfa_backup_codes', $array);
        $this->assertStringNotContainsString($secret, $stored->toJson());
    }

    public function test_profile_says_when_two_step_is_not_set_up(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web')->get(route('account.profile'))
            ->assertOk()
            ->assertSee('Not set up for this account.')
            ->assertDontSee('Make new backup codes');
    }

    // ---------------------------------------------------------------- visitors + config

    public function test_visitors_and_the_mobile_api_are_unaffected(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'maria.santos@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token']);
        $this->assertNull(session('mfa_pending'));

        // Visitors are still refused on the staff login, before any two-step step.
        $this->staffLogin('maria.santos@example.com')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Visitor accounts sign in through the CustodiCore mobile app, not this dashboard.']);
        $this->assertNull(session('mfa_pending'));
        $this->assertGuest('web');
    }

    public function test_when_turned_off_staff_without_setup_sign_in_with_the_password(): void
    {
        config(['mfa.required' => false]);

        $this->staffLogin('r.salcedo')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->recordOfficerAccount, 'web');

        // Someone who already set it up still needs the code.
        $this->signOut();
        $this->enrollStaffMfa($this->frontDeskAccount);
        $this->staffLogin('n.fernandez')->assertRedirect(route('mfa.challenge'));
        $this->assertWaitingForMfa($this->frontDeskAccount);
    }

    // ---------------------------------------------------------------- helpers

    /** A 6-digit code that is not valid for $secret in the current window. */
    private function wrongCode(string $secret): string
    {
        $valid = array_map(fn ($s) => Totp::code($secret, $s), range(Totp::stepAt() - 1, Totp::stepAt() + 1));

        for ($n = 0; ; $n++) {
            $candidate = str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            if (! in_array($candidate, $valid, true)) {
                return $candidate;
            }
        }
    }

    private function decryptedCookie(\Symfony\Component\HttpFoundation\Cookie $cookie): string
    {
        return (string) $cookie->getValue();
    }
}
