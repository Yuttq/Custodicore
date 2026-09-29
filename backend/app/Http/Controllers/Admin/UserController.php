<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\StaffProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Module 1.1 User Management — real accounts + staff_profiles rows (the
 * same tables the Records Officer and Front Desk modules' officers log
 * into).
 *
 * The admin's only write access on this dashboard is here: registering a
 * new BJMP officer account, and updating an existing officer's details
 * when they request a change. PDL and Visitor data (see those modules)
 * are read-only from this dashboard.
 *
 * New accounts get the default password "password" (same as every
 * DatabaseSeeder-seeded account) — there's no "send an invite email" flow
 * built yet, so tell the new officer that password out of band.
 */
class UserController extends Controller
{
    private const ROLE_NAMES = ['System Administrator/Warden', 'Record Officer', 'Front Desk Officer'];

    public function index(): View
    {
        $accounts = self::rows();

        $activeCount = count(array_filter($accounts, fn ($a) => $a['status'] === 'active'));

        $summary = [
            ['label' => 'Total Accounts', 'value' => (string) count($accounts), 'icon' => 'users', 'accent' => 'info'],
            ['label' => 'Active', 'value' => (string) $activeCount, 'icon' => 'check-circle', 'accent' => 'success'],
            ['label' => 'Inactive', 'value' => (string) (count($accounts) - $activeCount), 'icon' => 'warning', 'accent' => 'danger'],
        ];

        return view('admin.users.index', compact('accounts', 'summary'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'role_name' => ['required', 'in:' . implode(',', self::ROLE_NAMES)],
            'email' => ['required', 'email', 'max:150', 'unique:accounts,email'],
        ]);

        DB::transaction(function () use ($data) {
            $role = Role::where('role_name', $data['role_name'])->firstOrFail();
            $username = $this->uniqueUsername($data['email']);

            $account = Account::create([
                'role_id' => $role->role_id,
                'username' => $username,
                'email' => $data['email'],
                'password_hash' => Hash::make('password'),
                'status' => 'active',
            ]);

            StaffProfile::create([
                'account_id' => $account->account_id,
                'employee_number' => $this->nextEmployeeNumber(),
                'full_name' => $data['full_name'],
                'position' => $data['role_name'],
                'assigned_facility' => 'BJMP Facility — Main',
            ]);

            AuditLog::record(
                'create',
                'accounts',
                $account->account_id,
                "Registered BJMP officer account for {$data['full_name']} ({$data['role_name']})",
                Module::CODE_USER_MANAGEMENT
            );
        });

        return redirect()->route('admin.users.index')->with('status', "Account created for {$data['full_name']}. Default password: password");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'role_name' => ['required', 'in:' . implode(',', self::ROLE_NAMES)],
            'email' => ['required', 'email', 'max:150', 'unique:accounts,email,' . $id . ',account_id'],
        ]);

        $account = Account::findOrFail($id);

        DB::transaction(function () use ($account, $data) {
            $role = Role::where('role_name', $data['role_name'])->firstOrFail();

            $account->update(['email' => $data['email'], 'role_id' => $role->role_id]);

            if ($account->staffProfile) {
                $account->staffProfile->update(['full_name' => $data['full_name'], 'position' => $data['role_name']]);
            } else {
                StaffProfile::create([
                    'account_id' => $account->account_id,
                    'employee_number' => $this->nextEmployeeNumber(),
                    'full_name' => $data['full_name'],
                    'position' => $data['role_name'],
                ]);
            }

            AuditLog::record(
                'update',
                'accounts',
                $account->account_id,
                "Updated account details for {$data['full_name']} (requested update)",
                Module::CODE_USER_MANAGEMENT
            );
        });

        return redirect()->route('admin.users.index')->with('status', "Updated {$data['full_name']}'s account.");
    }

    public function toggleStatus(int $id): RedirectResponse
    {
        $account = Account::with('staffProfile')->findOrFail($id);

        $account->status = $account->status === 'active' ? 'inactive' : 'active';
        $account->save();

        AuditLog::record(
            'update',
            'accounts',
            $account->account_id,
            "Set {$account->displayName()}'s account to {$account->status}",
            Module::CODE_USER_MANAGEMENT
        );

        return redirect()->route('admin.users.index')->with('status', 'Account status updated.');
    }

    private static function rows(): array
    {
        return Account::query()
            ->with(['role', 'staffProfile'])
            ->whereHas('role', fn ($q) => $q->whereIn('role_name', self::ROLE_NAMES))
            ->orderBy('created_at')
            ->get()
            ->map(fn ($account) => [
                'id' => $account->account_id,
                'employee_number' => $account->staffProfile?->employee_number ?? '—',
                'full_name' => $account->displayName(),
                'role_name' => $account->role?->role_name ?? '—',
                'email' => $account->email,
                'status' => $account->status,
                'last_login_at' => $account->last_login_at?->format('Y-m-d H:i') ?? 'Never',
            ])
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
