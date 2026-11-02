<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Services\Auth\StaffInvitationService;
use App\Support\MailDeliveryCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\PassesStaffMfa;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Production email readiness (Azure Communication Services over SMTP):
 * MAIL_REQUIRE_TLS reaches the SMTP transport, failed sends are logged
 * without the raw SMTP error (which can hold the sign-in name, an address,
 * a link or a code), and production with the log/array mailer is flagged.
 * No email is sent and no mail server is contacted.
 */
class MailConfigurationTest extends TestCase
{
    use PassesStaffMfa;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const SMTP_USER = 'custodicore-acs-smtp-user';
    private const SECRET_URL = 'https://custodicore.example/staff/setup/AbCdEf0123456789';
    private const CODE = '481516';

    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        // Collect log lines in memory instead of writing storage/logs.
        config(['logging.default' => 'null']);
        app('log')->forgetChannel();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message];
        });
    }

    /** What an ACS / Symfony SMTP failure can look like: sign-in name, a link, a code. */
    private function smtpFailure(): TransportException
    {
        return new TransportException(
            'Failed to authenticate on SMTP server with username "' . self::SMTP_USER . '" using the following '
            . 'authenticators: "LOGIN", "PLAIN". Authenticator "LOGIN" returned "Expected response code "235" but got '
            . 'code "535", with message "535 5.7.3 Authentication unsuccessful". Message: ' . self::SECRET_URL . ' ' . self::CODE . '".'
        );
    }

    private function assertNothingSensitiveLogged(): void
    {
        $all = implode("\n", array_column($this->logged, 'message'));

        foreach ([self::SMTP_USER, self::SECRET_URL, 'AbCdEf0123456789', self::CODE, '535 5.7.3', 'r.salcedo@bjmp.gov.ph'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    // ---------------------------------------------------------------- MAIL_REQUIRE_TLS

    public function test_require_tls_reaches_the_smtp_transport(): void
    {
        config(['mail.mailers.smtp' => [
            'transport' => 'smtp',
            'host' => 'smtp.azurecomm.net',
            'port' => 587,
            'username' => self::SMTP_USER,
            'password' => 'not-a-real-secret',
            'timeout' => 15,
            'require_tls' => true,
        ]]);
        app('mail.manager')->purge('smtp');

        $transport = Mail::mailer('smtp')->getSymfonyTransport();

        $this->assertTrue($transport->isTlsRequired());
        $this->assertTrue($transport->isAutoTls(), 'STARTTLS on 587');
        $this->assertFalse($transport->getStream()->isTLS(), 'Not implicit TLS (smtps): STARTTLS upgrades the connection.');
        $this->assertSame('smtp.azurecomm.net', $transport->getStream()->getHost());
        $this->assertSame(587, $transport->getStream()->getPort());

        config(['mail.mailers.smtp.require_tls' => false]);
        app('mail.manager')->purge('smtp');
        $this->assertFalse(Mail::mailer('smtp')->getSymfonyTransport()->isTlsRequired());
    }

    public function test_require_tls_is_read_from_the_environment_and_off_by_default(): void
    {
        $original = $_SERVER['MAIL_REQUIRE_TLS'] ?? null;

        try {
            unset($_SERVER['MAIL_REQUIRE_TLS'], $_ENV['MAIL_REQUIRE_TLS']);
            putenv('MAIL_REQUIRE_TLS');
            $this->assertFalse((require config_path('mail.php'))['mailers']['smtp']['require_tls']);

            $_SERVER['MAIL_REQUIRE_TLS'] = 'true';
            $this->assertTrue((require config_path('mail.php'))['mailers']['smtp']['require_tls']);

            $_SERVER['MAIL_REQUIRE_TLS'] = 'false';
            $this->assertFalse((require config_path('mail.php'))['mailers']['smtp']['require_tls']);
        } finally {
            unset($_SERVER['MAIL_REQUIRE_TLS']);
            if ($original !== null) {
                $_SERVER['MAIL_REQUIRE_TLS'] = $original;
            }
        }
    }

    // ---------------------------------------------------------------- sanitized failure logs

    public function test_a_failed_staff_setup_email_is_logged_without_the_raw_smtp_error(): void
    {
        Mail::shouldReceive('to')->andThrow($this->smtpFailure());

        $result = app(StaffInvitationService::class)->send($this->recordOfficerAccount);

        // Behavior unchanged: the send failed, and the Warden still gets the one-time link to hand over.
        $this->assertFalse($result['email']);
        $this->assertFalse($result['email_delivered']);
        $this->assertStringContainsString('rejected the login', $result['email_problem']);
        $this->assertStringContainsString('/staff/setup/', $result['url']);

        $warnings = array_values(array_filter($this->logged, fn ($l) => $l['level'] === 'warning'));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Staff setup email could not be sent for account #' . $this->recordOfficerAccount->getKey(), $warnings[0]['message']);
        $this->assertStringContainsString(TransportException::class, $warnings[0]['message']);
        $this->assertStringContainsString('the mail server rejected the login', $warnings[0]['message']);
        $this->assertStringNotContainsString($result['url'], implode("\n", array_column($this->logged, 'message')));
        $this->assertNothingSensitiveLogged();
    }

    public function test_a_failed_mfa_email_is_logged_without_the_raw_smtp_error(): void
    {
        $this->enrollStaffMfa($this->recordOfficerAccount);
        Mail::shouldReceive('to')->andThrow($this->smtpFailure());

        $this->post('/login', ['email' => 'r.salcedo', 'password' => 'password'])->assertRedirect(route('mfa.challenge'));
        $this->post(route('mfa.email'))->assertSessionHasErrors('code');

        // Behavior unchanged: still waiting for a code, nobody signed in.
        $this->assertWaitingForMfa($this->recordOfficerAccount);

        $warnings = array_values(array_filter($this->logged, fn ($l) => $l['level'] === 'warning'));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Two-step verification email could not be sent for account #' . $this->recordOfficerAccount->getKey(), $warnings[0]['message']);
        $this->assertStringContainsString('the mail server rejected the login', $warnings[0]['message']);
        $this->assertNothingSensitiveLogged();
    }

    // ---------------------------------------------------------------- production warning

    public function test_production_with_a_log_only_mailer_is_flagged_once(): void
    {
        foreach (['log', 'array'] as $mailer) {
            $this->logged = [];
            Cache::forget(MailDeliveryCheck::CACHE_KEY);
            config(['mail.default' => $mailer]);

            MailDeliveryCheck::warnIfNotDelivering(true);
            MailDeliveryCheck::warnIfNotDelivering(true);

            $critical = array_values(array_filter($this->logged, fn ($l) => $l['level'] === 'critical'));
            $this->assertCount(1, $critical, "{$mailer}: logged once, not on every request");
            $this->assertStringContainsString('MAIL_MAILER is "' . $mailer . '" in production', $critical[0]['message']);
        }
    }

    public function test_no_warning_for_smtp_or_outside_production(): void
    {
        config(['mail.default' => 'smtp']);
        MailDeliveryCheck::warnIfNotDelivering(true);

        config(['mail.default' => 'log']);
        MailDeliveryCheck::warnIfNotDelivering(false);

        $this->assertSame([], array_filter($this->logged, fn ($l) => $l['level'] === 'critical'));
    }

    public function test_the_warning_never_blocks_startup(): void
    {
        config(['mail.default' => 'log']);
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache database unavailable'));

        MailDeliveryCheck::warnIfNotDelivering(true);

        $this->assertSame([], array_filter($this->logged, fn ($l) => $l['level'] === 'critical'));
    }

    public function test_the_app_runs_the_check_when_it_boots(): void
    {
        config(['mail.default' => 'log']);
        $this->app['env'] = 'production';

        (new AppServiceProvider($this->app))->boot();

        $this->assertCount(1, array_filter($this->logged, fn ($l) => $l['level'] === 'critical'));
    }
}
