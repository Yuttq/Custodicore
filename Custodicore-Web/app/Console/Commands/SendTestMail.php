<?php

namespace App\Console\Commands;

use App\Services\Auth\StaffInvitationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * php artisan cc:mail-test you@example.com
 *
 * Sends one plain test email so the mail settings in .env can be checked
 * without registering an officer. Prints what was used and, on failure,
 * a plain-English reason.
 */
class SendTestMail extends Command
{
    protected $signature = 'cc:mail-test {email : Address to send the test email to}';

    protected $description = 'Send a test email to check the MAIL_ settings in .env';

    public function handle(): int
    {
        $to = trim((string) $this->argument('email'));

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$to}' is not a valid email address.");

            return self::FAILURE;
        }

        $mailer = config('mail.default');
        $this->line("Mailer: {$mailer}");
        if ($mailer === 'smtp') {
            $this->line('Server: ' . config('mail.mailers.smtp.host') . ':' . config('mail.mailers.smtp.port'));
            $this->line('Login:  ' . (config('mail.mailers.smtp.username') ?: '(none)'));
        }
        $this->line('From:   ' . config('mail.from.address'));

        if (StaffInvitationService::mailIsLogOnly()) {
            $this->warn('MAIL_MAILER is "' . $mailer . '": nothing will be delivered. Set MAIL_MAILER=smtp in .env, run `php artisan config:clear`, and try again.');

            return self::FAILURE;
        }

        try {
            Mail::raw(
                "This is a test email from CustodiCore.\n\nIf you can read this, staff setup links can be sent automatically.",
                fn ($message) => $message->to($to)->subject('CustodiCore test email')
            );
        } catch (\Throwable $e) {
            $this->error('Sending failed: ' . $e->getMessage());
            $this->line('Common causes: wrong MAIL_USERNAME / MAIL_PASSWORD (Gmail needs an App Password), or a wrong MAIL_HOST / MAIL_PORT.');

            return self::FAILURE;
        }

        $this->info("Test email sent to {$to}. Check the inbox (and Spam).");

        return self::SUCCESS;
    }
}
