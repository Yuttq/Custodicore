<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $table = 'system_settings';
    protected $primaryKey = 'setting_id';
    public $timestamps = false; // has updated_at only, no created_at

    const UPDATED_AT = 'updated_at';

    protected $fillable = ['setting_key', 'setting_value', 'updated_by'];

    /** Human-readable blurb per key — the schema itself has no description column. */
    const DESCRIPTIONS = [
        'visit.max_per_week' => 'Maximum visits a single visitor may attend per week',
        'visit.confirmation_window_hours' => 'Hours a visitor has to confirm an assigned schedule',
        'schedule.slot_capacity_default' => 'Default visitor capacity per time slot',
    ];

    /**
     * Kept in the table but no longer used, so not listed on settings pages.
     * qr.expiry_minutes: a QR pass is valid until the visit's last session
     * ends (QrCode::validUntil).
     */
    const HIDDEN = ['qr.expiry_minutes'];

    public static function value(string $key, ?string $default = null): ?string
    {
        return self::where('setting_key', $key)->value('setting_value') ?? $default;
    }

    public function description(): string
    {
        return self::DESCRIPTIONS[$this->setting_key] ?? '';
    }
}
