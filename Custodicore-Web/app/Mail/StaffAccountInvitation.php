<?php

namespace App\Mail;

use App\Models\Account;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Set up your CustodiCore account" — sent to a new officer by
 * StaffInvitationService. Contains NO password: the officer chooses their
 * own through the link. Sent synchronously (never queued) so the link does
 * not sit in the jobs table.
 */
class StaffAccountInvitation extends Mailable
{
    public function __construct(
        public Account $account,
        public string $setupUrl,
        public int $expiresInHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your CustodiCore staff account');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-invitation',
            text: 'emails.staff-invitation-text',
            with: [
                'name' => $this->account->displayName(),
                'roleName' => $this->account->role?->role_name,
                'loginEmail' => $this->account->email,
            ],
        );
    }
}
