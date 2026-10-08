<?php

namespace App\Console\Commands;

use App\Services\VisitAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Applies the time-based visit transitions (see VisitAssignmentService):
 *   pending_confirmation -> cancelled  (confirmation deadline passed)
 *   confirmed            -> no_show    (visit date passed, never checked in)
 *
 * Safe to run repeatedly: each visit is locked and re-checked before it
 * changes, so a second run (or a racing confirm/decline) changes nothing.
 * Not scheduled yet; it could later be added to routes/console.php with
 * Schedule::command('visits:expire')->hourly().
 */
class ExpireVisits extends Command
{
    protected $signature = 'visits:expire';

    protected $description = 'Cancel overdue unconfirmed visits and mark missed confirmed visits as no-show';

    public function handle(VisitAssignmentService $visits): int
    {
        $expired = $this->process(
            $visits->overduePendingConfirmationIds(),
            fn (int $id) => $visits->expirePendingConfirmation($id),
            'expire pending confirmation'
        );

        $noShow = $this->process(
            $visits->missedConfirmedVisitIds(),
            fn (int $id) => $visits->markNoShow($id),
            'mark no-show'
        );

        $this->info("Expired pending confirmations: {$expired['changed']}");
        $this->info("Marked no-show: {$noShow['changed']}");

        $failed = $expired['failed'] + $noShow['failed'];
        if ($failed > 0) {
            $this->error("Failed: {$failed} (see log)");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * One transaction per visit, so a failure on one row neither rolls back
     * nor blocks the others.
     *
     * @return array{changed:int,failed:int}
     */
    private function process(iterable $ids, callable $transition, string $label): array
    {
        $changed = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                if ($transition((int) $id)) {
                    $changed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error("visits:expire could not {$label} for visit request {$id}: {$e->getMessage()}");
            }
        }

        return ['changed' => $changed, 'failed' => $failed];
    }
}
