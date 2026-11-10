<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * "Forgot Password" for visitors (mobile app) and staff (web). See PasswordResetService.
 *
 * The mobile app requests a visitor link (API); the web login page's
 * "Forgot password?" requests a staff link (forgotForm()/sendStaffLink()).
 * Either link opens the same web page where the owner chooses a new password. Nothing here signs anyone in or issues a
 * Sanctum token — the visitor logs in to the app afterwards. The token is
 * never logged, flashed to the session, or echoed in a JSON response.
 */
class PasswordResetController extends Controller
{
    /** Per-email request limit (on top of the per-IP route throttle). */
    private const REQUEST_MAX_ATTEMPTS = 3;
    private const REQUEST_DECAY_SECONDS = 600;

    public const GENERIC_MESSAGE = 'If an account exists for this email address, a password reset link has been sent.';

    public function __construct(private PasswordResetService $passwords) {}

    /**
     * POST /api/auth/password/forgot {email}. Always the same answer whether
     * the address is unknown, belongs to staff or an inactive account, or
     * gets a link — so it cannot be used to discover accounts. Limited per
     * IP (route throttle) and per address (here, applied to every address).
     *   200 password_reset_requested
     *   422 email missing/invalid
     *   429 too_many_requests {retryAfter}
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:100']]);

        $key = 'password-reset-request:'.sha1(mb_strtolower(trim($data['email'])));

        if (RateLimiter::tooManyAttempts($key, self::REQUEST_MAX_ATTEMPTS)) {
            return response()->json([
                'code' => 'too_many_requests',
                'message' => 'Too many password reset emails were requested. Please wait a few minutes and try again.',
                'retryAfter' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, self::REQUEST_DECAY_SECONDS);

        $this->passwords->request($data['email']);

        return response()->json([
            'status' => 'password_reset_requested',
            'message' => self::GENERIC_MESSAGE,
        ]);
    }

    /** GET /forgot-password — staff "Forgot password?" form (web login page). */
    public function forgotForm(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * POST /forgot-password {email} — staff only. Only an email registered to
     * an active staff account is accepted; anything else gets an error and no
     * email. Limited per IP (route throttle) and per address (here).
     */
    public function sendStaffLink(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['email' => ['required', 'string', 'email', 'max:100']],
            ['email.email' => 'Enter the email address you use to sign in, e.g. juan.delacruz@gmail.com.'],
        );

        $key = 'staff-password-reset-request:'.sha1(mb_strtolower(trim($data['email'])));

        if (RateLimiter::tooManyAttempts($key, self::REQUEST_MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return back()->withInput($request->only('email'))
                ->withErrors(['email' => "Too many reset emails were requested for this address. Please wait about {$minutes} minute(s) and try again."]);
        }

        RateLimiter::hit($key, self::REQUEST_DECAY_SECONDS);

        if (! $this->passwords->isRegisteredStaffEmail($data['email'])) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'This email is not registered to an active staff account. Check the spelling, or contact your Admin.']);
        }

        $this->passwords->request($data['email'], PasswordResetService::AUDIENCE_STAFF);

        return redirect()->route('password.request')
            ->with('status', 'We sent a password reset link to '.mb_strtolower(trim($data['email'])).'. Check your inbox (and spam folder). The link expires in '.$this->passwords->expiresInMinutes().' minutes.');
    }

    /** GET /password/reset/{token}?email= — the new-password form, or an error page for a dead link. */
    public function show(Request $request, string $token): View
    {
        $email = $request->query('email');
        $email = is_string($email) ? $email : ''; // ?email[]=x is ignored, not cast

        if ($email !== '' && ! $this->passwords->tokenIsValid($email, $token)) {
            return view('auth.reset-password', ['state' => 'invalid', 'isStaff' => false]);
        }

        // After a failed submit the address comes back as old input instead of ?email=.
        $lookup = $email !== '' ? $email : (is_string(old('email')) ? old('email') : null);

        return view('auth.reset-password', [
            'state' => 'form',
            'token' => $token,
            'email' => $email,
            // Only decided for a link already proven valid above (or a refilled form).
            'isStaff' => $this->passwords->isStaffEmail($lookup),
        ]);
    }

    /** POST /password/reset {token, email, password, password_confirmation}. */
    public function reset(Request $request): View|RedirectResponse
    {
        // A crafted email[]=x must fail validation, not break a string cast.
        $email = $request->input('email');
        $email = is_string($email) ? $email : null;

        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:100'],
            // Staff officers get the staff policy, visitors the visitor policy.
            'password' => $this->passwords->rulesFor($email),
        ], [
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        if ($validator->fails()) {
            $token = $request->input('token');
            if (! is_string($token) || $token === '' || strlen($token) > 255) {
                return view('auth.reset-password', ['state' => 'invalid', 'isStaff' => false]);
            }

            // Back to the reset page — explicitly, because that page is never
            // stored as the session's previous URL (see App\Http\Middleware\
            // StartSession), so back() can't find it. No ?email=: the form is
            // refilled from the flashed input instead, so a mistyped address
            // shows its validation error rather than an "invalid link" page.
            // Only the email is flashed — never the token or passwords — and
            // only when it is a string: the form echoes it back.
            return redirect()
                ->route('password.reset', ['token' => $token])
                ->withErrors($validator)
                ->withInput($email !== null ? ['email' => $email] : []);
        }

        $result = $this->passwords->reset(
            (string) $request->input('email'),
            (string) $request->input('token'),
            (string) $request->input('password'),
        );

        return view('auth.reset-password', [
            'state' => $result === PasswordResetService::RESET ? 'done' : 'invalid',
            'isStaff' => $this->passwords->isStaffEmail((string) $request->input('email')),
        ]);
    }
}
