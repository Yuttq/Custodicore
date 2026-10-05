<?php

namespace App\Services\Auth;

use App\Models\Account;

/**
 * Google Sign-In account decision flow for the mobile (visitor) API.
 *
 * Input is ONLY a VerifiedGoogleIdentity (from GoogleIdTokenVerifier) —
 * never request data. Order:
 *
 *  1. accounts.google_id = verified `sub`
 *       Staff            → not_visitor_account
 *       inactive Visitor → account_inactive
 *       active Visitor   → authenticated (last_login_at + Sanctum token)
 *  2. else accounts.email = verified email (case-insensitive)
 *       Staff            → not_visitor_account
 *       inactive Visitor → account_inactive
 *       active Visitor   → link_required — NEVER auto-linked on email
 *                          alone; linking needs the account password
 *                          (a later phase).
 *  3. else              → registration_required. Nothing is created: no
 *                          Account, VisitorProfile, token or stored `sub`
 *                          until the full registration form is submitted.
 *
 * Role/status always come from the database account. Apart from
 * last_login_at on a successful sign-in, no account is ever modified here.
 */
class GoogleAuthService
{
    public const TOKEN_NAME = 'mobile-google';

    public function decide(VerifiedGoogleIdentity $identity): GoogleAuthDecision
    {
        $linked = Account::with('role')->where('google_id', $identity->googleId)->first();

        if ($linked) {
            if ($rejection = $this->rejection($linked, $identity)) {
                return $rejection;
            }

            // forceFill: last_login_at is not fillable (same as AuthController::login).
            $linked->forceFill(['last_login_at' => now()])->save();
            $token = $linked->createToken(self::TOKEN_NAME)->plainTextToken;

            return GoogleAuthDecision::authenticated($identity, $linked, $token);
        }

        // MySQL's default collation already compares case-insensitively;
        // LOWER() makes that explicit (and true on SQLite in tests).
        $byEmail = Account::with('role')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($identity->email)])
            ->first();

        if ($byEmail) {
            return $this->rejection($byEmail, $identity)
                ?? GoogleAuthDecision::rejected(GoogleAuthDecision::LINK_REQUIRED, $identity, $byEmail);
        }

        return GoogleAuthDecision::rejected(GoogleAuthDecision::REGISTRATION_REQUIRED, $identity);
    }

    /** Role first, then status — the same order as the password login. */
    private function rejection(Account $account, VerifiedGoogleIdentity $identity): ?GoogleAuthDecision
    {
        if (! $account->isVisitor()) {
            return GoogleAuthDecision::rejected(GoogleAuthDecision::NOT_VISITOR_ACCOUNT, $identity, $account);
        }

        if ($account->status !== 'active') {
            return GoogleAuthDecision::rejected(GoogleAuthDecision::ACCOUNT_INACTIVE, $identity, $account);
        }

        return null;
    }
}
