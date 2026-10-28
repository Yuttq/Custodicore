<?php

namespace App\Mail;

use App\Models\Account;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The 6-digit sign-in code an officer asks for when their authenticator app
 * is not at hand (see App\Services\Auth\StaffMfaService::sendEmailCode).
 * Sent synchronously, never queued, so the code is not stored in the jobs table.
 */
class StaffMfaCode extends Mailable
{
    public function __construct(
        public Account $account,
        public string $code,
        public int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your CustodiCore sign-in code');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-mfa-code',
            text: 'emails.staff-mfa-code-text',
            with: [
                'name' => $this->account->displayName(),
                'code' => $this->code,
                'minutes' => $this->minutes,
            ],
        );
    }
}
