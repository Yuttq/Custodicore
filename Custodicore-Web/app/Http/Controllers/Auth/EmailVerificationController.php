<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Visitor email verification links (Phase 2). See EmailVerificationService.
 *
 * Opening the emailed link (GET) only shows a confirmation page; the token
 * is consumed by the POST its button submits. That way mail scanners and
 * link previews that prefetch URLs cannot use up the single-use link.
 *
 * Nothing here signs the visitor in or issues a Sanctum token — the visitor
 * logs in to the app afterwards. The token is never logged or echoed in a
 * JSON response.
 */
class EmailVerificationController extends Controller
{
    /** Per-email resend limit (on top of the per-IP route throttle). */
    private const RESEND_MAX_ATTEMPTS = 3;
    private const RESEND_DECAY_SECONDS = 600;

    public function __construct(private EmailVerificationService $verification) {}

    /** GET /email/verify/{token} — confirmation page with a "Verify" button. */
    public function show(string $token): View
    {
        return view('auth.verify-email', ['state' => 'confirm', 'token' => $token]);
    }

    /** POST /email/verify — consumes the token, shows the result page. */
    public function verify(Request $request): View
    {
        $result = $this->verification->verify((string) $request->input('token', ''));

        return view('auth.verify-email', ['state' => $result]);
    }

    /**
     * POST /api/auth/email/verify {token} — the same check for API clients.
     *   200 verified | already_verified
     *   410 expired
     *   422 invalid (unknown, used, superseded)
     */
    public function verifyApi(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        $result = $this->verification->verify($data['token']);

        return match ($result) {
            EmailVerificationService::VERIFIED, EmailVerificationService::ALREADY_VERIFIED => response()->json([
                'status' => $result,
                'message' => 'Your email has been verified. You can now log in.',
            ]),
            EmailVerificationService::EXPIRED => response()->json([
                'status' => $result,
                'code' => 'verification_link_expired',
                'message' => 'This verification link has expired. Request a new verification email.',
            ], 410),
            default => response()->json([
                'status' => EmailVerificationService::INVALID,
                'code' => 'verification_link_invalid',
                'message' => 'This verification link is invalid or has already been used.',
            ], 422),
        };
    }

    /**
     * POST /api/auth/email/resend {email}. Always the same answer whether or
     * not the address is registered (or already verified), so it cannot be
     * used to discover accounts. Limited per IP (route throttle) and per
     * address (here) — the per-address limit applies to every address alike.
     */
    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:100']]);

        $key = 'email-verification-resend:'.sha1(mb_strtolower(trim($data['email'])));

        if (RateLimiter::tooManyAttempts($key, self::RESEND_MAX_ATTEMPTS)) {
            return response()->json([
                'code' => 'too_many_requests',
                'message' => 'Too many verification emails were requested. Please wait a few minutes and try again.',
                'retryAfter' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, self::RESEND_DECAY_SECONDS);

        $this->verification->resend($data['email']);

        return response()->json([
            'status' => 'verification_email_requested',
            'message' => 'If this email belongs to an account that still needs verification, a new verification link has been sent.',
        ]);
    }
}
