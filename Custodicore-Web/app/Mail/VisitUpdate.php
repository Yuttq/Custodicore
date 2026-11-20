<?php

namespace App\Mail;

use App\Models\Account;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email copy of a visit notification (Notification::EMAILED_TYPES): the
 * same title as the subject and the same message as the body, so the email
 * and the in-app notice never say different things. The in-app notification
 * is the authoritative record; this email is a courtesy copy.
 */
class VisitUpdate extends Mailable
{
    public function __construct(
        public Account $account,
        public string $title,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "CustodiCore: {$this->title}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.visit-update',
            text: 'emails.visit-update-text',
            with: [
                'name' => $this->account->displayName(),
                'heading' => $this->title,
                'body' => $this->body,
            ],
        );
    }
}
