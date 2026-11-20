<?php

namespace Tests\Feature;

use App\Mail\VisitUpdate;
use App\Models\Notification;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Visit notifications (Notification::EMAILED_TYPES) are also emailed
 * (VisitUpdate): same subject and text as the in-app notice, one email per
 * notification, sent after the database commit, only to an active account
 * with a verified email. Other notification types are not emailed.
 */
class VisitEmailTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        Mail::fake();
    }

    private function staffAssignedVisit(): VisitRequest
    {
        return app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
    }

    private function notifyVisit(string $type = 'visits'): Notification
    {
        return Notification::notify($this->visitorAccount->account_id, $type, 'Visit Cancelled', 'Your visit was cancelled.', 'visit_requests', 1);
    }

    public function test_a_visit_event_emails_the_same_title_and_text_once(): void
    {
        $visit = $this->staffAssignedVisit();

        $notice = Notification::where('related_record_id', $visit->visit_request_id)->sole();
        Mail::assertSentCount(1);
        Mail::assertSent(VisitUpdate::class, function (VisitUpdate $mail) use ($notice) {
            return $mail->hasTo('maria.santos@example.com')
                && $mail->title === $notice->title
                && $mail->body === $notice->message
                && $mail->envelope()->subject === "CustodiCore: {$notice->title}";
        });
    }

    public function test_each_visit_step_sends_one_email(): void
    {
        $visit = $this->staffAssignedVisit();                                   // Visit Assigned
        app(VisitAssignmentService::class)->confirmAssignedVisit($visit);       // Visit Confirmed

        Mail::assertSentCount(2);
        $this->assertSame(
            ['Visit Assigned — Confirmation Required', 'Visit Confirmed'],
            Mail::sent(VisitUpdate::class)->map(fn (VisitUpdate $m) => $m->title)->values()->all()
        );
    }

    public function test_reminders_are_emailed_too(): void
    {
        $this->staffAssignedVisit();
        Mail::assertSentCount(1);

        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'Asia/Manila'));
        $this->artisan('visits:remind', ['type' => 'confirmation'])->assertSuccessful();

        Mail::assertSent(VisitUpdate::class, fn (VisitUpdate $m) => $m->title === 'Reminder: Confirm Your Visit');
        Mail::assertSentCount(2);
    }

    public function test_other_notification_types_are_not_emailed(): void
    {
        foreach (['account_rejected', 'document_rejected', 'relationship_approved', 'pdl_request_rejected'] as $type) {
            Notification::notify($this->visitorAccount->account_id, $type, 'Title', 'Message.');
        }

        Mail::assertNothingSent();
    }

    public function test_no_email_without_a_verified_email_or_for_an_inactive_account(): void
    {
        $this->visitorAccount->forceFill(['email_verified_at' => null])->save();
        $this->notifyVisit();

        $this->visitorAccount->forceFill(['email_verified_at' => now(), 'status' => 'inactive'])->save();
        $this->notifyVisit();

        $this->visitorAccount->forceFill(['status' => 'active', 'email' => null])->save();
        $this->notifyVisit();

        Mail::assertNothingSent();
        // The in-app notices are still saved.
        $this->assertSame(3, Notification::where('account_id', $this->visitorAccount->account_id)->count());
    }

    public function test_email_waits_for_the_commit_and_a_rollback_sends_nothing(): void
    {
        DB::transaction(function () {
            $this->notifyVisit();
            Mail::assertNothingSent(); // not yet: still inside the transaction
        });
        Mail::assertSentCount(1);

        try {
            DB::transaction(function () {
                $this->notifyVisit();
                throw new \RuntimeException('action failed');
            });
        } catch (\RuntimeException) {
        }

        Mail::assertSentCount(1); // the rolled-back notice was never emailed
        $this->assertSame(1, Notification::count());
    }

    public function test_a_mail_failure_never_breaks_the_action(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $visit = $this->staffAssignedVisit();

        $this->assertSame('pending_confirmation', $visit->fresh()->status);
        $this->assertTrue(Notification::where('related_record_id', $visit->visit_request_id)->exists());
    }

    public function test_the_email_text_is_readable_and_the_html_is_escaped(): void
    {
        $mail = new VisitUpdate($this->visitorAccount, 'Visit Request Not Approved', "Reason: The PDL's schedule <changed>.");

        $mail->assertSeeInText("Reason: The PDL's schedule <changed>.", false);
        $mail->assertSeeInHtml('Reason: The PDL&#039;s schedule &lt;changed&gt;.', false);
        $mail->assertSeeInHtml('Visit Request Not Approved');
    }
}
