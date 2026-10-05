<?php

namespace Tests\Support;

use App\Services\Auth\GoogleIdTokenVerifier;
use Firebase\JWT\JWT;
use Google\AccessToken\Verify;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Deterministic Google ID token fixtures — no network, no committed keys.
 *
 * RSA key pairs are generated at runtime (once per PHP process) and the
 * JWKS endpoint Google's Verify fetches is answered by an in-process Guzzle
 * handler, so tokens go through the REAL signature/claim verification path.
 */
trait GoogleIdTokenFixtures
{
    protected const GOOGLE_TEST_CLIENT_ID = 'test-web-client.apps.googleusercontent.com';
    protected const GOOGLE_TEST_KID = 'test-kid-1';

    /** @var array<string, array{private: string, n: string, e: string}> */
    private static array $googleTestKeys = [];

    /** Requests the fake JWKS endpoint received (to assert no-network paths). */
    protected array $jwksRequests = [];

    /**
     * Generates (and caches) an RSA key pair. Windows PHP builds often have
     * no default openssl.cnf, so a minimal temp config is supplied.
     */
    protected static function googleTestKey(string $name = 'google'): array
    {
        if (isset(self::$googleTestKeys[$name])) {
            return self::$googleTestKeys[$name];
        }

        $cnf = tempnam(sys_get_temp_dir(), 'ossl');
        file_put_contents($cnf, "[req]\ndistinguished_name = dn\n[dn]\n");

        try {
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf];
            $key = openssl_pkey_new($options);
            if ($key === false || ! openssl_pkey_export($key, $privatePem, null, ['config' => $cnf])) {
                throw new RuntimeException('Could not generate a test RSA key: '.openssl_error_string());
            }
            $details = openssl_pkey_get_details($key);
        } finally {
            @unlink($cnf);
        }

        return self::$googleTestKeys[$name] = [
            'private' => $privatePem,
            'public' => $details['key'],
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ];
    }

    protected static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Claims of a valid Google ID token; override/remove per test. */
    protected function googleClaims(array $overrides = [], array $remove = []): array
    {
        $now = time();
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'azp' => self::GOOGLE_TEST_CLIENT_ID,
            'aud' => self::GOOGLE_TEST_CLIENT_ID,
            'sub' => '109876543210987654321',
            'email' => 'google.visitor@gmail.com',
            'email_verified' => true,
            'name' => 'Google Visitor',
            'picture' => 'https://lh3.googleusercontent.com/a/test',
            'iat' => $now - 60,
            'exp' => $now + 3600,
        ], $overrides);

        foreach ($remove as $claim) {
            unset($claims[$claim]);
        }

        return $claims;
    }

    /** A token signed RS256 with the key Google's (fake) JWKS publishes. */
    protected function googleIdToken(array $overrides = [], array $remove = [], string $keyName = 'google', string $kid = self::GOOGLE_TEST_KID): string
    {
        return JWT::encode($this->googleClaims($overrides, $remove), self::googleTestKey($keyName)['private'], 'RS256', $kid);
    }

    /**
     * A verifier whose JWKS fetch is answered in-process.
     *
     * @param  'ok'|'network_error'|'server_error'  $jwks
     */
    protected function makeGoogleVerifier(array $clientIds = [self::GOOGLE_TEST_CLIENT_ID], string $jwks = 'ok'): GoogleIdTokenVerifier
    {
        $handler = function (RequestInterface $request) use ($jwks) {
            $this->jwksRequests[] = (string) $request->getUri();

            if ($jwks === 'network_error') {
                return Create::rejectionFor(new ConnectException('Connection refused', $request));
            }
            if ($jwks === 'server_error') {
                return Create::promiseFor(new Response(500, [], 'oops'));
            }

            $key = self::googleTestKey();

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'keys' => [[
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => self::GOOGLE_TEST_KID,
                    'n' => $key['n'],
                    'e' => $key['e'],
                ]],
            ])));
        };

        return new GoogleIdTokenVerifier($clientIds, new Verify(new GuzzleClient(['handler' => $handler])));
    }
}
