<?php

namespace App\Support;

/**
 * One place for cleaning user input BEFORE it is validated or stored, so the
 * same person is never saved twice as "juan  dela cruz", "JUAN DELA CRUZ" or
 * "Juan Dela Cruz " (data redundancy), and so stray markup / control
 * characters never reach the database.
 *
 * Output escaping stays with Blade ({{ }}); this is defence in depth.
 * Every method returns null for empty input and never throws.
 */
final class Sanitizer
{
    /** Letters (any language), accents, spaces, dots, apostrophes and hyphens. */
    public const NAME_REGEX = "/^\\p{L}[\\p{L}\\p{M} .'’-]*$/u";

    /** Philippine mobile number in the stored format: +639XXXXXXXXX */
    public const MOBILE_REGEX = '/^\\+639\\d{9}$/';

    public const SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

    /** Government ID number as stored: capitals, digits, single spaces, hyphens and slashes. */
    public const ID_NUMBER_REGEX = '/^[A-Z0-9](?:[A-Z0-9 \/-]*[A-Z0-9])?$/';

    /** Plain single-line text: no tags, no control/zero-width characters, single spaces. */
    public static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strip_tags($value);
        $value = preg_replace('/[\\x00-\\x1F\\x7F\\x{200B}-\\x{200D}\\x{FEFF}]/u', ' ', $value) ?? '';
        $value = preg_replace('/\\s+/u', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** Multi-line text (remarks, descriptions): keeps line breaks, drops tags/control characters. */
    public static function paragraph(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strip_tags($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F\\x{200B}-\\x{200D}\\x{FEFF}]/u', '', $value) ?? '';
        $value = preg_replace('/[ \\t]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\\n{3,}/', "\n\n", $value) ?? '';
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A person's name part. Fixes accidental ALL CAPS / all lower case
     * ("JUAN" -> "Juan") but leaves deliberate capitals alone ("McDonald").
     */
    public static function name(?string $value): ?string
    {
        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        // Word by word, so "JUAN dela cruz" is fixed but "McDonald" and "D." are left alone.
        $words = array_map(function (string $word) {
            $letters = preg_replace('/[^\p{L}]/u', '', $word) ?? '';
            if (mb_strlen($letters) < 2 || ($word !== mb_strtolower($word) && $word !== mb_strtoupper($word))) {
                return $word;
            }

            return preg_replace_callback(
                '/(^|[-\'’])(\p{L})/u',
                fn (array $m) => $m[1] . mb_strtoupper($m[2]),
                mb_strtolower($word)
            ) ?? $word;
        }, explode(' ', $value));

        return implode(' ', $words);
    }

    /** "jr", "JR." -> "Jr."; "iii" -> "III". Unknown values are returned trimmed so validation can reject them. */
    public static function suffix(?string $value): ?string
    {
        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        $key = mb_strtolower(rtrim($value, '.'));
        foreach (self::SUFFIXES as $suffix) {
            if (mb_strtolower(rtrim($suffix, '.')) === $key) {
                return $suffix;
            }
        }

        return $value;
    }

    public static function email(?string $value): ?string
    {
        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        return mb_strtolower(preg_replace('/\\s+/u', '', $value) ?? '');
    }

    /**
     * Philippine mobile number -> +639XXXXXXXXX. Accepts 0917 123 4567,
     * 09171234567, 639171234567, +63 917 123 4567 and 9171234567. Anything
     * else is returned as typed so the MOBILE_REGEX validation rejects it.
     */
    public static function mobile(?string $value): ?string
    {
        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\\D+/', '', $value) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            return '+63' . substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return '+63' . substr($digits, 1);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '+63' . $digits;
        }

        return $value;
    }

    public static function username(?string $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : mb_strtolower(str_replace(' ', '', $value));
    }

    /**
     * Government ID number: "a12 - 345  678" -> "A12-345 678". Upper-cases,
     * single spaces, no spaces around hyphens or slashes.
     */
    public static function idNumber(?string $value): ?string
    {
        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s*([\/-])\s*/', '$1', mb_strtoupper($value)) ?? '';

        return $value === '' ? null : $value;
    }

    /** A token pasted or scanned from a QR code: all whitespace removed. */
    public static function token(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', '', strip_tags($value)) ?? '';

        return $value === '' ? null : $value;
    }

    /** Escapes % _ \ so a search box value is matched literally inside LIKE. */
    public static function like(string $value): string
    {
        return '%' . addcslashes($value, '%_\\') . '%';
    }
}
