<?php

namespace Tests\Unit;

use App\Services\Auth\GoogleSignInUnavailableException;
use App\Services\Auth\InvalidGoogleIdTokenException;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\GoogleIdTokenFixtures;

/**
 * GoogleIdTokenVerifier against real RS256 signatures and a fake, in-process
 * JWKS endpoint. Pure PHPUnit — no Laravel app, database or network.
 */
class GoogleIdTokenVerifierTest extends TestCase
{
    use GoogleIdTokenFixtures;

    public function test_valid_token_returns_identity_from_verified_claims(): void
    {
        $identity = $this->makeGoogleVerifier()->verify($this->googleIdToken());

        $this->assertSame('109876543210987654321', $identity->googleId);
        $this->assertSame('google.visitor@gmail.com', $identity->email);
        $this->assertSame('Google Visitor', $identity->name);
        $this->assertSame('https://lh3.googleusercontent.com/a/test', $identity->pictureUrl);
        $this->assertSame(['https://www.googleapis.com/oauth2/v3/certs'], $this->jwksRequests);
    }

    public function test_accepts_issuer_without_scheme(): void
    {
        $identity = $this->makeGoogleVerifier()->verify($this->googleIdToken(['iss' => 'accounts.google.com']));

        $this->assertSame('109876543210987654321', $identity->googleId);
    }

    public function test_accepts_any_configured_client_id_as_audience(): void
    {
        $verifier = $this->makeGoogleVerifier(['android-client.apps.googleusercontent.com', self::GOOGLE_TEST_CLIENT_ID]);

        $this->assertSame('google.visitor@gmail.com', $verifier->verify($this->googleIdToken())->email);
    }

    public function test_accepts_legacy_string_email_verified(): void
    {
        $identity = $this->makeGoogleVerifier()->verify($this->googleIdToken(['email_verified' => 'true']));

        $this->assertSame('google.visitor@gmail.com', $identity->email);
    }

    public function test_optional_profile_claims_may_be_absent(): void
    {
        $identity = $this->makeGoogleVerifier()->verify($this->googleIdToken([], ['name', 'picture']));

        $this->assertNull($identity->name);
        $this->assertNull($identity->pictureUrl);
    }

    public static function invalidClaimsProvider(): array
    {
        return [
            'wrong audience' => [['aud' => 'someone-else.apps.googleusercontent.com'], []],
            'array audience' => [['aud' => [self::GOOGLE_TEST_CLIENT_ID]], []],
            'wrong issuer' => [['iss' => 'https://evil.example.com'], []],
            'missing issuer' => [[], ['iss']],
            'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200], []],
            'missing exp' => [[], ['exp']],
            'missing iat' => [[], ['iat']],
            'issued in the future' => [['iat' => time() + 3600, 'exp' => time() + 7200], []],
            'missing sub' => [[], ['sub']],
            'empty sub' => [['sub' => ''], []],
            'missing email' => [[], ['email']],
            'invalid email' => [['email' => 'not-an-email'], []],
            'email not verified' => [['email_verified' => false], []],
            'email_verified "false"' => [['email_verified' => 'false'], []],
            'email_verified missing' => [[], ['email_verified']],
        ];
    }

    #[DataProvider('invalidClaimsProvider')]
    public function test_rejects_token_with_invalid_or_missing_claims(array $overrides, array $remove): void
    {
        $this->expectException(InvalidGoogleIdTokenException::class);

        $this->makeGoogleVerifier()->verify($this->googleIdToken($overrides, $remove));
    }

    public function test_rejects_token_signed_by_a_different_key(): void
    {
        // Same kid as Google's published key, but signed with an attacker key.
        $forged = $this->googleIdToken([], [], 'attacker');

        $this->expectException(InvalidGoogleIdTokenException::class);
        $this->makeGoogleVerifier()->verify($forged);
    }

    public function test_rejects_tampered_payload(): void
    {
        [$header, , $signature] = explode('.', $this->googleIdToken());
        $payload = self::base64Url(json_encode($this->googleClaims(['email' => 'victim@gmail.com'])));

        $this->expectException(InvalidGoogleIdTokenException::class);
        $this->makeGoogleVerifier()->verify("$header.$payload.$signature");
    }

    public function test_rejects_unknown_key_id(): void
    {
        $this->expectException(InvalidGoogleIdTokenException::class);

        $this->makeGoogleVerifier()->verify($this->googleIdToken([], [], 'google', 'unknown-kid'));
    }

    public function test_rejects_hs256_algorithm_confusion(): void
    {
        // Classic attack: HMAC-sign with the RSA public key as the secret.
        $token = JWT::encode($this->googleClaims(), self::googleTestKey()['public'], 'HS256', self::GOOGLE_TEST_KID);

        $this->expectException(InvalidGoogleIdTokenException::class);
        $this->makeGoogleVerifier()->verify($token);
    }

    public function test_rejects_unsigned_alg_none_token(): void
    {
        $header = self::base64Url(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => self::GOOGLE_TEST_KID]));
        $payload = self::base64Url(json_encode($this->googleClaims()));

        $this->expectException(InvalidGoogleIdTokenException::class);
        $this->makeGoogleVerifier()->verify("$header.$payload.");
    }

    public static function malformedTokenProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'no dots' => ['not-a-jwt'],
            'two parts' => ['a.b'],
            'four parts' => ['a.b.c.d'],
            'garbage parts' => ['a.b.c'],
            'oversized' => [str_repeat('a', 3000).'.'.str_repeat('b', 1100).'.c'],
        ];
    }

    #[DataProvider('malformedTokenProvider')]
    public function test_rejects_malformed_tokens(string $token): void
    {
        $this->expectException(InvalidGoogleIdTokenException::class);

        $this->makeGoogleVerifier()->verify($token);
    }

    public function test_unconfigured_client_ids_is_unavailable_and_makes_no_request(): void
    {
        try {
            $this->makeGoogleVerifier([])->verify($this->googleIdToken());
            $this->fail('Expected GoogleSignInUnavailableException.');
        } catch (GoogleSignInUnavailableException) {
            $this->assertSame([], $this->jwksRequests);
        }
    }

    public function test_jwks_network_failure_is_unavailable_not_invalid(): void
    {
        $this->expectException(GoogleSignInUnavailableException::class);

        $this->makeGoogleVerifier(jwks: 'network_error')->verify($this->googleIdToken());
    }

    public function test_jwks_server_error_is_unavailable_not_invalid(): void
    {
        $this->expectException(GoogleSignInUnavailableException::class);

        $this->makeGoogleVerifier(jwks: 'server_error')->verify($this->googleIdToken());
    }

    public function test_exception_messages_never_contain_the_token(): void
    {
        $cases = [
            [$this->makeGoogleVerifier(), $this->googleIdToken(['aud' => 'other'])],
            [$this->makeGoogleVerifier(), $this->googleIdToken([], [], 'attacker')],
            [$this->makeGoogleVerifier(jwks: 'network_error'), $this->googleIdToken()],
            [$this->makeGoogleVerifier(jwks: 'server_error'), $this->googleIdToken()],
        ];

        foreach ($cases as [$verifier, $token]) {
            try {
                $verifier->verify($token);
                $this->fail('Expected verification to fail.');
            } catch (InvalidGoogleIdTokenException|GoogleSignInUnavailableException $e) {
                $this->assertStringNotContainsString($token, $e->getMessage());
                [, $payloadSegment] = explode('.', $token);
                $this->assertStringNotContainsString($payloadSegment, $e->getMessage());
            }
        }
    }
}
