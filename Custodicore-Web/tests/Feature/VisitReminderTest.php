<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * `php artisan visits:remind confirmation|today`:
 *   confirmation  hourly  once, 24 hours before a staff-assigned visit's deadline
 *   today         07:00   once, on the day of a confirmed visit
 *
 * "Now" starts at Wednesday 2026-10-07 10:00 Asia/Manila. A staff assignment
 * made then gets the default 48h deadline, Fri 2026-10-09 10:00; the fixture
 * schedule is Fri 2026-10-09 09:00–11:30.
 */
class VisitReminderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const CONFIRM = 'visit_reminder_confirm';
    private const TODAY = 'visit_reminder_today';

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-10-07 10:00:00');
        $this->seedPhase1Fixtures();
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, 'Asia/Manila'));
    }

    private function service(): VisitAssignmentService
    {
        return app(VisitAssignmentService::class);
    }

    private function staffAssignedVisit(array $extra = []): VisitRequest
    {
        return $this->service()->assign(array_merge([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ], $extra));
    }

    private function confirmedVisit(): VisitRequest
    {
        return $this->service()->confirmAssignedVisit($this->staffAssignedVisit());
    }

    private function remind(string $type, int $expected): void
    {
        $label = $type === 'confirmation' ? 'Confirmation reminders sent' : 'Visit-day reminders sent';
        $this->artisan('visits:remind', ['type' => $type])
            ->expectsOutput("{$label}: {$expected}")
            ->assertSuccessful();
    }

    private function reminders(string $type): \Illuminate\Support\Collection
    {
        return Notification::where('notification_type', $type)->get();
    }

    private function makeNoAppWalkIn(): void
    {
        $this->visitor->update(['registered_via' => 'walk_in']);
        $this->visitorAccount->forceFill(['last_login_at' => null])->save();
    }

    // -------------------------------------------- Confirmation reminder

    public function test_confirmation_reminder_is_sent_once_24_hours_before_the_deadline(): void
    {
        $visit = $this->staffAssignedVisit();

        // More than 24 hours before the Friday 10:00 deadline: not yet.
        $this->at('2026-10-08 09:59:00');
        $this->remind('confirmation', 0);

        $this->at('2026-10-08 10:00:00');
        $this->remind('confirmation', 1);

        $reminder = $this->reminders(self::CONFIRM)->sole();
        $this->assertSame($this->visitorAccount->account_id, (int) $reminder->account_id);
        $this->assertSame($visit->visit_request_id, (int) $reminder->related_record_id);
        $this->assertSame('visit_requests', $reminder->related_record_type);
        $this->assertSame('Reminder: Confirm Your Visit', $reminder->title);
        $this->assertStringContainsString($this->pdl->full_name, $reminder->message);
        $this->assertStringContainsString('before Oct 9, 2026 10:00 AM', $reminder->message);

        // Hourly reruns send nothing more.
        $this->at('2026-10-08 11:00:00');
        $this->remind('confirmation', 0);
        $this->at('2026-10-09 09:00:00');
        $this->remind('confirmation', 0);
        $this->assertCount(1, $this->reminders(self::CONFIRM));
    }

    public function test_no_confirmation_reminder_once_the_visitor_confirmed(): void
    {
        $this->service()->confirmAssignedVisit($this->staffAssignedVisit());

        $this->at('2026-10-08 10:30:00');
        $this->remind('confirmation', 0);
        $this->assertCount(0, $this->reminders(self::CONFIRM));
    }

    public function test_no_confirmation_reminder_once_the_visitor_declined(): void
    {
        $this->service()->declineAssignedVisit($this->staffAssignedVisit(), 'Cannot come.');

        $this->at('2026-10-08 10:30:00');
        $this->remind('confirmation', 0);
        $this->assertCount(0, $this->reminders(self::CONFIRM));
    }

    public function test_no_confirmation_reminder_for_a_cancelled_visit(): void
    {
        $this->staffAssignedVisit()->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->at('2026-10-08 10:30:00');
        $this->remind('confirmation', 0);
        $this->assertCount(0, $this->reminders(self::CONFIRM));
    }

    public function test_no_confirmation_reminder_after_the_deadline_passed(): void
    {
        // Still pending because the nightly expiry has not run yet.
        $visit = $this->staffAssignedVisit();

        $this->at('2026-10-09 10:30:00');
        $this->assertSame('pending_confirmation', $visit->fresh()->status);
        $this->remind('confirmation', 0);
        $this->assertCount(0, $this->reminders(self::CONFIRM));
    }

    public function test_a_deadline_of_24_hours_or_less_gets_no_separate_reminder(): void
    {
        // Assigned with a 20-hour deadline: the assignment notice is the only one.
        $this->staffAssignedVisit(['confirmation_deadline' => now()->addHours(20)->toDateTimeString()]);

        $this->remind('confirmation', 0);
        $this->at('2026-10-08 05:00:00');
        $this->remind('confirmation', 0);

        $this->assertCount(0, $this->reminders(self::CONFIRM));
    }

    public function test_no_confirmation_reminder_for_a_walk_in_without_the_app(): void
    {
        $this->staffAssignedVisit();
        $this->makeNoAppWalkIn();

        $this->at('2026-10-08 10:30:00');
        $this->remind('confirmation', 0);

        $this->assertCount(0, $this->reminders(self::CONFIRM));
    }

    // -------------------------------------------------- Visit-day reminder

    public function test_visit_day_reminder_is_sent_once_on_the_day(): void
    {
        $visit = $this->confirmedVisit();

        // The day before: nothing.
        $this->at('2026-10-08 07:00:00');
        $this->remind('today', 0);

        $this->at('2026-10-09 07:00:00');
        $this->remind('today', 1);

        $reminder = $this->reminders(self::TODAY)->sole();
        $this->assertSame($visit->visit_request_id, (int) $reminder->related_record_id);
        $this->assertSame('Visit Today', $reminder->title);
        $this->assertStringContainsString("Your visit with {$this->pdl->full_name} is today, Oct 9, 2026 09:00–11:30.", $reminder->message);
        $this->assertStringContainsString('arrive 15 minutes early with a valid ID and your QR pass', $reminder->message);

        // A second run (or a restart) sends nothing more.
        $this->remind('today', 0);
        $this->assertCount(1, $this->reminders(self::TODAY));
    }

    public function test_no_visit_day_reminder_for_a_visit_still_waiting_for_an_answer(): void
    {
        $this->staffAssignedVisit(); // pending_confirmation

        $this->at('2026-10-09 07:00:00');
        $this->remind('today', 0);
        $this->assertCount(0, $this->reminders(self::TODAY));
    }

    public function test_no_visit_day_reminder_for_a_cancelled_visit(): void
    {
        $this->confirmedVisit()->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->at('2026-10-09 07:00:00');
        $this->remind('today', 0);
        $this->assertCount(0, $this->reminders(self::TODAY));
    }

    public function test_no_visit_day_reminder_for_a_walk_in_without_the_app(): void
    {
        $this->confirmedVisit();
        $this->makeNoAppWalkIn();

        $this->at('2026-10-09 07:00:00');
        $this->remind('today', 0);

        $this->assertCount(0, $this->reminders(self::TODAY));
    }

    public function test_reminders_do_not_change_the_visit(): void
    {
        $visit = $this->staffAssignedVisit();

        $before = $visit->fresh()->toArray();
        $this->at('2026-10-08 10:00:00');
        $this->remind('confirmation', 1);
        $this->assertSame($before, $visit->fresh()->toArray());

        $this->service()->confirmAssignedVisit($visit->fresh());
        $before = $visit->fresh()->toArray();
        $this->at('2026-10-09 07:00:00');
        $this->remind('today', 1);
        $this->assertSame($before, $visit->fresh()->toArray());
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->artisan('visits:remind', ['type' => 'weekly'])->assertExitCode(2);
    }

    public function test_reminders_are_scheduled_hourly_and_at_7_am(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event) => [trim(\Illuminate\Support\Str::after($event->command, 'artisan')) => $event]);

        $confirmation = $events->first(fn ($event, $command) => str_contains($command, 'visits:remind confirmation'));
        $today = $events->first(fn ($event, $command) => str_contains($command, 'visits:remind today'));

        $this->assertNotNull($confirmation);
        $this->assertSame('0 * * * *', $confirmation->expression);
        $this->assertNotNull($today);
        $this->assertSame('0 7 * * *', $today->expression);
    }
}
