<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// CONFIRMED against the actual migration (2024_01_02_000080_create_pdl_profiles_table.php):
// registered_by -> staff_profiles.staff_id (not accounts, as earlier guessed).
//
// IMPORTANT — not yet safe to use: auth() currently authenticates against
// Laravel's default `users` table (see App\Models\User's own doc comment),
// which has no relationship to staff_profiles at all. Any code that does
// 'registered_by' => auth()->id() will insert a users.id into a column
// with a foreign key pointing at staff_profiles — that will fail its FK
// constraint (or silently insert garbage if the constraint isn't enforced).
// This needs real auth wired to accounts/staff_profiles before these writes
// are safe to run — see PdlController for where this is flagged inline.

class Pdl extends Model
{
    protected $table = 'pdl_profiles';
    protected $primaryKey = 'pdl_id';

    protected $fillable = [
        'pdl_number',
        'full_name',
        'alias',
        'date_of_birth',
        'gender',
        'classification',
        'cell_block',
        'admission_date',
        'custody_status',
        'photo_path',
        'registered_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'admission_date' => 'date',
    ];

    // --- Relationships ------------------------------------------------

    public function legalRecords()
    {
        return $this->hasMany(PdlLegalRecord::class, 'pdl_id', 'pdl_id')
            ->orderBy('created_at', 'desc');
    }

    public function disciplinaryRecords()
    {
        return $this->hasMany(PdlDisciplinaryRecord::class, 'pdl_id', 'pdl_id')
            ->orderBy('incident_date', 'desc');
    }

    public function restrictions()
    {
        return $this->hasMany(PdlRestriction::class, 'pdl_id', 'pdl_id')
            ->orderBy('created_at', 'desc');
    }

    public function activeRestrictions()
    {
        return $this->restrictions()->where('status', 'active');
    }

    public function visitorRelationships()
    {
        return $this->hasMany(VisitorPdlRelationship::class, 'pdl_id', 'pdl_id');
    }

    public function visitRequests()
    {
        return $this->hasMany(VisitRequest::class, 'pdl_id', 'pdl_id');
    }

    public function registeredBy()
    {
        // Corrected: registered_by references staff_profiles.staff_id per
        // the actual migration, not accounts.account_id.
        return $this->belongsTo(StaffProfile::class, 'registered_by', 'staff_id');
    }

    // --- Convenience accessors -----------------------------------------

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->full_name));
        $first = $parts[0][0] ?? '';
        $last = count($parts) > 1 ? ($parts[count($parts) - 1][0] ?? '') : '';
        return strtoupper($first . $last);
    }
}
