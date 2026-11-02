<?php

namespace App\Console\Commands;

use App\Http\Requests\Admin\StaffAccountRequest;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Services\Auth\StaffInvitationService;
use App\Services\Auth\StaffMfaService;
use App\Services\SmsSender;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * php artisan cc:create-first-warden --email=... --first-name=... --last-name=... --contact=...
 *
 * Creates the very first Warden on a fresh installation, the same way Admin →
 * Users creates staff: no password is chosen or shown by anyone. The account
 * is `inactive` with a random password nobody knows, and a one-time setup
 * link (stored only as a hash) is emailed to the Warden, who chooses their
 * own password and then sets up two-step verification at first sign-in.
 *
 * Runs only while no Warden account exists at all (active, inactive or
 * awaiting setup); more Wardens are added from Admin → Users. Needs shell
 * access to the server, so it cannot be triggered from the website. Prints
 * the account id, the masked email and whether delivery worked — never the
 * link, unless the email could not be delivered and --show-link was given.
 */
class CreateFirstWarden extends Command
{
    private const WARDEN = 'System Administrator/Warden';

    protected $signature = 'cc:create-first-warden
        {--email= : The Warden\'s own email: the setup link goes here and it is their sign-in email}
        {--first-name= : First name}
        {--last-name= : Last name}
        {--middle-name= : Middle name (optional)}
        {--suffix= : Suffix such as Jr. (optional)}
        {--contact= : Philippine mobile number, e.g. 09171234567}
        {--show-link : Show the setup link here, only if it could not be emailed}';

    protected $description = 'Create the first Warden account on a new installation (setup link by email; no password is shown)';

    public function handle(StaffInvitationService $invitations): int
    {
        $wardenRole = Role::where('role_name', self::WARDEN)->first();

        if (! $wardenRole) {
            $this->error('The Warden role does not exist yet. Run: php artisan db:seed --class=ReferenceDataSeeder --force');

            return self::FAILURE;
        }

        if (Account::where('role_id', $wardenRole->role_id)->exists()) {
            $this->error('A Warden account already exists (active, inactive or awaiting setup). Add more Wardens from Admin → Users.');

            return self::FAILURE;
        }

        if (StaffInvitationService::mailIsLogOnly() && ! $this->option('show-link')) {
            $this->error('Email is not set up (MAIL_MAILER is "' . config('mail.default') . '"), so the setup link could not be delivered. '
                . 'Set up mail and check it with php artisan cc:mail-test, or run again with --show-link to see the link here.');

            return self::FAILURE;
        }

        $data = $this->validated();
        if ($data === null) {
            return self::FAILURE;
        }

        $account = $invitations->createStaffAccount($data);
        $result = $invitations->send($account);
        $name = (string) $account->staffProfile()->value('full_name');

        AuditLog::record(
            'create',
            'accounts',
            $account->account_id,
            Str::limit("Registered the first Warden account for {$name} from the server console; setup link sent", 250),
            Module::CODE_USER_MANAGEMENT
        );

        $this->info("Warden account #{$account->account_id} created for {$name}. It stays inactive until the setup link is used.");

        $to = StaffMfaService::maskEmail($result['email_to']);
        $sms = match ($result['sms']) {
            SmsSender::SENT => 'texted',
            SmsSender::LOGGED => 'not texted (no SMS provider configured)',
            SmsSender::FAILED => 'text FAILED',
            default => 'no mobile number',
        };

        if ($result['email_delivered']) {
            $this->info("Setup link emailed to {$to} (valid {$invitations->expireHours()} hours, one use). SMS: {$sms}.");

            if ($this->option('show-link')) {
                $this->line('The link was emailed, so it is not shown here.');
            }

            return self::SUCCESS;
        }

        $this->warn('Setup link NOT emailed to ' . $to . ': '
            . ($result['email'] ? 'mail is in log mode, nothing was sent' : ($result['email_problem'] ?? 'unknown problem')) . ". SMS: {$sms}.");

        if (! $this->option('show-link')) {
            $this->error('The account was kept, but its setup link was not delivered and is not shown. '
                . 'Fix the mail settings (php artisan cc:mail-test) before relying on this account.');

            return self::FAILURE;
        }

        $this->warn("Give this link only to {$name}, in person or over a private channel. It works once and expires in {$invitations->expireHours()} hours. It is not stored or logged:");
        $this->line($result['url']);

        return self::SUCCESS;
    }

    /**
     * The same checks as Admin → Users (StaffAccountRequest): name format and
     * duplicates, a Philippine mobile number nobody else uses, and an email
     * that is not already anyone's sign-in or staff email.
     *
     * @return array<string, mixed>|null the validated data, or null after printing the problems
     */
    private function validated(): ?array
    {
        $request = StaffAccountRequest::createFrom(Request::create('/', 'POST', [
            'first_name' => $this->option('first-name'),
            'middle_name' => $this->option('middle-name'),
            'last_name' => $this->option('last-name'),
            'suffix' => $this->option('suffix'),
            'role_name' => self::WARDEN,
            'contact_number' => $this->option('contact'),
            'personal_email' => $this->option('email'),
        ]));
        $request->setContainer($this->laravel)->setRedirector($this->laravel->make(Redirector::class));

        try {
            $request->validateResolved();
        } catch (ValidationException $e) {
            $this->error('Nothing was created:');
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->line('  - ' . $message);
                }
            }

            return null;
        }

        return $request->validated();
    }
}
