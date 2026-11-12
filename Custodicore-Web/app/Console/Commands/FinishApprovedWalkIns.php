<?php

namespace App\Console\Commands;

use App\Services\WalkInService;
use Illuminate\Console\Command;

/**
 * php artisan cc:finish-approved-walk-ins [--dry-run]
 *
 * One-time catch-up: walk-ins approved in the Review Queue before one Approve
 * also saved the walk-in decision are still "Pending". For each of them this
 * runs the rest of that Approve (WalkInService::approveInOneStep): files, PDL
 * link, relationship and the walk-in decision, booking the visit the Front
 * Desk chose. Anything still blocking (e.g. the chosen session has ended) is
 * reported and left Pending. Attributed to the officer who approved the account.
 */
class FinishApprovedWalkIns extends Command
{
    protected $signature = 'cc:finish-approved-walk-ins {--dry-run : Only list them, change nothing}';

    protected $description = 'Finish walk-ins whose account was approved but whose walk-in decision is still Pending';

    public function handle(WalkInService $walkIns): int
    {
        $waiting = $walkIns->awaitingDecision();
        if ($waiting->isEmpty()) {
            $this->info('No approved walk-ins are waiting for their walk-in decision.');

            return self::SUCCESS;
        }

        foreach ($waiting as $visitor) {
            if ($this->option('dry-run')) {
                $this->line("Would finish: #{$visitor->visitor_id} {$visitor->full_name}");
                continue;
            }

            $staffId = (int) $visitor->verified_by;
            if ($staffId === 0) {
                $this->warn("Skipped #{$visitor->visitor_id} {$visitor->full_name}: no approving officer on record.");
                continue;
            }

            $result = $walkIns->approveInOneStep($visitor, $staffId);
            $line = "#{$visitor->visitor_id} {$visitor->full_name}: " . implode(' ', $result['notes']);
            $result['approved'] ? $this->info($line) : $this->warn($line);
        }

        return self::SUCCESS;
    }
}
