<?php

namespace App\Services\Auth;

use Google\AccessToken\Verify;
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Verifies a Google ID token (JWT) sent by the mobile app and returns the
 * identity it proves. This is the ONLY source of Google identity data the
 * backend may trust — never use an email/name the client sends alongside it.
 *
 * Checks, in order:
 *  - Google Sign-In is configured (services.google.client_ids non-empty)
 *  - the token is a well-formed three-part JWT of sane length
 *  - RS256 signature against Google's published keys (JWKS), via
 *    google/apiclient's Verify (firebase/php-jwt), which also enforces
 *    exp / nbf / iat when present
 *  - iss is accounts.google.com or https://accounts.google.com
 *  - aud is one of our configured client IDs
 *  - required claims are present: sub, email, email_verified, exp, iat
 *  - email_verified is true
 *
 * The raw token is never logged or included in exception messages.
 */
class GoogleIdTokenVerifier
{
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    // Real Google ID tokens are ~1–1.5 KB; anything far larger is not one.
    private const MAX_TOKEN_LENGTH = 4096;

    /**
     * @param  string[]  $clientIds  Accepted `aud` values (config services.google.client_ids).
     */
    public function __construct(
        private readonly array $clientIds,
        private readonly Verify $verify,
    ) {}

    public function isConfigured(): bool
    {
        return $this->clientIds !== [];
    }

    /**
     * @throws GoogleSignInUnavailableException  not configured, or Google's keys unreachable
     * @throws InvalidGoogleIdTokenException      the token failed any check
     */
    public function verify(string $idToken): VerifiedGoogleIdentity
    {
        if (! $this->isConfigured()) {
            throw new GoogleSignInUnavailableException('Google Sign-In is not configured.');
        }

        $idToken = trim($idToken);
        if ($idToken === ''
            || strlen($idToken) > self::MAX_TOKEN_LENGTH
            || substr_count($idToken, '.') !== 2) {
            throw new InvalidGoogleIdTokenException('malformed');
        }

        try {
            // Audience is checked below against the full allow-list, since
            // Verify only accepts a single audience.
            $payload = $this->verify->verifyIdToken($idToken);
        } catch (ClientExceptionInterface $e) {
            throw new GoogleSignInUnavailableException('Could not reach Google to verify the token.', previous: $e);
        } catch (UnexpectedValueException $e) {
            // firebase/php-jwt's CachedKeySet reports a non-200 JWKS fetch this way.
            if (str_starts_with($e->getMessage(), 'HTTP Error')) {
                throw new GoogleSignInUnavailableException('Could not fetch Google signing keys.', previous: $e);
            }
            throw new InvalidGoogleIdTokenException('rejected');
        } catch (Throwable) {
            // Malformed segments, unknown kid, wrong alg, not-yet-valid, etc.
            throw new InvalidGoogleIdTokenException('rejected');
        }

        if (! is_array($payload)) {
            // Verify returns false for bad signature, expiry, wrong issuer.
            throw new InvalidGoogleIdTokenException('rejected');
        }

        return $this->identityFromClaims($payload);
    }

    private function identityFromClaims(array $claims): VerifiedGoogleIdentity
    {
        // Issuer (Verify checks this too; kept explicit so this class is
        // the single readable source of the rules).
        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new InvalidGoogleIdTokenException('issuer');
        }

        // Google issues a single string audience.
        $aud = $claims['aud'] ?? null;
        if (! is_string($aud) || ! in_array($aud, $this->clientIds, true)) {
            throw new InvalidGoogleIdTokenException('audience');
        }

        // php-jwt only enforces exp/iat when present — require them.
        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] <= time()) {
            throw new InvalidGoogleIdTokenException('expiry');
        }
        if (! is_numeric($claims['iat'] ?? null)) {
            throw new InvalidGoogleIdTokenException('issued_at');
        }

        $sub = $claims['sub'] ?? null;
        if (! is_string($sub) || $sub === '' || strlen($sub) > 255) {
            throw new InvalidGoogleIdTokenException('subject');
        }

        $email = $claims['email'] ?? null;
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidGoogleIdTokenException('email');
        }

        // Google sends a boolean; older tokens used the string "true".
        $emailVerified = $claims['email_verified'] ?? null;
        if ($emailVerified !== true && $emailVerified !== 'true') {
            throw new InvalidGoogleIdTokenException('email_unverified');
        }

        return new VerifiedGoogleIdentity(
            googleId: $sub,
            email: $email,
            name: is_string($claims['name'] ?? null) ? $claims['name'] : null,
            pictureUrl: is_string($claims['picture'] ?? null) ? $claims['picture'] : null,
        );
    }
}
