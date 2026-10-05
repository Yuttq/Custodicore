<?php

namespace App\Services\Auth;

/**
 * Identity proven by a Google ID token that passed GoogleIdTokenVerifier.
 * Every field comes from verified token claims, never from request input.
 */
final class VerifiedGoogleIdentity
{
    public function __construct(
        public readonly string $googleId,   // `sub` — stable; store in accounts.google_id
        public readonly string $email,      // `email` — email_verified was true
        public readonly ?string $name,
        public readonly ?string $pictureUrl,
    ) {}
}
