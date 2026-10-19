<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffAccountRequest;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Services\Auth\StaffInvitationService;
use App\Support\Sanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Pages;
use Illuminate\View\View;

/**
 * Module 1.1 User Management — real accounts + staff_profiles rows (the
 * same tables the Records Officer and Front Desk modules' officers log
 * into).
 *
 * The admin's only write access on this dashboard is here: registering a
 * new BJMP officer account, updating an existing officer's details when
 * they request a change, and switching an account on or off.
 *
 * The admin never sets a password. A new account is created inactive and
 * the officer is sent a one-time "set up your account" link by email and
 * SMS (StaffInvitationService) to choose their own password. The admin can
 * resend the link while the officer has not finished setting up.
 */
class UserController extends Controller
{
    private const ROLE_NAMES = StaffAccountRequest::ROLE_NAMES;

    public function __construct(private StaffInvitationService $invitations) {}

    public function index(Request $request): View
    {
        $search = Sanitizer::text($request->query('q'));

        $accounts = self::rows($search);

        $all = self::rows(null);
        $summary = [
            ['label' => 'Total Accounts', 'value' => (string) count($all), 'icon' => 'users', 'accent' => 'info'],
            ['label' => 'Active', 'value' => (string) count(array_filter($all, fn ($a) => $a['status'] === 'active')), 'icon' => 'check-circle', 'accent' => 'success'],
            ['label' => 'Awaiting Setup', 'value' => (string) count(array_filter($all, fn ($a) => $a['invite'] !== null)), 'icon' => 'clock', 'accent' => 'warning'],
            ['label' => 'Inactive', 'value' => (string) count(array_filter($all, fn ($a) => $a['status'] !== 'active' && $a['invite'] === null)), 'icon' => 'warning', 'accent' => 'danger'],
        ];

        $accounts = Pages::of($accounts);

        return view('admin.users.index', [
            'accounts' => $accounts,
            'summary' => $summary,
            'search' => $search,
            'totalAccounts' => count($all),
            'suffixes' => Sanitizer::SUFFIXES,
            // False while MAIL_MAILER=log: the confirmation step then warns that nothing will really be emailed.
            'mailConnected' => ! StaffInvitationService::mailIsLogOnly(),
        ]);
    }

    public function store(StaffAccountRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $account = DB::transaction(function () use ($data) {
            $role = Role::where('role_name', $data['role_name'])->firstOrFail();

            $account = Account::create([
                'role_id' => $role->role_id,
                'username' => $this->uniqueUsername($data['email']),
                'email' => $data['email'],
                // Random and unknown to everyone: the officer chooses their own
                // through the setup link, and the account stays inactive until then.
                'password_hash' => StaffInvitationService::unusablePasswordHash(),
                'status' => 'inactive',
            ]);

            StaffProfile::create([
                'account_id' => $account->account_id,
                'employee_number' => $this->nextEmployeeNumber(),
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'suffix' => $data['suffix'] ?? null,
                'position' => $data['role_name'],
                'contact_number' => $data['contact_number'],
                'personal_email' => $data['personal_email'],
                'assigned_facility' => 'BJMP Facility — Main',
            ]);

            return $account;
        });

        // Outside the transaction: a mail/SMS problem must not undo the account.
        $delivery = $this->deliverInvitation($account);
        $name = $account->staffProfile()->value('full_name');

        AuditLog::record(
            'create',
            'accounts',
            $account->account_id,
            // audit_logs.description is varchar(255) — keep within it.
            Str::limit("Registered BJMP officer account for {$name} ({$data['role_name']}); setup link sent", 250),
            Module::CODE_USER_MANAGEMENT
        );

        return redirect()->route('admin.users.index')
            ->with('status', "Account created for {$name}. They must open the setup link to choose their own password. {$delivery}");
    }

    public function update(StaffAccountRequest $request, int $id): RedirectResponse
    {
        $data = $request->validated();
        $account = Account::with('staffProfile')->findOrFail($id);

        if ((int) $account->account_id === (int) auth()->id() && $data['role_name'] !== $account->role?->role_name) {
            return back()->withInput($request->except(['current_password', '_token']))
                ->withErrors(['role_name' => 'You cannot change your own role. Ask another administrator.']);
        }

        // A pending officer's link goes to the personal email, so a change to it (or to the sign-in email when there is no personal one) means the old link went astray.
        $oldRecipient = mb_strtolower($this->invitations->recipientFor($account));

        DB::transaction(function () use ($account, $data) {
            $role = Role::where('role_name', $data['role_name'])->firstOrFail();

            $account->update(['email' => $data['email'], 'role_id' => $role->role_id]);

            $profile = [
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'suffix' => $data['suffix'] ?? null,
                'position' => $data['role_name'],
                'contact_number' => $data['contact_number'],
                // Left blank: keep whatever the account already has.
                'personal_email' => $data['personal_email'] ?? $account->staffProfile?->personal_email,
            ];

            if ($account->staffProfile) {
                $account->staffProfile->update($profile);
            } else {
                StaffProfile::create($profile + [
                    'account_id' => $account->account_id,
                    'employee_number' => $this->nextEmployeeNumber(),
                ]);
            }
        });

        $account->refresh()->load('staffProfile');
        $name = $account->staffProfile->full_name;
        $note = '';

        // The officer has not set up yet and their address changed: the old
        // link went to the wrong place, so send a fresh one to the new address.
        $recipientChanged = mb_strtolower($this->invitations->recipientFor($account)) !== $oldRecipient;

        if ($account->invitationState() !== null && $recipientChanged) {
            $note = ' ' . $this->deliverInvitation($account);
        }

        AuditLog::record(
            'update',
            'accounts',
            $account->account_id,
            Str::limit("Updated account details for {$name} (requested update; password re-confirmed)", 250),
            Module::CODE_USER_MANAGEMENT
        );

        return redirect()->route('admin.users.index')->with('status', "Updated {$name}'s account.{$note}");
    }

    public function toggleStatus(int $id): RedirectResponse
    {
        $account = Account::with('staffProfile')->findOrFail($id);

        if ($account->invitationState() !== null) {
            return redirect()->route('admin.users.index')
                ->with('status', "{$account->displayName()} has not set up their account yet — resend the setup link instead.");
        }

        if ((int) $account->account_id === (int) auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('status', 'You cannot deactivate your own account.');
        }

        $account->status = $account->status === 'active' ? 'inactive' : 'active';
        $account->save();

        AuditLog::record(
            'update',
            'accounts',
            $account->account_id,
            Str::limit("Set {$account->displayName()}'s account to {$account->status} (password re-confirmed)", 250),
            Module::CODE_USER_MANAGEMENT
        );

        return redirect()->route('admin.users.index')->with('status', 'Account status updated.');
    }

    /** POST admin/users/{id}/resend-invite — a new link (the old one stops working). */
    public function resendInvite(int $id): RedirectResponse
    {
        $account = Account::with(['staffProfile', 'role'])->findOrFail($id);

        if ($account->invitationState() === null) {
            return redirect()->route('admin.users.index')
                ->with('status', "{$account->displayName()} has already set up their account.");
        }

        $delivery = $this->deliverInvitation($account);

        AuditLog::record(
            'update',
            'accounts',
            $account->account_id,
            Str::limit("Re-sent the account setup link to {$account->displayName()}", 250),
            Module::CODE_USER_MANAGEMENT
        );

        return redirect()->route('admin.users.index')
            ->with('status', "New setup link for {$account->displayName()}. The previous link no longer works. {$delivery}");
    }

    /**
     * Sends the setup link (email/SMS). If the email could not really be delivered,
     * the link is flashed once to the Warden's next page as a fallback. The flash
     * is removed after that single request; the link is never stored in plain text.
     */
    private function deliverInvitation(Account $account): string
    {
        $result = $this->invitations->send($account);

        // When the email really went out, the Warden never needs to see the link.
        // Only when it did not (mail not connected, or the send failed) is it
        // shown once so it can be handed over in person.
        if (empty($result['email_delivered'])) {
            session()->flash('setup_link', [
                'name' => $account->displayName(),
                'url' => $result['url'],
                'hours' => $this->invitations->expireHours(),
            ]);
        }

        return StaffInvitationService::describe($result);
    }

    private static function rows(?string $search): array
    {
        $like = $search !== null && $search !== '' ? Sanitizer::like($search) : null;

        return Account::query()
            ->with(['role', 'staffProfile'])
            ->whereHas('role', fn ($q) => $q->whereIn('role_name', self::ROLE_NAMES))
            ->when($like, function ($query) use ($like) {
                $query->where(function ($q) use ($like) {
                    $q->where('accounts.email', 'like', $like)
                        ->orWhere('accounts.username', 'like', $like)
                        ->orWhereHas('staffProfile', function ($p) use ($like) {
                            $p->where('last_name', 'like', $like)
                                ->orWhere('first_name', 'like', $like)
                                ->orWhere('middle_name', 'like', $like)
                                ->orWhere('full_name', 'like', $like)
                                ->orWhere('employee_number', 'like', $like)
                                ->orWhere('contact_number', 'like', $like)
                                ->orWhere('personal_email', 'like', $like);
                        });
                });
            })
            // Sorted by last name, then first name (staff without name parts last).
            ->orderBy(StaffProfile::select('last_name')->whereColumn('staff_profiles.account_id', 'accounts.account_id')->limit(1))
            ->orderBy(StaffProfile::select('first_name')->whereColumn('staff_profiles.account_id', 'accounts.account_id')->limit(1))
            ->orderBy('accounts.created_at')
            ->get()
            ->map(function ($account) {
                $profile = $account->staffProfile;
                $invite = $account->invitationState();

                // Old rows without name parts: a best guess for the Edit form to confirm.
                $parts = ['first' => $profile?->first_name, 'middle' => $profile?->middle_name, 'last' => $profile?->last_name];
                if ($profile && $parts['first'] === null && $parts['last'] === null) {
                    $words = preg_split('/\s+/', trim((string) $profile->full_name), -1, PREG_SPLIT_NO_EMPTY);
                    if (count($words) >= 2) {
                        $parts = ['first' => array_shift($words), 'last' => array_pop($words), 'middle' => $words ? implode(' ', $words) : null];
                    } else {
                        $parts['first'] = $words[0] ?? '';
                    }
                }

                return [
                    'id' => $account->account_id,
                    'employee_number' => $profile?->employee_number ?? '—',
                    'full_name' => $account->displayName(),
                    'list_name' => $profile?->listName() ?? $account->displayName(),
                    'first_name' => $parts['first'],
                    'middle_name' => $parts['middle'],
                    'last_name' => $parts['last'],
                    'suffix' => $profile?->suffix,
                    'contact_number' => $profile?->contact_number,
                    'photo' => $profile?->photoUrl(),
                    'personal_email' => $profile?->personal_email,
                    'role_name' => $account->role?->role_name ?? '—',
                    'email' => $account->email,
                    'status' => $account->status,
                    'invite' => $invite,
                    'display_status' => $invite === 'pending' ? 'invited' : ($invite === 'expired' ? 'invite_expired' : $account->status),
                    'is_self' => (int) $account->account_id === (int) auth()->id(),
                    'last_login_at' => $account->last_login_at?->format('Y-m-d H:i') ?? 'Never',
                ];
            })
            ->all();
    }

    private function nextEmployeeNumber(): string
    {
        $maxId = StaffProfile::max('staff_id') ?? 0;

        return 'EMP-' . str_pad((string) ($maxId + 1), 3, '0', STR_PAD_LEFT);
    }

    private function uniqueUsername(string $email): string
    {
        $base = Str::before($email, '@');
        $username = $base;
        $suffix = 1;

        while (Account::where('username', $username)->exists()) {
            $username = $base . $suffix++;
        }

        return $username;
    }
}
