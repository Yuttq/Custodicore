<?php

namespace App\Console\Commands;

use App\Services\VisitAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Visitor reminders (routes/console.php):
 *   visits:remind confirmation  hourly   "please confirm" 24 hours before the deadline
 *   visits:remind today         07:00    "your visit is today"
 *
 * Each reminder is sent at most once per visit (VisitAssignmentService), so
 * running the command again, or late, sends nothing twice.
 */
class SendVisitReminders extends Command
{
    protected $signature = 'visits:remind {type : confirmation or today}';

    protected $description = 'Send visitor reminders: confirmation (24 hours before the deadline) or today (visit day)';

    public function handle(VisitAssignmentService $visits): int
    {
        $type = (string) $this->argument('type');

        [$ids, $send, $label] = match ($type) {
            'confirmation' => [
                $visits->confirmationReminderDueIds(),
                fn (int $id) => $visits->sendConfirmationReminder($id),
                'Confirmation reminders sent',
            ],
            'today' => [
                $visits->visitDayReminderIds(),
                fn (int $id) => $visits->sendVisitDayReminder($id),
                'Visit-day reminders sent',
            ],
            default => [null, null, null],
        };

        if ($ids === null) {
            $this->error('Unknown reminder type. Use "confirmation" or "today".');

            return self::INVALID;
        }

        $sent = 0;
        $failed = 0;
        // One transaction per visit, so a failure on one neither rolls back nor blocks the others.
        foreach ($ids as $id) {
            try {
                if ($send((int) $id)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error("visits:remind {$type} could not remind visit request {$id}: {$e->getMessage()}");
            }
        }

        $this->info("{$label}: {$sent}");
        if ($failed > 0) {
            $this->error("Failed: {$failed} (see log)");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
