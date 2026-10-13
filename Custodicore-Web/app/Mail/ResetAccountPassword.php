<?php

namespace App\Mail;

use App\Models\Account;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The visitor's password reset link (PasswordResetService::sendLink()).
 * Sent synchronously, not queued: the link must never sit in the jobs table.
 */
class ResetAccountPassword extends Mailable
{
    public function __construct(
        public Account $account,
        public string $resetUrl,
        public int $expiresInMinutes,
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
            ],
        );
    }
}
