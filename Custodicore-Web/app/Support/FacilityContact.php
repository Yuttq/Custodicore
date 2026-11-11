<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * The facility contact shown in the visitor app's Help Center: a name
 * (e.g. "BJMP Facility — Visiting Area"), a phone number and office hours.
 * Edited by the Admin/Warden on Settings (Admin\SettingsController), stored
 * as three system_settings rows, read by GET /api/facility-contact.
 *
 * Until a phone number is saved the app shows no contact at all — never a
 * made-up number. PHONE_REGEX keeps the number to digits and + ( ) - and
 * spaces so it is safe to put in a tel: link.
 */
class FacilityContact
{
    public const KEY_NAME = 'facility_contact.name';
    public const KEY_PHONE = 'facility_contact.phone';
    public const KEY_HOURS = 'facility_contact.hours';

    public const KEYS = [self::KEY_NAME, self::KEY_PHONE, self::KEY_HOURS];

    public const NAME_MAX = 100;
    public const HOURS_MAX = 100;

    /** 7–20 characters of digits, spaces, + ( ) and -, starting with + ( or a digit. */
    public const PHONE_REGEX = '/^[+(0-9][0-9 ()+\-]{6,19}$/';
    public const PHONE_MIN_DIGITS = 7;

    /** @return array{name: string, phone: string, hours: string} empty strings when unset */
    public static function current(): array
    {
        $values = SystemSetting::whereIn('setting_key', self::KEYS)->pluck('setting_value', 'setting_key');

        return [
            'name' => (string) ($values[self::KEY_NAME] ?? ''),
            'phone' => (string) ($values[self::KEY_PHONE] ?? ''),
            'hours' => (string) ($values[self::KEY_HOURS] ?? ''),
        ];
    }

    /** What the app shows, or null while no phone number is set. */
    public static function forApp(): ?array
    {
        $contact = self::current();

        if ($contact['phone'] === '') {
            return null;
        }

        return [
            'name' => $contact['name'] !== '' ? $contact['name'] : null,
            'phone' => $contact['phone'],
            'hours' => $contact['hours'] !== '' ? $contact['hours'] : null,
        ];
    }

    /**
     * Saves already-validated values (null/empty clears a field).
     * @param array{name: ?string, phone: ?string, hours: ?string} $contact
     */
    public static function save(array $contact, int $staffId): void
    {
        $map = [
            self::KEY_NAME => $contact['name'] ?? '',
            self::KEY_PHONE => $contact['phone'] ?? '',
            self::KEY_HOURS => $contact['hours'] ?? '',
        ];

        foreach ($map as $key => $value) {
            SystemSetting::updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => (string) $value, 'updated_by' => $staffId, 'updated_at' => now()]
            );
        }
    }

    /** Collapses spaces in a phone number; null when empty. */
    public static function normalizePhone(?string $value): ?string
    {
        $value = Sanitizer::text($value);

        return $value === null ? null : preg_replace('/\s+/', ' ', $value);
    }
}
