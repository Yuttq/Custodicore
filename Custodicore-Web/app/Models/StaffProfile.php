<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffProfile extends Model
{
    protected $table = 'staff_profiles';
    protected $primaryKey = 'staff_id';

    protected $fillable = [
        'account_id',
        'employee_number',
        'full_name',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'position',
        'contact_number',
        'personal_email',
        'photo_path',
        'assigned_facility',
    ];

    protected static function booted(): void
    {
        // full_name is what the sidebar, audit trail and every list read, so
        // keep it in sync whenever the separate name parts are set.
        static::saving(function (StaffProfile $staff) {
            if ($staff->first_name !== null || $staff->last_name !== null) {
                $staff->full_name = self::composeFullName(
                    $staff->first_name,
                    $staff->middle_name,
                    $staff->last_name,
                    $staff->suffix
                );
            }
        });
    }

    /** "Juan D. Dela Cruz Jr." */
    public static function composeFullName(?string $first, ?string $middle, ?string $last, ?string $suffix = null): string
    {
        return implode(' ', array_filter(
            array_map(fn ($part) => trim((string) $part), [$first, $middle, $last, $suffix]),
            fn ($part) => $part !== ''
        ));
    }

    /** "Dela Cruz, Juan D. Jr." — the form used in sorted lists. */
    public function listName(): string
    {
        if ($this->last_name === null && $this->first_name === null) {
            return (string) $this->full_name;
        }

        $given = trim(implode(' ', array_filter([$this->first_name, $this->middle_name])));
        $name = $this->last_name !== null && $this->last_name !== '' ? $this->last_name . ($given !== '' ? ', ' . $given : '') : $given;

        return $this->suffix ? $name . ' ' . $this->suffix : $name;
    }

    /** URL of the officer's profile picture, or null when none (show initials). */
    public function photoUrl(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        // ?v= changes with the file, so a replaced picture isn't served from cache.
        return route('account.photo.show', $this->staff_id) . '?v=' . substr(md5($this->photo_path), 0, 8);
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
