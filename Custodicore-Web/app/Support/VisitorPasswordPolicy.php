<?php

namespace App\Support;

/**
 * The one password policy for every NEW visitor password: API registration,
 * Google registration and the emailed password reset. Never applied when an
 * existing password is checked (login, Google linking) — accounts created
 * under older, weaker rules must keep signing in.
 *
 *  - 12 to 64 characters
 *  - at least one uppercase letter (A-Z), one lowercase letter (a-z), one
 *    number (0-9) and one special character
 *  - no spaces (or any other whitespace) anywhere
 *  - only printable ASCII: English letters, digits and the keyboard symbols
 *    in SPECIAL_CHARACTERS
 *  - confirmed (password_confirmation must match exactly)
 *
 * Why ASCII only: bcrypt reads at most 72 BYTES. A 64-character password of
 * ASCII is at most 64 bytes, so every accepted password is hashed in full.
 * Allowing multibyte characters (ñ, é, emoji) would let a 64-character
 * password exceed 72 bytes and be silently cut by bcrypt. Such characters
 * are rejected with a clear message instead. Laravel's Password rule is not
 * used for the character classes: its symbols() counts a space as a symbol
 * and its mixedCase()/numbers() accept any Unicode letter or digit.
 *
 * The mobile app mirrors these rules in src/utils/registrationValidation.js
 * (validateRegistrationPassword) — keep both in step.
 */
class VisitorPasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 64;

    /** Printable ASCII that is neither a letter nor a digit (space excluded). */
    public const SPECIAL_CHARACTERS = '!"#$%&\'()*+,-./:;<=>?@[\]^_`{|}~';

    /** One line for forms and hints. */
    public const REQUIREMENTS = '12–64 characters, with at least one uppercase letter, one lowercase letter, one number and one special character (such as ! @ # $ %). No spaces.';

    /**
     * Rules for the `password` field. Pass the account's email to also refuse
     * a password equal to it (the reset form; registration never did).
     */
    public static function rules(?string $email = null): array
    {
        return [
            'required', 'string', 'confirmed',
            function (string $attribute, mixed $value, \Closure $fail) use ($email) {
                if (! is_string($value)) {
                    return; // 'string' already reports it
                }

                foreach (self::problems($value) as $message) {
                    $fail($message);
                }

                if ($email !== null && mb_strtolower($value) === mb_strtolower(trim($email))) {
                    $fail('Your password cannot be the same as your email address.');
                }
            },
        ];
    }

    /** Every unmet requirement, as user-facing messages (empty = acceptable). */
    public static function problems(string $password): array
    {
        $notAscii = 'Use only English letters, numbers and standard keyboard symbols in your password.';
        if (! mb_check_encoding($password, 'UTF-8')) {
            return [$notAscii];
        }

        $problems = [];
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            $problems[] = 'Your password must be at least '.self::MIN_LENGTH.' characters.';
        }
        if ($length > self::MAX_LENGTH) {
            $problems[] = 'Your password must be '.self::MAX_LENGTH.' characters or fewer.';
        }
        if (preg_match('/\s|\p{Z}/u', $password)) {
            $problems[] = 'Your password cannot contain spaces.';
        }
        // Whitespace is already reported above; anything else outside ! .. ~ is not ASCII.
        if (preg_match('/[^\x21-\x7E\s\p{Z}]/u', $password)) {
            $problems[] = $notAscii;
        }
        if (! preg_match('/[A-Z]/', $password)) {
            $problems[] = 'Your password must contain at least one uppercase letter (A-Z).';
        }
        if (! preg_match('/[a-z]/', $password)) {
            $problems[] = 'Your password must contain at least one lowercase letter (a-z).';
        }
        if (! preg_match('/[0-9]/', $password)) {
            $problems[] = 'Your password must contain at least one number (0-9).';
        }
        if (! preg_match('/[!-\/:-@\[-`{-~]/', $password)) {
            $problems[] = 'Your password must contain at least one special character (such as ! @ # $ %).';
        }

        return $problems;
    }
}
