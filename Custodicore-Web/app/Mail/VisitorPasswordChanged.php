<?php

namespace App\Mail;

use App\Models\Account;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent after a visitor changes their password in the app
 * (Api\PasswordChangeController), so a change they did not make is noticed.
 * Holds no link and no password.
 */
class VisitorPasswordChanged extends Mailable
{
    public function __construct(public Account $account, public CarbonInterface $changedAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your CustodiCore password was changed');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-changed',
            text: 'emails.password-changed-text',
            with: [
                'name' => $this->account->displayName(),
                'changedAt' => $this->changedAt->copy()->timezone(config('app.timezone'))->format('F j, Y \a\t g:i A'),
            ],
        );
    }
}
