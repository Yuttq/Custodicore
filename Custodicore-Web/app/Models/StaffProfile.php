<?php

namespace App\Models;

use App\Models\Concerns\HasPersonName;
use Illuminate\Database\Eloquent\Model;

class StaffProfile extends Model
{
    use HasPersonName;

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

    // composeFullName() / listName() / orderByName / whereNameMatches: HasPersonName.

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
