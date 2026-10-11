<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\StaffInvitationService;
use App\Support\Sanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The page a new officer lands on from the "set up your account" email/SMS
 * (see StaffInvitationService). Public — the one-time token in the URL is
 * the credential. Opening the page (GET) changes nothing, so mail scanners
 * and link previews cannot use the link up; it is consumed by the POST.
 */
class StaffSetupController extends Controller
{
    public function __construct(private StaffInvitationService $invitations) {}

    /** GET /staff/setup/{token} */
    public function show(string $token): View
    {
        [$state, $account] = $this->invitations->find($token);

        if ($state !== StaffInvitationService::OK) {
            return view('auth.staff-setup', ['state' => $state]);
        }

        return view('auth.staff-setup', [
            'state' => 'form',
            'token' => $token,
            'account' => $account,
            'hours' => $this->invitations->expireHours(),
        ]);
    }

    /** POST /staff/setup */
    public function submit(Request $request): RedirectResponse|View
    {
        $token = (string) $request->input('token', '');

        [$state, $account] = $this->invitations->find($token);
        if ($state !== StaffInvitationService::OK) {
            return view('auth.staff-setup', ['state' => $state]);
        }

        $request->merge([
            'username' => Sanitizer::username($request->input('username')),
            'contact_number' => Sanitizer::mobile($request->input('contact_number')),
        ]);

        $staffId = $account->staffProfile?->staff_id;

        $validator = Validator::make($request->all(), [
            'username' => [
                'required', 'string', 'min:4', 'max:30', 'regex:/^[a-z0-9][a-z0-9._-]*$/',
                Rule::unique('accounts', 'username')->ignore($account->account_id, 'account_id'),
            ],
            'contact_number' => [
                'required', 'regex:' . Sanitizer::MOBILE_REGEX,
                Rule::unique('staff_profiles', 'contact_number')->ignore($staffId, 'staff_id'),
            ],
            'password' => [
                'required', 'confirmed',
                Password::min(10)->letters()->mixedCase()->numbers()->symbols(),
                function (string $attribute, $value, \Closure $fail) use ($request, $account) {
                    $lower = mb_strtolower((string) $value);
                    if ($lower === mb_strtolower($account->email) || $lower === (string) $request->input('username')) {
                        $fail('Your password cannot be the same as your email or username.');
                    }
                },
            ],
        ], [
            'username.regex' => 'Use only lowercase letters, numbers, dots, dashes and underscores (start with a letter or number).',
            'username.unique' => 'That username is already taken. Choose another.',
            'contact_number.regex' => 'Enter a valid Philippine mobile number, e.g. 0917 123 4567.',
            'contact_number.unique' => 'That mobile number already belongs to another staff member.',
            'password.confirmed' => 'The two passwords do not match.',
        ], [
            'contact_number' => 'mobile number',
        ]);

        if ($validator->fails()) {
            return redirect()->route('staff-setup.show', ['token' => $token])
                ->withErrors($validator)
                ->withInput($request->only('username', 'contact_number'));
        }

        $done = $this->invitations->complete(
            $token,
            (string) $request->input('password'),
            (string) $request->input('username'),
            (string) $request->input('contact_number'),
        );

        if (! $done) {
            return view('auth.staff-setup', ['state' => StaffInvitationService::INVALID]);
        }

        return redirect()->route('login')->with('status', 'Your account is ready. Sign in with your email or username and the password you just chose.');
    }
}
