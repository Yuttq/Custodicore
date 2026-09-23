<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Module 1.1 User Management — accounts table (roles: System
 * Administrator/Warden, Record Officer, Front Desk Officer, Visitor).
 *
 * The admin's only write access on this dashboard is here: registering a
 * new BJMP officer account, and updating an existing officer's details
 * when they request a change. PDL and Visitor data (see those modules)
 * are read-only from this dashboard.
 *
 * Data lives in the session only (no database yet): creating or updating
 * an account persists for as long as your browser session lasts, and
 * resets when the session expires.
 */
class UserController extends Controller
{
    public const SESSION_KEY = 'users_rows';

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
            'role_name' => ['required', 'in:System Administrator/Warden,Record Officer,Front Desk Officer'],
            'email' => ['required', 'email', 'max:150'],
        ]);

        $accounts = self::rows();
        $nextId = self::nextId($accounts);

        $accounts[] = [
            'id' => $nextId,
            'employee_number' => 'EMP-' . str_pad((string) (100 + $nextId), 3, '0', STR_PAD_LEFT),
            'full_name' => $data['full_name'],
            'role_name' => $data['role_name'],
            'email' => $data['email'],
            'status' => 'active',
            'last_login_at' => 'Never',
        ];

        session([self::SESSION_KEY => $accounts]);

        AuditLog::record('create', 'User Management', "Registered BJMP officer account for {$data['full_name']} ({$data['role_name']})");

        return redirect()->route('admin.users.index')->with('status', "Account created for {$data['full_name']}.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'role_name' => ['required', 'in:System Administrator/Warden,Record Officer,Front Desk Officer'],
            'email' => ['required', 'email', 'max:150'],
        ]);

        $accounts = self::rows();

        foreach ($accounts as &$account) {
            if ($account['id'] === $id) {
                $account['full_name'] = $data['full_name'];
                $account['role_name'] = $data['role_name'];
                $account['email'] = $data['email'];
                AuditLog::record('update', 'User Management', "Updated account details for {$data['full_name']} (requested update)");
                break;
            }
        }
        unset($account);

        session([self::SESSION_KEY => $accounts]);

        return redirect()->route('admin.users.index')->with('status', "Updated {$data['full_name']}'s account.");
    }

    public function toggleStatus(int $id): RedirectResponse
    {
        $accounts = self::rows();

        foreach ($accounts as &$account) {
            if ($account['id'] === $id) {
                $account['status'] = $account['status'] === 'active' ? 'inactive' : 'active';
                AuditLog::record('update', 'User Management', "Set {$account['full_name']}'s account to {$account['status']}");
                break;
            }
        }
        unset($account);

        session([self::SESSION_KEY => $accounts]);

        return redirect()->route('admin.users.index')->with('status', 'Account status updated.');
    }

    public static function rows(): array
    {
        if (! session()->has(self::SESSION_KEY)) {
            session([self::SESSION_KEY => self::defaultRows()]);
        }

        return session(self::SESSION_KEY);
    }

    private static function nextId(array $rows): int
    {
        return $rows === [] ? 1 : max(array_column($rows, 'id')) + 1;
    }

    private static function defaultRows(): array
    {
        return [
            ['id' => 1, 'employee_number' => 'EMP-001', 'full_name' => 'Warden Ana R. Domingo', 'role_name' => 'System Administrator/Warden', 'email' => 'a.domingo@bjmp.gov.ph', 'status' => 'active', 'last_login_at' => '2026-09-22 07:58'],
            ['id' => 2, 'employee_number' => 'EMP-014', 'full_name' => 'Rico P. Salcedo', 'role_name' => 'Record Officer', 'email' => 'r.salcedo@bjmp.gov.ph', 'status' => 'active', 'last_login_at' => '2026-09-22 08:02'],
            ['id' => 3, 'employee_number' => 'EMP-022', 'full_name' => 'Grace T. Manalo', 'role_name' => 'Record Officer', 'email' => 'g.manalo@bjmp.gov.ph', 'status' => 'active', 'last_login_at' => '2026-09-21 17:40'],
            ['id' => 4, 'employee_number' => 'EMP-031', 'full_name' => 'Noel D. Fernandez', 'role_name' => 'Front Desk Officer', 'email' => 'n.fernandez@bjmp.gov.ph', 'status' => 'active', 'last_login_at' => '2026-09-22 09:00'],
            ['id' => 5, 'employee_number' => 'EMP-037', 'full_name' => 'Jhoana C. Reyes', 'role_name' => 'Front Desk Officer', 'email' => 'j.reyes@bjmp.gov.ph', 'status' => 'inactive', 'last_login_at' => '2026-08-30 15:11'],
        ];
    }
}
