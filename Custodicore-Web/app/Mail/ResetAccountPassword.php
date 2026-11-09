<?php

namespace App\Mail;

use App\Models\Account;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A password reset link (PasswordResetService::sendLink()) — for a visitor
 * (requested from the mobile app) or a staff officer (web login page).
 * Sent synchronously, not queued: the link must never sit in the jobs table.
 */
class ResetAccountPassword extends Mailable
{
    public function __construct(
        public Account $account,
        public string $resetUrl,
        public int $expiresInMinutes,
        public bool $isStaff = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your CustodiCore password');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset-password',
            text: 'emails.reset-password-text',
            with: [
                'name' => $this->account->displayName(),
                'accountKind' => $this->isStaff ? 'staff' : 'visitor',
                'requestFrom' => $this->isStaff ? 'the CustodiCore sign-in page ("Forgot password?")' : 'the CustodiCore app',
            ],
        );
    }
}
