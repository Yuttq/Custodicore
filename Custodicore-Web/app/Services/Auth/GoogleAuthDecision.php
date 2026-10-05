<?php

namespace App\Services\Auth;

use App\Models\Account;

/**
 * Outcome of GoogleAuthService::decide() for one verified Google identity.
 * The controller turns it into the HTTP response.
 */
final class GoogleAuthDecision
{
    /** Linked active Visitor: a Sanctum token was issued. */
    public const AUTHENTICATED = 'authenticated';

    /** Unknown Google identity and email: the mobile app must register. */
    public const REGISTRATION_REQUIRED = 'registration_required';

    /** Email belongs to an existing account not linked to this Google identity. */
    public const LINK_REQUIRED = 'link_required';

    /** Matched account is Staff: Google sign-in is visitor-only. */
    public const NOT_VISITOR_ACCOUNT = 'not_visitor_account';

    /** Matched Visitor account is inactive/suspended. */
    public const ACCOUNT_INACTIVE = 'account_inactive';

    /** Linking: no account has the verified Google email. */
    public const ACCOUNT_NOT_FOUND = 'account_not_found';

    /** Linking: the Custodicore password did not match. */
    public const INVALID_PASSWORD = 'invalid_password';

    /**
     * Linking: the Google `sub` is already linked to another account, or the
     * account is already linked to another Google identity.
     */
    public const GOOGLE_ACCOUNT_MISMATCH = 'google_account_mismatch';

    private function __construct(
        public readonly string $outcome,
        public readonly VerifiedGoogleIdentity $identity,
        public readonly ?Account $account = null,
        public readonly ?string $token = null,
    ) {}

    public static function authenticated(VerifiedGoogleIdentity $identity, Account $account, string $token): self
    {
        return new self(self::AUTHENTICATED, $identity, $account, $token);
    }

    public static function rejected(string $outcome, VerifiedGoogleIdentity $identity, ?Account $account = null): self
    {
        return new self($outcome, $identity, $account);
    }
}
