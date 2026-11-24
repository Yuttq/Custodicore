<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\PdlRestriction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lifts every restriction whose end date has passed, with no officer
 * needed: active -> lifted, end date kept. The end date is the last day a
 * restriction covers, so one ending on the 24th blocks the 24th and is
 * lifted at midnight, when the 25th begins.
 *
 * Bookings and the gate already stop applying a restriction after its end
 * date; this also makes its saved status say so (the admin PDL counts read
 * it). Restrictions without an end date stay until an officer lifts them.
 *
 * Safe to run repeatedly: each row is locked and re-checked before it
 * changes. Scheduled daily at 00:00 Asia/Manila in routes/console.php.
 */
class LiftEndedRestrictions extends Command
{
    protected $signature = 'restrictions:lift-ended';

    protected $description = 'Lift PDL restrictions whose end date has passed';

    public function handle(): int
    {
        $ids = PdlRestriction::where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', today()->toDateString())
            ->orderBy('restriction_id')
            ->pluck('restriction_id');

        $lifted = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                if ($this->lift((int) $id)) {
                    $lifted++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error("restrictions:lift-ended could not lift restriction {$id}: {$e->getMessage()}");
            }
        }

        $this->info("Lifted ended restrictions: {$lifted}");

        if ($failed > 0) {
            $this->error("Failed: {$failed} (see log)");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function lift(int $restrictionId): bool
    {
        return DB::transaction(function () use ($restrictionId) {
            $restriction = PdlRestriction::whereKey($restrictionId)->lockForUpdate()->first();

            if (! $restriction || ! $restriction->hasEnded()) {
                return false;
            }

            $restriction->update(['status' => 'lifted']);

            $restriction->loadMissing('pdl');
            AuditLog::record('update', 'pdl_restrictions', $restriction->restriction_id,
                'System lifted '.str_replace('_', ' ', $restriction->restriction_type).' restriction for '
                    .($restriction->pdl?->full_name ?? 'PDL #'.$restriction->pdl_id)
                    .' automatically: it ended on '.$restriction->end_date->format('M j, Y'),
                Module::CODE_PDL_MANAGEMENT);

            return true;
        });
    }
}
