<?php

namespace App\Support;

/**
 * Time-based one-time passwords (RFC 6238) for authenticator apps such as
 * Google Authenticator, Microsoft Authenticator and Authy: HMAC-SHA1, 30-second
 * steps, 6 digits. Dependency-free on purpose, like App\Support\SimplePdf, so
 * nothing extra has to be installed with composer.
 *
 * The shared secret is a random 20-byte value written in base32 (the form
 * authenticator apps accept when a code is typed in instead of scanned).
 */
class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random secret, base32 (32 characters). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** The step number (30-second window) a moment in time falls in. */
    public static function stepAt(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /** The code an authenticator app shows for this secret during $step. */
    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Checks what the officer typed. Accepts the current step and $window steps
     * either side (clock drift between the phone and the server).
     *
     * @param  int|null  $afterStep  steps up to and including this one are refused, so a code
     *                               that was already used (or an older one) cannot be replayed
     * @return int|null  the step that matched, or null when the code is wrong or already used
     */
    public static function verify(string $secret, string $input, int $window = 1, ?int $afterStep = null, ?int $time = null): ?int
    {
        $input = preg_replace('/\s+/', '', $input) ?? '';

        if (! preg_match('/^\d{' . self::DIGITS . '}$/', $input)) {
            return null;
        }

        $current = self::stepAt($time);
        $matched = null;

        // Every step is compared (no early exit) so timing does not reveal which one was close.
        for ($step = $current - $window; $step <= $current + $window; $step++) {
            if (hash_equals(self::code($secret, $step), $input) && ($afterStep === null || $step > $afterStep)) {
                $matched = $matched === null ? $step : max($matched, $step);
            }
        }

        return $matched;
    }

    /** The otpauth:// address that the QR code encodes. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** "ABCD EFGH IJKL ..." — easier to read and type. */
    public static function groupSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]+/', '', $text) ?? '');

        $bits = '';
        foreach (str_split($text) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                continue;
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
