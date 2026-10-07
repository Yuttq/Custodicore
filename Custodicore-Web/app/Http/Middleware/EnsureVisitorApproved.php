<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile API features that require a staff-approved visitor
 * (visitor_profiles.verification_status = 'verified'), e.g.
 *   ->middleware(['auth:sanctum', 'visitor.approved'])
 *
 * A visitor whose email is verified but whose information/documents are
 * still under review (pending), or were not approved (rejected), can sign
 * in and use /me, /documents and /notifications — but not these routes.
 * This is the real security boundary; the app hiding the features is only
 * UX. Registered as the 'visitor.approved' alias in bootstrap/app.php.
 */
class EnsureVisitorApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();
        $profile = $account?->visitorProfile;

        if (! $account || ! $profile) {
            return response()->json(['message' => 'This account has no visitor profile.'], 403);
        }

        if (! $account->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email address before logging in.',
                'code' => 'email_not_verified',
            ], 403);
        }

        if ($profile->verification_status !== 'verified') {
            return response()->json([
                'message' => $profile->verification_status === 'rejected'
                    ? 'Your submitted information and documents were not approved. This feature is not available.'
                    : 'Your documents and information are under review. This feature will be available once staff approve your account.',
                'code' => 'visitor_not_approved',
                'verificationStatus' => $profile->verification_status,
            ], 403);
        }

        return $next($request);
    }
}
