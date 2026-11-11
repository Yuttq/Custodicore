<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VisitorPasswordChanged;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use App\Support\VisitorPasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * POST /api/me/password — a signed-in visitor changes their own password
 * (mobile Profile → Security Settings).
 *
 *  - The current password is required, so an unlocked phone or a stolen
 *    token alone cannot take the account over.
 *  - Wrong current passwords are limited per account (LOCK_* below) on top
 *    of the route's per-IP throttle. A locked account refuses even the right
 *    password until the lock decays, as login() does.
 *  - The new password follows VisitorPasswordPolicy (with the email check)
 *    and must differ from the current one.
 *  - Every other mobile token is revoked; the phone that made the change
 *    stays signed in.
 *  - An audit entry is written and the visitor is emailed, so a change they
 *    did not make is noticed. A mail failure is logged and never undoes the
 *    change.
 *
 * Passwords are never logged or echoed back.
 *
 *   200 password_changed
 *   403 not a visitor account / account not active
 *   422 currentPassword wrong or missing; password fails the policy,
 *       equals the current one or is not confirmed
 *   429 too_many_requests {retryAfter}
 */
class PasswordChangeController extends Controller
{
    public const LOCK_MAX_FAILURES = 5;
    public const LOCK_DECAY_SECONDS = 900;

    public function update(Request $request): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();

        if (! $account->isVisitor() || $account->status !== 'active') {
            return response()->json(['message' => 'This account cannot change its password here.'], 403);
        }

        $request->validate([
            'currentPassword' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'currentPassword.required' => 'Enter your current password.',
            'password.required' => 'Enter a new password.',
        ]);

        $key = 'password-change-failures:'.$account->account_id;

        if (RateLimiter::tooManyAttempts($key, self::LOCK_MAX_FAILURES)) {
            return response()->json([
                'code' => 'too_many_requests',
                'message' => 'Too many incorrect attempts. Please wait a few minutes and try again.',
                'retryAfter' => RateLimiter::availableIn($key),
            ], 429);
        }

        if (! Hash::check((string) $request->input('currentPassword'), $account->password_hash)) {
            RateLimiter::hit($key, self::LOCK_DECAY_SECONDS);

            throw ValidationException::withMessages(['currentPassword' => 'Your current password is incorrect.']);
        }

        RateLimiter::clear($key);

        $request->validate([
            'password' => VisitorPasswordPolicy::rules($account->email),
            'password_confirmation' => ['required', 'string'],
        ], [
            'password_confirmation.required' => 'Confirm your new password.',
        ]);

        $password = (string) $request->input('password');

        if (Hash::check($password, $account->password_hash)) {
            throw ValidationException::withMessages(['password' => 'Your new password must be different from your current password.']);
        }

        $current = $account->currentAccessToken();
        $currentTokenId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        DB::transaction(function () use ($account, $password, $currentTokenId) {
            $locked = Account::lockForUpdate()->findOrFail($account->account_id);
            $locked->forceFill(['password_hash' => Hash::make($password)])->save();

            $locked->tokens()
                ->when($currentTokenId !== null, fn ($q) => $q->whereKeyNot($currentTokenId))
                ->delete();

            AuditLog::record(
                'update',
                'accounts',
                $locked->account_id,
                Str::limit("{$locked->displayName()} changed their password in the mobile app", 250),
                Module::CODE_USER_MANAGEMENT
            );
        });

        try {
            Mail::to($account->email)->send(new VisitorPasswordChanged($account, now()));
        } catch (\Throwable $e) {
            // Account id and exception class only: no personal data in the log.
            Log::warning("Password change email could not be sent for account #{$account->account_id} (".$e::class.')');
        }

        return response()->json([
            'status' => 'password_changed',
            'message' => 'Your password has been changed. Other devices have been signed out.',
        ]);
    }
}
