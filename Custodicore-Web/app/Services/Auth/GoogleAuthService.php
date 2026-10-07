<?php

namespace App\Services\Auth;

use App\Models\Account;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
 *                          (link(), POST /api/auth/google/link).
 *  3. else              → registration_required. Nothing is created: no
 *                          Account, VisitorProfile, token or stored `sub`
 *                          until the full registration form is submitted.
 *
 * Role/status always come from the database account. Apart from
 * last_login_at on a successful sign-in, decide() never modifies an account;
 * only link() (existing account) and register() (new account) set google_id.
 *
 * Email verification (Phase 2): a verified Google token proves ownership of
 * its email, so register(), link() and a sign-in whose Google email still
 * equals the account email set email_verified_at if it is not set yet.
 * Staff review (visitor_profiles.verification_status) is never touched —
 * a new Google visitor is `pending` like any other.
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

            return $this->authenticate($linked, $identity);
        }

        $byEmail = $this->findByEmail($identity);

        if ($byEmail) {
            return $this->rejection($byEmail, $identity)
                ?? GoogleAuthDecision::rejected(GoogleAuthDecision::LINK_REQUIRED, $identity, $byEmail);
        }

        return GoogleAuthDecision::rejected(GoogleAuthDecision::REGISTRATION_REQUIRED, $identity);
    }

    /**
     * Explicit linking of a verified Google identity to the existing Visitor
     * account with the same (verified) email, proven by its Custodicore
     * password. Order:
     *
     *  1. accounts.email = verified email (case-insensitive), else
     *       account_not_found — nothing is created
     *  2. Staff → not_visitor_account; inactive → account_inactive
     *       (before the password, so linking is never a staff auth path)
     *  3. already linked to this same `sub` → authenticated, unchanged
     *       (idempotent; the verified token alone already proves it, exactly
     *       as on /auth/google)
     *  4. password must match password_hash (Hash::check) → invalid_password
     *  5. `sub` linked to another account, or this account linked to another
     *       `sub` → google_account_mismatch; a Google identity is never moved
     *       or replaced
     *  6. in one transaction: google_id = `sub`, last_login_at, Sanctum token
     *
     * Only the `sub` is stored — never the Google email, name, picture or token.
     */
    public function link(VerifiedGoogleIdentity $identity, string $password): GoogleAuthDecision
    {
        $account = $this->findByEmail($identity);

        if (! $account) {
            return GoogleAuthDecision::rejected(GoogleAuthDecision::ACCOUNT_NOT_FOUND, $identity);
        }

        if ($rejection = $this->rejection($account, $identity)) {
            return $rejection;
        }

        if ($account->google_id !== null && hash_equals($account->google_id, $identity->googleId)) {
            return $this->authenticate($account, $identity);
        }

        if (! Hash::check($password, $account->password_hash)) {
            return GoogleAuthDecision::rejected(GoogleAuthDecision::INVALID_PASSWORD, $identity, $account);
        }

        try {
            return DB::transaction(function () use ($account, $identity) {
                // Re-read under lock so a concurrent link can't slip in between
                // the checks and the write.
                $locked = Account::with('role')->lockForUpdate()->findOrFail($account->account_id);

                $subTaken = Account::where('google_id', $identity->googleId)
                    ->where('account_id', '!=', $locked->account_id)
                    ->exists();

                if ($subTaken || $locked->google_id !== null) {
                    return GoogleAuthDecision::rejected(GoogleAuthDecision::GOOGLE_ACCOUNT_MISMATCH, $identity, $locked);
                }

                // forceFill: google_id is deliberately not fillable. The
                // account's email is the verified Google email (step 1), so
                // this also proves email ownership.
                $locked->forceFill([
                    'google_id' => $identity->googleId,
                    'last_login_at' => now(),
                    'email_verified_at' => $locked->email_verified_at ?? now(),
                ])->save();

                $token = $locked->createToken(self::TOKEN_NAME)->plainTextToken;

                return GoogleAuthDecision::authenticated($identity, $locked, $token);
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race on accounts.google_id's unique index; rolled back.
            return GoogleAuthDecision::rejected(GoogleAuthDecision::GOOGLE_ACCOUNT_MISMATCH, $identity, $account);
        }
    }

    /**
     * Google registration (after /auth/google answered registration_required):
     * creates a new Visitor account for a verified Google identity nobody
     * owns yet. $createAccount(string $verifiedEmail): Account builds the
     * Account + VisitorProfile from the validated registration form (see
     * AuthController::createVisitorAccount()). Order:
     *
     *  1. accounts.google_id = verified `sub`
     *       Staff/inactive → not_visitor_account / account_inactive
     *       otherwise      → google_account_mismatch (already linked; no
     *                        second account)
     *  2. accounts.email = verified email (case-insensitive)
     *       Staff/inactive → not_visitor_account / account_inactive (never
     *                        converted or reactivated)
     *       otherwise      → link_required — NEVER auto-linked on email alone
     *  3. in one transaction: create account + profile, google_id = `sub`,
     *       Sanctum token. Any failure rolls all of it back.
     *
     * A concurrent registration with the same `sub` or email that slips in
     * after the checks trips the unique index; the rolled-back attempt is
     * then re-classified by the same checks (409, never a 500). A collision
     * on anything else (the generated username) is simply retried.
     */
    public function register(VerifiedGoogleIdentity $identity, callable $createAccount): GoogleAuthDecision
    {
        if ($conflict = $this->registrationConflict($identity)) {
            return $conflict;
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($identity, $createAccount) {
                    $account = $createAccount($identity->email);

                    // forceFill: google_id/email_verified_at are deliberately
                    // not fillable. The email came from the verified Google
                    // token, so no second email verification is needed. Staff
                    // review is untouched: the profile stays `pending`.
                    $account->forceFill([
                        'google_id' => $identity->googleId,
                        'email_verified_at' => now(),
                    ])->save();

                    $token = $account->createToken(self::TOKEN_NAME)->plainTextToken;

                    return GoogleAuthDecision::authenticated($identity, $account, $token);
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($conflict = $this->registrationConflict($identity)) {
                    return $conflict;
                }

                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /** Why a verified identity may not register, or null if it may. */
    private function registrationConflict(VerifiedGoogleIdentity $identity): ?GoogleAuthDecision
    {
        $linked = Account::with('role')->where('google_id', $identity->googleId)->first();

        if ($linked) {
            return $this->rejection($linked, $identity)
                ?? GoogleAuthDecision::rejected(GoogleAuthDecision::GOOGLE_ACCOUNT_MISMATCH, $identity, $linked);
        }

        $byEmail = $this->findByEmail($identity);

        if ($byEmail) {
            return $this->rejection($byEmail, $identity)
                ?? GoogleAuthDecision::rejected(GoogleAuthDecision::LINK_REQUIRED, $identity, $byEmail);
        }

        return null;
    }

    private function authenticate(Account $account, VerifiedGoogleIdentity $identity): GoogleAuthDecision
    {
        // forceFill: last_login_at is not fillable (same as AuthController::login).
        $updates = ['last_login_at' => now()];

        // A linked visitor who never used their verification link has now
        // proven the address through Google — only if it is still the same one.
        if (! $account->hasVerifiedEmail()
            && mb_strtolower($account->email) === mb_strtolower($identity->email)) {
            $updates['email_verified_at'] = now();
        }

        $account->forceFill($updates)->save();
        $token = $account->createToken(self::TOKEN_NAME)->plainTextToken;

        return GoogleAuthDecision::authenticated($identity, $account, $token);
    }

    private function findByEmail(VerifiedGoogleIdentity $identity): ?Account
    {
        // MySQL's default collation already compares case-insensitively;
        // LOWER() makes that explicit (and true on SQLite in tests).
        return Account::with('role')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($identity->email)])
            ->first();
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
