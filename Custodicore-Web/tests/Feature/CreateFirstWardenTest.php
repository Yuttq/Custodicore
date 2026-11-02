<?php

namespace Tests\Feature;

use App\Mail\StaffAccountInvitation;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\StaffProfile;
use App\Services\Auth\StaffInvitationService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\PassesStaffMfa;
use Tests\TestCase;

/**
 * php artisan cc:create-first-warden: the first Warden of a fresh
 * installation gets the same treatment as staff added from Admin → Users —
 * inactive, a password nobody knows, a one-time setup link stored only as a
 * hash and emailed — and nothing secret is printed or logged.
 */
class CreateFirstWardenTest extends TestCase
{
    use PassesStaffMfa;
    use RefreshDatabase;

    private const WARDEN = 'System Administrator/Warden';
    private const EMAIL = 'juan.delacruz@example.com';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(ReferenceDataSeeder::class)->setContainer($this->app)->__invoke();
        $this->withoutVite();

        Mail::fake();
        // A connected mailer (faked above, so nothing is sent) and production-like debug off.
        config(['mail.default' => 'smtp', 'app.debug' => false]);

        config(['logging.default' => 'null']);
        app('log')->forgetChannel();
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e->message);
    }

    /** @return array{0: int, 1: string} exit code and everything the command printed */
    private function createWarden(array $options = []): array
    {
        $code = Artisan::call('cc:create-first-warden', $options + [
            '--email' => self::EMAIL,
            '--first-name' => 'Juan',
            '--last-name' => 'Dela Cruz',
            '--contact' => '09171234567',
        ]);

        return [$code, Artisan::output()];
    }

    private function wardenRoleId(): int
    {
        return Role::where('role_name', self::WARDEN)->value('role_id');
    }

    private function sentSetupUrl(): string
    {
        $url = null;
        Mail::assertSent(StaffAccountInvitation::class, function (StaffAccountInvitation $mail) use (&$url) {
            $url = $mail->setupUrl;

            return $mail->hasTo(self::EMAIL);
        });

        return $url;
    }

    private function assertNotExposed(string $output, string $url): void
    {
        $token = Str::afterLast($url, '/');
        foreach ([$url, $token] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
            $this->assertStringNotContainsString($secret, implode("\n", $this->logged));
        }
    }

    // ---------------------------------------------------------------- creating the Warden

    public function test_creates_an_inactive_warden_and_emails_a_one_time_setup_link(): void
    {
        [$code, $output] = $this->createWarden();

        $this->assertSame(0, $code, $output);
        $account = Account::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame(self::WARDEN, $account->role->role_name);
        $this->assertSame('inactive', $account->status);
        $this->assertNull($account->password_set_at);
        $this->assertSame('pending', $account->invitationState());
        $this->assertNull($account->mfa_enabled_at);

        $profile = $account->staffProfile;
        // The mobile number is normalized the same way the Admin → Users form does it.
        $this->assertSame(['Juan', 'Dela Cruz', '+639171234567', self::EMAIL, self::WARDEN],
            [$profile->first_name, $profile->last_name, $profile->contact_number, $profile->personal_email, $profile->position]);

        // The link is emailed; the database keeps only its hash.
        $url = $this->sentSetupUrl();
        $token = Str::afterLast($url, '/');
        $this->assertSame(hash('sha256', $token), $account->invitation_token_hash);
        $this->assertFalse(Hash::check($token, $account->password_hash));

        $this->assertTrue(AuditLog::where(['record_type' => 'accounts', 'record_id' => $account->account_id])
            ->where('description', 'like', '%first Warden account%from the server console%')->exists());

        // Output: id, masked email, delivery status — never the link or token.
        $this->assertStringContainsString("Warden account #{$account->account_id} created", $output);
        $this->assertStringContainsString('j***@example.com', $output);
        $this->assertStringContainsString('Setup link emailed', $output);
        $this->assertStringNotContainsString(self::EMAIL, $output);
        $this->assertNotExposed($output, $url);
    }

    public function test_the_warden_sets_their_own_password_and_then_needs_two_step_verification(): void
    {
        $this->createWarden();
        $url = $this->sentSetupUrl();
        $account = Account::where('email', self::EMAIL)->firstOrFail();

        $this->get($url)->assertOk();
        $this->post(route('staff-setup.submit'), [
            'token' => Str::afterLast($url, '/'),
            'username' => 'juan.warden',
            'contact_number' => '09171234567',
            'password' => 'Str0ng!Warden-pass',
            'password_confirmation' => 'Str0ng!Warden-pass',
        ])->assertRedirect(route('login'));

        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertNull($account->invitation_token_hash);

        // The setup link works once.
        $this->get($url)->assertOk()->assertDontSee('juan.warden');
        $this->assertNull(Account::where('invitation_token_hash', hash('sha256', Str::afterLast($url, '/')))->first());

        // First sign-in: password step, then two-step setup — no session before it.
        $this->post('/login', ['email' => 'juan.warden', 'password' => 'Str0ng!Warden-pass'])->assertRedirect(route('mfa.challenge'));
        $this->assertWaitingForMfa($account);
        $this->get(route('mfa.challenge'))->assertRedirect(route('mfa.setup'));
        $this->get('/admin')->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- refusals

    public function test_refuses_when_any_warden_account_exists(): void
    {
        $states = [
            'active' => ['status' => 'active'],
            'inactive' => ['status' => 'inactive'],
            'awaiting setup' => ['status' => 'inactive', 'invitation_token_hash' => hash('sha256', 'x'), 'invitation_expires_at' => now()->addDay()],
        ];

        foreach ($states as $label => $state) {
            $existing = Account::create([
                'role_id' => $this->wardenRoleId(),
                'username' => 'existing.warden',
                'email' => 'existing.warden@example.com',
                'password_hash' => Hash::make(Str::random(40)),
                'status' => $state['status'],
            ]);
            $existing->forceFill($state)->save();

            [$code, $output] = $this->createWarden();

            $this->assertSame(1, $code, $label);
            $this->assertStringContainsString('A Warden account already exists', $output, $label);
            $this->assertSame(1, Account::count(), $label);
            $existing->delete();
        }

        Mail::assertNothingSent();
    }

    public function test_refuses_when_the_reference_data_is_missing(): void
    {
        RolePermission::where('role_id', $this->wardenRoleId())->delete();
        Role::where('role_name', self::WARDEN)->delete();

        [$code, $output] = $this->createWarden();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('ReferenceDataSeeder', $output);
        $this->assertSame(0, Account::count());
        Mail::assertNothingSent();
    }

    public function test_rejects_an_invalid_or_missing_email(): void
    {
        foreach (['not-an-email', null] as $email) {
            [$code, $output] = $this->createWarden(['--email' => $email]);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('Nothing was created', $output);
        }

        $this->assertSame(0, Account::count());
        $this->assertSame(0, StaffProfile::count());
        Mail::assertNothingSent();
    }

    public function test_rejects_an_email_already_used_by_another_account(): void
    {
        Account::create([
            'role_id' => Role::where('role_name', 'Visitor')->value('role_id'),
            'username' => 'juan.visitor',
            'email' => self::EMAIL,
            'password_hash' => Hash::make(Str::random(40)),
            'status' => 'active',
        ]);

        [$code, $output] = $this->createWarden(['--email' => strtoupper(self::EMAIL)]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('already used by another account', $output);
        $this->assertSame(0, Account::where('role_id', $this->wardenRoleId())->count());
        Mail::assertNothingSent();
    }

    public function test_applies_the_admin_form_checks_to_names_and_mobile_number(): void
    {
        [$code, $output] = $this->createWarden(['--contact' => '12345', '--first-name' => 'J4n']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('valid Philippine mobile number', $output);
        $this->assertStringContainsString('first name may only contain letters', $output);
        $this->assertSame(0, Account::count());
    }

    // ---------------------------------------------------------------- when email does not go out

    public function test_mail_in_log_mode_is_refused_before_anything_is_created(): void
    {
        config(['mail.default' => 'log']);

        [$code, $output] = $this->createWarden();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--show-link', $output);
        $this->assertSame(0, Account::count());
    }

    public function test_show_link_prints_the_link_only_when_it_was_not_emailed(): void
    {
        config(['mail.default' => 'log']);

        [$code, $output] = $this->createWarden(['--show-link' => true]);

        $this->assertSame(0, $code, $output);
        $account = Account::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame('inactive', $account->status);
        $this->assertMatchesRegularExpression('#/staff/setup/([A-Za-z0-9]{64})#', $output);
        preg_match('#(\S+/staff/setup/([A-Za-z0-9]{64}))#', $output, $found);
        $this->assertSame(hash('sha256', $found[2]), $account->invitation_token_hash);
        $this->assertStringContainsString('NOT emailed', $output);

        // Shown on screen only — never logged.
        $this->assertStringNotContainsString($found[2], implode("\n", $this->logged));
    }

    public function test_a_failed_email_keeps_the_account_and_reports_it_without_the_link(): void
    {
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection could not be established with host "smtp.azurecomm.net:587"'));

        [$code, $output] = $this->createWarden();

        $this->assertSame(1, $code);
        $account = Account::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame('pending', $account->invitationState());
        $this->assertStringContainsString('NOT emailed', $output);
        $this->assertStringContainsString('could not be reached', $output);
        $this->assertStringContainsString('The account was kept', $output);
        $this->assertStringNotContainsString('/staff/setup/', $output);
        $this->assertStringNotContainsString('/staff/setup/', implode("\n", $this->logged));
    }

    public function test_a_failed_email_with_show_link_prints_the_link_once(): void
    {
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection could not be established with host "smtp.azurecomm.net:587"'));

        [$code, $output] = $this->createWarden(['--show-link' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(1, substr_count($output, '/staff/setup/'));
        $this->assertStringNotContainsString('/staff/setup/', implode("\n", $this->logged));
    }

    public function test_show_link_does_not_print_a_link_that_was_emailed(): void
    {
        [$code, $output] = $this->createWarden(['--show-link' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('not shown here', $output);
        $this->assertNotExposed($output, $this->sentSetupUrl());
    }

    // ---------------------------------------------------------------- Admin → Users still the same

    public function test_admin_users_still_creates_staff_the_same_way(): void
    {
        $this->createWarden();
        $warden = Account::where('email', self::EMAIL)->firstOrFail();
        $warden->forceFill(['status' => 'active', 'invitation_token_hash' => null, 'invitation_expires_at' => null])->save();
        Mail::fake();

        $this->actingAs($warden, 'web')
            ->post(route('admin.users.store'), [
                'first_name' => 'Maria',
                'last_name' => 'Reyes',
                'role_name' => 'Record Officer',
                'contact_number' => '09181234567',
                'personal_email' => 'maria.reyes@example.com',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $officer = Account::where('email', 'maria.reyes@example.com')->firstOrFail();
        $this->assertSame('Record Officer', $officer->role->role_name);
        $this->assertSame('inactive', $officer->status);
        $this->assertSame('maria.reyes', $officer->username);
        $this->assertSame('pending', $officer->invitationState());
        $this->assertSame('EMP-' . str_pad((string) $officer->staffProfile->staff_id, 3, '0', STR_PAD_LEFT), $officer->staffProfile->employee_number);
        $this->assertSame('Record Officer', $officer->staffProfile->position);
        Mail::assertSent(StaffAccountInvitation::class, fn ($mail) => $mail->hasTo('maria.reyes@example.com'));
        $this->assertTrue(AuditLog::where(['record_type' => 'accounts', 'record_id' => $officer->account_id])
            ->where('description', 'like', 'Registered BJMP officer account for Maria Reyes (Record Officer)%')->exists());
        $this->assertNull(session('setup_link'), 'Emailed links are not shown to the Warden.');

        // Only a Warden can do this.
        $this->app['auth']->forgetGuards();
        $officer->forceFill(['status' => 'active', 'invitation_token_hash' => null, 'invitation_expires_at' => null])->save();
        $this->actingAs($officer, 'web')
            ->post(route('admin.users.store'), ['first_name' => 'X'])
            ->assertForbidden();
    }
}
