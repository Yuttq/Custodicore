<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Session login for the three staff web dashboards (Warden/Admin, Record
 * Officer, Front Desk Officer) — all backed by the same `accounts` table
 * as the mobile app (see config/auth.php's 'web' guard + 'accounts'
 * provider). Visitor accounts are mobile-app only (Sanctum bearer tokens,
 * see App\Http\Controllers\Api\AuthController) and are explicitly refused
 * here even though they live in the same table.
 *
 * Route protection itself lives in routes/web.php (each dashboard's route
 * group carries ['auth', 'role:<Role Name>']) plus
 * App\Http\Middleware\EnsureRoleAccess — this controller only handles the
 * login form and the attempt/logout actions.
 */
class LoginController extends Controller
{
    private const STAFF_ROLES = [
        'System Administrator/Warden',
        'Record Officer',
        'Front Desk Officer',
    ];

    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Officers may type either their email or their username — accept
        // whichever they gave rather than forcing one format.
        $field = filter_var($credentials['email'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $account = Account::where($field, $credentials['email'])->with('role')->first();

        if ($account && $account->role?->role_name === 'Visitor') {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'Visitor accounts sign in through the CustodiCore mobile app, not this dashboard.']);
        }

        if (! $account || ! in_array($account->role?->role_name, self::STAFF_ROLES, true)) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'These credentials do not match a staff account.']);
        }

        if ($account->status !== 'active') {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => "This account is {$account->status}. Contact the Warden to restore access."]);
        }

        if (! Auth::guard('web')->attempt(['account_id' => $account->account_id, 'password' => $credentials['password']])) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'These credentials do not match a staff account.']);
        }

        $request->session()->regenerate();

        $account->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended($this->homeRouteFor($account->role->role_name));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function homeRouteFor(string $roleName): string
    {
        return match ($roleName) {
            'System Administrator/Warden' => route('admin.dashboard'),
            'Record Officer' => route('dashboard'),
            'Front Desk Officer' => route('frontdesk.dashboard'),
            default => route('login'),
        };
    }
}
