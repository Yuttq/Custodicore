<?php

namespace App\Mail;

use App\Models\Account;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The visitor's email verification link (EmailVerificationService::send()).
 * Sent synchronously, not queued: the link must never sit in the jobs table.
 */
class VerifyVisitorEmail extends Mailable
{
    public function __construct(
        public Account $account,
        public string $verificationUrl,
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your CustodiCore email address');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email',
            text: 'emails.verify-email-text',
            with: [
                'name' => $this->account->displayName(),
                'expiresInHours' => max(1, intdiv($this->expiresInMinutes, 60)),
            ],
        );
    }
}
