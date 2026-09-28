<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    protected $primaryKey = 'audit_log_id';
    public $timestamps = false; // only has created_at, no updated_at

    protected $fillable = [
        'account_id',
        'action_type',
        'module_id',
        'record_type',
        'record_id',
        'description',
        'ip_address',
    ];

    const CREATED_AT = 'created_at';

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Write an entry. Centralized here so every controller logs consistently
     * instead of hand-rolling AuditLog::create() calls with slightly
     * different shapes each time.
     *
     * NOTE: module_id is required (not null) by the schema, but the real
     * `modules` table has no seeded rows yet (module_id, module_code,
     * module_name — confirmed from the migration, but no seeder exists to
     * populate it). MODULE_PDL_MANAGEMENT = 1 below is a placeholder —
     * replace once that table is seeded and you know the real id, or better,
     * look it up by module_code instead of hardcoding an id at all.
     *
     * account_id also can't be reliably set from auth()->id() yet — see the
     * doc comment on PdlController::currentStaffId() for why. This method
     * defensively falls back to null if no one's authenticated, but it does
     * NOT fix the deeper issue: once real auth exists, auth()->id() still
     * won't be an `accounts.account_id` unless that's specifically what the
     * new auth guard returns.
     */
    const MODULE_PDL_MANAGEMENT = 1; // TODO: confirm/seed against the real modules table

    public static function record(string $actionType, string $recordType, int $recordId, string $description, ?int $moduleId = null): self
    {
        return self::create([
            'account_id' => auth()->check() ? auth()->id() : null,
            'action_type' => $actionType,
            'module_id' => $moduleId ?? self::MODULE_PDL_MANAGEMENT,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'description' => $description,
            'ip_address' => request()->ip(),
        ]);
    }
}