<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-authentication gate ("enter your password as a final step").
 *
 * Applied (alias `reauth`, see bootstrap/app.php) to every web route that
 * changes an EXISTING person's / record's details:
 *   - admin.users.update, admin.users.toggle-status   (Admin / Warden)
 *   - pdl.update                                      (Record Officer)
 *
 * The signed-in staff member must submit their OWN password in a
 * `current_password` field. The shared modal in
 * resources/views/partials/password-confirm.blade.php collects it and adds
 * it to the form just before submitting; this middleware is the real
 * enforcement, so skipping the modal (or disabling JS) just gets the
 * request rejected here.
 *
 * Wrong guesses are rate-limited per staff account so the form cannot be
 * used to brute-force a stolen, still-logged-in session's password.
 */
class ConfirmActorPassword
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if (! $actor) {
            abort(401);
        }

        $key = 'reauth:' . $actor->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->reject(
                $request,
                "Too many incorrect password attempts. Try again in {$seconds} seconds."
            );
        }

        $given = (string) $request->input('current_password', '');

        if ($given === '') {
            return $this->reject($request, 'Enter your password to confirm this change.');
        }

        if (! Hash::check($given, (string) $actor->getAuthPassword())) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return $this->reject($request, 'The password you entered is incorrect. Nothing was saved.');
        }

        RateLimiter::clear($key);

        return $next($request);
    }

    private function reject(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => ['current_password' => [$message]],
            ], 422);
        }

        return redirect()
            ->back()
            // Never flash the password (or the CSRF token) back into the form.
            ->withInput($request->except(['current_password', '_token']))
            ->withErrors(['current_password' => $message]);
    }
}
