<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FacilityVisitationRule;
use App\Models\Notification;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * A visitor cancels their own visit (POST /api/schedules/{id}/cancel):
 * `assigned` or `confirmed` -> `declined`, until the first session starts and
 * never after a check-in; every seat released and the QR pass voided. A
 * staff-assigned visit awaiting the visitor's answer keeps its Decline.
 *
 * "Now" starts at Wednesday 2026-10-07 10:00 Asia/Manila; the visits are on
 * Friday 2026-10-09: morning 09:00–11:30, afternoon 13:00–16:30.
 */
class VisitorCancelVisitTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'fri',
            'time_slot_start' => '13:00:00',
            'time_slot_end' => '16:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);

        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'x.png',
            'verification_status' => 'verified',
        ]);

        $this->publishSessions('2026-10-01', '2026-11-30');
    }

    // ------------------------------------------------------------ Helpers

    /** The visitor's own request for $startTimes on Friday, still awaiting review. */
    private function requestedVisit(array $startTimes = ['09:00']): VisitRequest
    {
        Sanctum::actingAs($this->visitorAccount);
        $id = $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::FRIDAY,
            'startTimes' => $startTimes,
        ])->assertCreated()->json('id');

        return VisitRequest::findOrFail($id);
    }

    /** The visitor's own request, approved by staff. */
    private function confirmedVisit(array $startTimes = ['09:00']): VisitRequest
    {
        $visit = $this->requestedVisit($startTimes);
        $this->completeRequirements();

        return ($visit)->fresh();
    }

    /** A staff-assigned visit awaiting the visitor's answer. */
    private function staffAssignedVisit(): VisitRequest
    {
        return app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
    }

    private function cancel(VisitRequest $visit, array $body = ['reason' => 'Medical Reason']): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->visitorAccount);

        return $this->postJson("/api/schedules/{$visit->visit_request_id}/cancel", $body);
    }

    private function at(string $time, string $date = self::FRIDAY): void
    {
        $this->travelTo(CarbonImmutable::parse("{$date} {$time}:00", 'Asia/Manila'));
    }

    /** @return list<int> seats taken on each session of the visit, earliest first */
    private function seatsTaken(VisitRequest $visit): array
    {
        return $visit->sessionSchedules()->orderBy('time_slot_start')->get()
            ->map(fn ($s) => (int) $s->slots_taken)->all();
    }

    private function timelineSteps(VisitRequest $visit): \Illuminate\Support\Collection
    {
        Sanctum::actingAs($this->visitorAccount);

        return collect($this->getJson("/api/schedules/{$visit->visit_request_id}/timeline")
            ->assertOk()->json('steps'))->keyBy('id');
    }

    // ------------------------------------------------------------ Success

    public function test_the_visitor_cancels_their_own_request_awaiting_review(): void
    {
        $visit = $this->requestedVisit(['09:00']);
        $this->assertSame([1], $this->seatsTaken($visit));

        $this->cancel($visit)->assertOk()->assertJsonPath('status', 'declined');

        $visit->refresh();
        $this->assertSame('declined', $visit->status);
        $this->assertSame('Medical Reason', $visit->cancellation_reason);
        $this->assertNotNull($visit->cancelled_at);
        $this->assertSame([0], $this->seatsTaken($visit));
        $this->assertTrue(Notification::where('account_id', $this->visitorAccount->account_id)
            ->where('title', 'Visit Cancelled')->exists());
        $this->assertTrue(AuditLog::where('record_type', 'visit_requests')
            ->where('record_id', $visit->visit_request_id)
            ->where('description', 'like', 'Visitor cancelled visit via mobile app%')->exists());

        // The date is free again: the visitor can request it anew.
        $this->requestedVisit(['09:00']);
    }

    public function test_the_visitor_cancels_a_confirmed_whole_day_visit_and_its_qr_pass_is_void(): void
    {
        $visit = $this->confirmedVisit(['09:00', '13:00']);
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertOk();
        $this->assertSame([1, 1], $this->seatsTaken($visit));

        $this->cancel($visit, ['reason' => 'Personal Conflict', 'notes' => 'Work schedule changed'])->assertOk();

        $visit->refresh();
        $this->assertSame('declined', $visit->status);
        $this->assertSame('Personal Conflict: Work schedule changed', $visit->cancellation_reason);
        $this->assertSame([0, 0], $this->seatsTaken($visit));
        $this->assertSame('void', $visit->qrCode()->sole()->status);

        Sanctum::actingAs($this->visitorAccount);
        $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertStatus(409);
    }

    public function test_cancelling_is_allowed_until_the_first_session_starts(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('08:59');

        $this->cancel($visit)->assertOk();
        $this->assertSame('declined', $visit->fresh()->status);
    }

    // ------------------------------------------------------------ Refusals

    public function test_a_visit_awaiting_the_visitors_answer_keeps_its_decline_instead(): void
    {
        $visit = $this->staffAssignedVisit();

        $this->cancel($visit)->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'This visit is waiting for your answer. Use Unable to Attend to decline it.');
        $this->assertSame('pending_confirmation', $visit->fresh()->status);

        Sanctum::actingAs($this->visitorAccount);
        $this->postJson("/api/schedules/{$visit->visit_request_id}/decline", ['reason' => 'Sick'])->assertOk();
        $this->assertSame('declined', $visit->fresh()->status);
    }

    public function test_cancelling_is_refused_after_check_in(): void
    {
        $visit = $this->confirmedVisit(['09:00']);
        $this->at('08:45');
        Sanctum::actingAs($this->visitorAccount);
        $token = $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertOk()->json('qrToken');

        $this->actingAs($this->frontDeskAccount, 'web');
        $this->postJson('/front-desk/checkin-checkout/confirm', [
            'qr_token' => $token,
            'id_surrendered_type' => 'national_id',
            'id_match_status' => 'matched',
        ])->assertOk();

        $this->cancel($visit)->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'You have already checked in for this visit, so it can no longer be cancelled.');
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_cancelling_is_refused_once_the_first_session_has_started(): void
    {
        $visit = $this->confirmedVisit(['09:00', '13:00']);
        $this->at('09:00');

        $this->cancel($visit)->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'This visit has already started and can no longer be cancelled.');

        $visit->refresh();
        $this->assertSame('confirmed', $visit->status);
        $this->assertSame([1, 1], $this->seatsTaken($visit));
    }

    public function test_another_visitor_cannot_cancel_the_visit(): void
    {
        $visit = $this->confirmedVisit(['09:00']);

        $other = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'other.visitor',
            'email' => 'other.visitor@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $other->forceFill(['email_verified_at' => now()])->save();
        VisitorProfile::create([
            'account_id' => $other->account_id,
            'full_name' => 'Other Visitor',
            'date_of_birth' => '1990-01-01',
            'contact_number' => '09170000000',
            'verification_status' => 'verified',
        ]);

        Sanctum::actingAs($other);
        $this->postJson("/api/schedules/{$visit->visit_request_id}/cancel", ['reason' => 'Other'])->assertNotFound();
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_finished_visits_cannot_be_cancelled(): void
    {
        $visit = $this->confirmedVisit(['09:00']);

        foreach (['completed', 'cancelled', 'no_show', 'declined'] as $status) {
            $visit->update(['status' => $status]);

            $this->cancel($visit)->assertStatus(422)
                ->assertJsonPath('errors.status.0', 'This visit can no longer be cancelled.');
            $this->assertSame($status, $visit->fresh()->status);
        }
    }

    public function test_a_reason_is_required(): void
    {
        $visit = $this->confirmedVisit(['09:00']);

        $this->cancel($visit, [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->cancel($visit, ['reason' => '   '])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    // ------------------------------------------------------------ Timeline

    public function test_the_timeline_says_cancelled_for_a_cancel_and_declined_for_a_decline(): void
    {
        $own = $this->requestedVisit(['09:00']);
        $this->cancel($own)->assertOk();
        $steps = $this->timelineSteps($own);
        $this->assertSame('Visit Cancelled', $steps['visit_cancelled']['title']);
        $this->assertSame('You cancelled this visit. Reason: Medical Reason', $steps['visit_cancelled']['description']);
        $this->assertArrayNotHasKey('visit_declined', $steps);
        $this->assertArrayNotHasKey('request_rejected', $steps);

        // A staff-assigned visit the visitor confirmed, then cancelled.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $assigned = $this->staffAssignedVisit();
        Sanctum::actingAs($this->visitorAccount);
        $this->completeRequirements();
        $this->postJson("/api/schedules/{$assigned->visit_request_id}/confirm")->assertOk();
        $this->cancel($assigned)->assertOk();
        $steps = $this->timelineSteps($assigned);
        $this->assertSame('Visit Cancelled', $steps['visit_cancelled']['title']);
        $this->assertArrayNotHasKey('visit_declined', $steps);
    }

    public function test_the_visit_data_marks_a_cancel_but_not_a_decline(): void
    {
        // Cancelled own request.
        $own = $this->requestedVisit(['09:00']);
        $this->cancel($own)->assertOk()->assertJsonPath('cancelledByVisitor', true);

        // Declined staff assignment (never confirmed).
        $declined = $this->staffAssignedVisit();
        Sanctum::actingAs($this->visitorAccount);
        $this->postJson("/api/schedules/{$declined->visit_request_id}/decline", ['reason' => 'Sick'])
            ->assertOk()->assertJsonPath('cancelledByVisitor', false);

        // The visit list and history carry the same flag; other statuses are false.
        Sanctum::actingAs($this->visitorAccount);
        $byId = fn (array $rows) => collect($rows)->keyBy(fn ($row) => (string) $row['id']);
        $history = $byId($this->getJson('/api/visits/history')->assertOk()->json('visits'));
        $this->assertTrue($history[(string) $own->visit_request_id]['cancelledByVisitor']);
        $this->assertFalse($history[(string) $declined->visit_request_id]['cancelledByVisitor']);

        $confirmed = $this->confirmedVisit(['13:00']);
        Sanctum::actingAs($this->visitorAccount);
        $visits = $byId($this->getJson('/api/visits')->assertOk()->json('visits'));
        $this->assertFalse($visits[(string) $confirmed->visit_request_id]['cancelledByVisitor']);
    }

    public function test_declining_a_staff_assignment_still_reads_as_declined(): void
    {
        $visit = $this->staffAssignedVisit();
        Sanctum::actingAs($this->visitorAccount);
        $this->postJson("/api/schedules/{$visit->visit_request_id}/decline", ['reason' => 'Sick'])->assertOk();

        $steps = $this->timelineSteps($visit);
        $this->assertSame('Visit Declined', $steps['visit_declined']['title']);
        $this->assertArrayNotHasKey('visit_cancelled', $steps);
    }
}
