<?php

namespace App\Models;

use Illuminate\Support\Facades\Log;

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
        'created_at',
    ];

    const CREATED_AT = 'created_at';

    // timestamps=false means Laravel won't auto-cast created_at; without this,
    // Admin\AuditController's $log->created_at->format(...) fails on a string.
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Cached module_code -> module_id lookups for this request. Fixed from
     * the previous hardcoded MODULE_PDL_MANAGEMENT=1 placeholder, which
     * meant every single caller (PdlController, VisitorController,
     * EligibilityController, ...) mis-tagged its entries as "PDL
     * Management" regardless of which module actually wrote them. Now every
     * caller passes its own module code (see Module::CODE_* and each
     * controller's logAudit()/AuditLog::record() calls) and this resolves
     * it against the real seeded `modules` table.
     */
    private static array $moduleIdCache = [];

    private static function resolveModuleId(string $moduleCode): ?int
    {
        if (! array_key_exists($moduleCode, self::$moduleIdCache)) {
            self::$moduleIdCache[$moduleCode] = Module::where('module_code', $moduleCode)->value('module_id');
        }

        return self::$moduleIdCache[$moduleCode];
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function module()
    {
        return $this->belongsTo(Module::class, 'module_id');
    }

    /**
     * Write an entry. Centralized here so every controller logs consistently
     * instead of hand-rolling AuditLog::create() calls with slightly
     * different shapes each time.
     *
     * account_id comes from auth()->id() — now safe to rely on, since
     * Account (the real accounts table) is the Authenticatable behind both
     * the 'web' and 'sanctum' guards (see config/auth.php). It's null until
     * an actual login flow sets the session/token, which is fine — this
     * falls back to null rather than throwing.
     *
     * @param string $moduleCode One of Module::CODE_* — see App\Models\Module.
     */
    public static function record(
        string $actionType,
        string $recordType,
        int $recordId,
        string $description,
        string $moduleCode = Module::CODE_AUDIT_REPORTING
    ): self {
        $moduleId = self::resolveModuleId($moduleCode);

        if ($moduleId === null) {
            // DatabaseSeeder hasn't run yet, or the code doesn't exist.
            // Fall back to *some* valid module rather than inserting null
            // into a NOT NULL column and failing the actual action this
            // audit entry is just a side-effect of.
            Log::warning("AuditLog: module_code '{$moduleCode}' not found in modules table — has DatabaseSeeder run?");
            $moduleId = Module::query()->value('module_id');
        }

        return self::create([
            'account_id' => auth()->check() ? auth()->id() : null,
            'action_type' => $actionType,
            'module_id' => $moduleId,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'description' => $description,
            'ip_address' => request()->ip(),
        ]);
    }
}
