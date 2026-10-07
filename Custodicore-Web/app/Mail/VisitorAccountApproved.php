<?php

namespace App\Mail;

use App\Models\Account;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent when staff approve a visitor's information and documents
 * (VisitorController::approve()). The in-app notification is the
 * authoritative record; this email is a courtesy copy.
 */
class VisitorAccountApproved extends Mailable
{
    public function __construct(public Account $account) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your CustodiCore account has been approved');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-approved',
            text: 'emails.account-approved-text',
            with: ['name' => $this->account->displayName()],
        );
    }
}
