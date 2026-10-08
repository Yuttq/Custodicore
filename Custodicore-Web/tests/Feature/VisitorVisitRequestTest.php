<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\EligibilityAssessment;
use App\Models\FacilityVisitationRule;
use App\Models\Notification;
use App\Models\PdlRestriction;
use App\Models\StaffProfile;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Stage 1 of the visitor visit request workflow:
 *   POST /api/visit-requests            -> assigned (awaiting staff review)
 *   Record Officer approve / reject     -> confirmed / cancelled
 * and the rule that a visitor can never confirm an `assigned` request.
 *
 * "Now" is pinned to Wednesday 2026-10-07 10:00 Asia/Manila. The fixture
 * PDL is non_drug_related (Fri/Sun). Fri 2026-10-09 09:00 has a seeded
 * visit_schedules row (capacity 2); Fri 13:00 has a rule but no row.
 */
class VisitorVisitRequestTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    private FacilityVisitationRule $afternoonRule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        $this->afternoonRule = FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'fri',
            'time_slot_start' => '13:00:00',
            'time_slot_end' => '16:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);
        FacilityVisitationRule::create([
            'pdl_classification' => 'drug_related',
            'day_of_week' => 'thu',
            'time_slot_start' => '09:00:00',
            'time_slot_end' => '11:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);
    }

    private function submit(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/visit-requests', array_merge([
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::FRIDAY,
            'startTime' => '09:00',
        ], $overrides));
    }

    /** A visitor-submitted request, created through the service (for staff tests). */
    private function submittedRequest(?VisitorProfile $visitor = null, ?VisitorPdlRelationship $relationship = null): VisitRequest
    {
        return app(VisitAssignmentService::class)->submitVisitorRequest(
            $visitor ?? $this->visitor,
            $relationship ?? $this->relationship,
            CarbonImmutable::parse(self::FRIDAY, 'Asia/Manila'),
            '09:00'
        );
    }

    private function makeOtherVisitor(string $username = 'carlo.ramos'): array
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => $username,
            'email' => "{$username}@example.com",
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $account->forceFill(['email_verified_at' => now()])->save();
        $visitor = VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => 'Carlo J. Ramos',
            'date_of_birth' => '1979-11-02',
            'gender' => 'male',
            'contact_number' => '09285550199',
            'verification_status' => 'verified',
        ]);
        $relationship = VisitorPdlRelationship::create([
            'visitor_id' => $visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'legal_counsel',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]);

        return [$account, $visitor, $relationship];
    }

    // ------------------------------------------------------- Submission

    public function test_visitor_can_submit_request_for_verified_relationship(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $response = $this->submit()
            ->assertCreated()
            ->assertJsonPath('status', 'assigned')
            ->assertJsonPath('pdlName', $this->pdl->full_name)
            ->assertJsonPath('scheduledAt', self::FRIDAY.'T09:00:00+08:00');

        $visit = VisitRequest::findOrFail($response->json('id'));
        $this->assertSame('assigned', $visit->status);
        $this->assertSame($this->visitor->visitor_id, $visit->visitor_id);
        $this->assertSame($this->pdl->pdl_id, $visit->pdl_id);
        $this->assertSame($this->relationship->relationship_id, $visit->relationship_id);
        $this->assertSame($this->schedule->schedule_id, $visit->schedule_id);
        $this->assertNull($visit->confirmation_deadline);
        $this->assertNull($visit->confirmed_at);

        $this->assertSame(1, $this->schedule->fresh()->slots_taken);
        $this->assertSame(1, VisitSchedule::count(), 'An existing schedule row is reused.');

        $this->assertTrue(EligibilityAssessment::where('visit_request_id', $visit->visit_request_id)->exists());
        $this->assertTrue(AuditLog::where('record_type', 'visit_requests')
            ->where('record_id', $visit->visit_request_id)
            ->where('action_type', 'create')
            ->exists());

        $notification = Notification::where('account_id', $this->visitorAccount->account_id)->latest('notification_id')->first();
        $this->assertNotNull($notification);
        $this->assertSame('Visit Request Submitted', $notification->title);
        $this->assertSame($visit->visit_request_id, $notification->related_record_id);
    }

    public function test_schedule_row_is_created_when_missing(): void
    {
        Sanctum::actingAs($this->visitorAccount);
        $this->assertFalse(VisitSchedule::where('rule_id', $this->afternoonRule->rule_id)->exists());

        $response = $this->submit(['startTime' => '13:00'])->assertCreated();

        $schedule = VisitSchedule::where('rule_id', $this->afternoonRule->rule_id)->sole();
        $this->assertSame(self::FRIDAY, $schedule->schedule_date->toDateString());
        $this->assertSame('13:00', substr($schedule->time_slot_start, 0, 5));
        $this->assertSame('16:30', substr($schedule->time_slot_end, 0, 5));
        $this->assertSame(30, (int) $schedule->max_capacity);
        $this->assertSame(1, (int) $schedule->slots_taken);
        $this->assertSame('open', $schedule->status);
        $this->assertSame($schedule->schedule_id, VisitRequest::findOrFail($response->json('id'))->schedule_id);
    }

    public function test_unverified_relationship_is_rejected(): void
    {
        $this->relationship->update(['verification_status' => 'pending']);
        Sanctum::actingAs($this->visitorAccount);

        $this->submit()->assertUnprocessable()->assertJsonValidationErrors('relationshipId');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_another_visitors_relationship_is_rejected(): void
    {
        [, , $otherRelationship] = $this->makeOtherVisitor();
        Sanctum::actingAs($this->visitorAccount);

        $this->submit(['relationshipId' => $otherRelationship->relationship_id])->assertNotFound();
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_unavailable_date_is_rejected(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        // Thursday is a drug-related visiting day; this PDL is non-drug-related.
        $this->submit(['date' => '2026-10-08'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->assertSame(0, VisitRequest::count());
        $this->assertSame(1, VisitSchedule::count());
    }

    public function test_unavailable_time_is_rejected(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $this->submit(['startTime' => '10:00'])->assertUnprocessable()->assertJsonValidationErrors('startTime');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_past_date_is_rejected(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $this->submit(['date' => '2026-10-02'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_date_outside_rule_effective_dates_is_rejected(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        // Only the 13:00 rule has ended: that slot is no longer offered that day.
        $this->afternoonRule->update(['effective_to' => '2026-10-08']);
        $this->submit(['startTime' => '13:00'])->assertUnprocessable()->assertJsonValidationErrors('startTime');

        // Every Friday rule has ended: the whole day is outside the visitation period.
        $this->rule->update(['effective_to' => '2026-10-08']);
        $this->submit()->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->assertSame(0, VisitRequest::count());
        $this->assertFalse(VisitSchedule::where('rule_id', $this->afternoonRule->rule_id)->exists());
    }

    public function test_ineligible_pdl_is_rejected(): void
    {
        PdlRestriction::create([
            'pdl_id' => $this->pdl->pdl_id,
            'restriction_type' => 'quarantine',
            'status' => 'active',
            'start_date' => '2026-10-01',
            'reason' => 'Health protocol',
            'imposed_by' => StaffProfile::first()->staff_id,
        ]);
        Sanctum::actingAs($this->visitorAccount);

        $this->submit()->assertUnprocessable()->assertJsonValidationErrors('relationshipId');

        $this->pdl->activeRestrictions()->update(['status' => 'lifted']);
        $this->pdl->update(['custody_status' => 'released']);
        $this->submit()->assertUnprocessable()->assertJsonValidationErrors('relationshipId');

        $this->assertSame(0, VisitRequest::count());
    }

    public function test_full_schedule_is_rejected(): void
    {
        [, $otherVisitor, $otherRelationship] = $this->makeOtherVisitor();
        $this->schedule->update(['max_capacity' => 1]);
        $this->submittedRequest($otherVisitor, $otherRelationship);
        $this->assertSame('full', $this->schedule->fresh()->status);

        Sanctum::actingAs($this->visitorAccount);
        $this->submit()->assertUnprocessable()->assertJsonValidationErrors('startTime');

        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(1, $this->schedule->fresh()->slots_taken);
    }

    public function test_closed_schedule_is_rejected(): void
    {
        $this->schedule->update(['status' => 'closed']);
        Sanctum::actingAs($this->visitorAccount);

        $this->submit()->assertUnprocessable()->assertJsonValidationErrors('startTime');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_duplicate_request_is_rejected(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $this->submit()->assertCreated();
        $this->submit()->assertUnprocessable()->assertJsonValidationErrors('startTime');

        $this->assertSame(1, VisitRequest::count());
        $this->assertSame(1, $this->schedule->fresh()->slots_taken);
    }

    public function test_invalid_body_is_rejected(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson('/api/visit-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['relationshipId', 'date', 'startTime']);
        $this->submit(['date' => '09/10/2026', 'startTime' => '9am'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'startTime']);
    }

    public function test_unapproved_or_unauthenticated_visitor_cannot_submit(): void
    {
        $this->submit()->assertUnauthorized();

        $this->visitor->update(['verification_status' => 'pending']);
        Sanctum::actingAs($this->visitorAccount);
        $this->submit()->assertForbidden()->assertJsonPath('code', 'visitor_not_approved');

        $this->assertSame(0, VisitRequest::count());
    }

    public function test_schedule_row_created_concurrently_by_another_request_is_reused(): void
    {
        // Another request creates the 13:00 row after this request read
        // availability (which saw no row) but before it inserts its own.
        $this->bindAvailabilityRace(function () {
            VisitSchedule::create([
                'rule_id' => $this->afternoonRule->rule_id,
                'schedule_date' => self::FRIDAY,
                'time_slot_start' => '13:00:00',
                'time_slot_end' => '16:30:00',
                'max_capacity' => 30,
                'slots_taken' => 1,
                'status' => 'open',
            ]);
        });
        Sanctum::actingAs($this->visitorAccount);

        $response = $this->submit(['startTime' => '13:00'])->assertCreated();

        $schedule = VisitSchedule::where('rule_id', $this->afternoonRule->rule_id)->sole();
        $this->assertSame($schedule->schedule_id, VisitRequest::findOrFail($response->json('id'))->schedule_id);
        $this->assertSame(2, (int) $schedule->slots_taken);
    }

    public function test_slot_filled_concurrently_is_rejected_without_side_effects(): void
    {
        // Another request fills the slot after availability was read.
        $this->bindAvailabilityRace(function () {
            VisitSchedule::create([
                'rule_id' => $this->afternoonRule->rule_id,
                'schedule_date' => self::FRIDAY,
                'time_slot_start' => '13:00:00',
                'time_slot_end' => '16:30:00',
                'max_capacity' => 30,
                'slots_taken' => 30,
                'status' => 'full',
            ]);
        });
        Sanctum::actingAs($this->visitorAccount);

        $this->submit(['startTime' => '13:00'])->assertUnprocessable();

        $this->assertSame(0, VisitRequest::count());
        $schedule = VisitSchedule::where('rule_id', $this->afternoonRule->rule_id)->sole();
        $this->assertSame(30, (int) $schedule->slots_taken);
    }

    public function test_weekly_limit_reached_after_availability_was_read_is_rejected_without_side_effects(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        // Availability shows the Friday afternoon slot as open.
        $slot = collect($this->getJson('/api/schedules/availability?from='.self::FRIDAY.'&to='.self::FRIDAY)
            ->assertOk()
            ->json('relationships.0.days.0.slots'))->firstWhere('startTime', '13:00');
        $this->assertTrue($slot['available']);

        // Two visits in the same week land after this request read slot
        // availability but before its transaction, so only the locked
        // re-check in VisitAssignmentService can catch the limit.
        $thursdayRule = FacilityVisitationRule::where('pdl_classification', 'drug_related')->sole();
        $this->bindAvailabilityRace(function () use ($thursdayRule) {
            $thursday = VisitSchedule::create([
                'rule_id' => $thursdayRule->rule_id,
                'schedule_date' => '2026-10-08',
                'time_slot_start' => '09:00:00',
                'time_slot_end' => '11:30:00',
                'max_capacity' => 30,
                'slots_taken' => 0,
                'status' => 'open',
            ]);
            foreach ([$thursday->schedule_id, $this->schedule->schedule_id] as $scheduleId) {
                VisitRequest::create([
                    'visitor_id' => $this->visitor->visitor_id,
                    'pdl_id' => $this->pdl->pdl_id,
                    'relationship_id' => $this->relationship->relationship_id,
                    'schedule_id' => $scheduleId,
                    'status' => 'confirmed',
                    'confirmation_deadline' => null,
                ]);
            }
        });

        $this->submit(['startTime' => '13:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'schedule_id' => 'You have reached the maximum number of visits allowed for this calendar week.',
            ]);

        $this->assertSame(2, VisitRequest::count(), 'Only the two racing visits exist.');
        $this->assertSame(0, VisitSchedule::where('rule_id', $this->afternoonRule->rule_id)->count(), 'The on-demand row is rolled back.');
        $this->assertSame(0, (int) $this->schedule->fresh()->slots_taken);
    }

    /** Runs $race right after the request reads slot availability. */
    private function bindAvailabilityRace(\Closure $race): void
    {
        $this->app->instance(ScheduleAvailabilityService::class, new class($race) extends ScheduleAvailabilityService {
            public function __construct(private \Closure $race)
            {
            }

            public function slotFor(VisitorProfile $visitor, VisitorPdlRelationship $relationship, CarbonImmutable $date, string $startTime): ?array
            {
                $slot = parent::slotFor($visitor, $relationship, $date, $startTime);
                ($this->race)();

                return $slot;
            }
        });
    }

    // ------------------------------------------------- Status / security

    public function test_visitor_cannot_confirm_or_decline_an_assigned_request(): void
    {
        $visit = $this->submittedRequest();
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->postJson("/api/schedules/{$visit->visit_request_id}/decline")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")
            ->assertStatus(409)
            ->assertJsonPath('status', 'assigned');

        $visit->refresh();
        $this->assertSame('assigned', $visit->status);
        $this->assertNull($visit->confirmed_at);
        $this->assertSame(1, $this->schedule->fresh()->slots_taken);
    }

    public function test_visitor_can_still_confirm_a_staff_assigned_visit(): void
    {
        $visit = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $this->assertSame('pending_confirmation', $visit->status);
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');
    }

    public function test_staff_assignment_still_creates_pending_confirmation(): void
    {
        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ])
            ->assertSessionHasNoErrors();

        $visit = VisitRequest::sole();
        $this->assertSame('pending_confirmation', $visit->status);
        $this->assertNotNull($visit->confirmation_deadline);
    }

    // ------------------------------------ Visitor confirm / decline (staff-assigned)

    public function test_visitor_confirms_a_pending_visit(): void
    {
        $visit = $this->staffAssignedVisit();
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")
            ->assertOk()
            ->assertJsonPath('id', (string) $visit->visit_request_id)
            ->assertJsonPath('status', 'confirmed');

        $visit->refresh();
        $this->assertSame('confirmed', $visit->status);
        $this->assertNotNull($visit->confirmed_at);
        $this->assertSame(1, $this->schedule->fresh()->slots_taken, 'Confirming keeps the seat.');
        $this->assertTrue(Notification::where('account_id', $this->visitorAccount->account_id)
            ->where('title', 'Visit Confirmed')
            ->where('related_record_id', $visit->visit_request_id)
            ->exists());
        $this->assertTrue(AuditLog::where('record_type', 'visit_requests')
            ->where('record_id', $visit->visit_request_id)
            ->where('description', 'Visitor confirmed attendance via mobile app')
            ->exists());
    }

    public function test_visitor_cannot_confirm_after_the_confirmation_deadline(): void
    {
        // Default window is 48h from Wed 10:00 -> Fri 10:00; the visit is Fri 09:00.
        $visit = $this->staffAssignedVisit();
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 10:30:00', 'Asia/Manila'));
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation_deadline');

        $this->assertSame('pending_confirmation', $visit->fresh()->status);
        $this->assertNull($visit->fresh()->confirmed_at);
    }

    public function test_visitor_cannot_confirm_a_visit_whose_date_has_passed(): void
    {
        $visit = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
            // Deadline still open, so only the visit date can refuse it.
            'confirmation_deadline' => '2026-10-20 12:00:00',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Asia/Manila'));
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule_id');

        $this->assertSame('pending_confirmation', $visit->fresh()->status);
    }

    public function test_visitor_cannot_confirm_when_the_relationship_was_rejected(): void
    {
        $visit = $this->staffAssignedVisit();
        $this->relationship->update(['verification_status' => 'rejected']);
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('relationship_id');

        $this->assertSame('pending_confirmation', $visit->fresh()->status);
    }

    public function test_visitor_cannot_confirm_when_the_pdl_became_ineligible(): void
    {
        $visit = $this->staffAssignedVisit();
        PdlRestriction::create([
            'pdl_id' => $this->pdl->pdl_id,
            'restriction_type' => 'quarantine',
            'status' => 'active',
            'start_date' => '2026-10-01',
            'reason' => 'Health protocol',
            'imposed_by' => StaffProfile::first()->staff_id,
        ]);
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pdl_id');

        $this->assertSame('pending_confirmation', $visit->fresh()->status);
    }

    public function test_visitor_declines_a_pending_visit_and_releases_exactly_one_seat(): void
    {
        [, $otherVisitor, $otherRelationship] = $this->makeOtherVisitor();
        $visit = $this->staffAssignedVisit();
        app(VisitAssignmentService::class)->assign([
            'visitor_id' => $otherVisitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $otherRelationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $this->assertSame(2, $this->schedule->fresh()->slots_taken);
        $this->assertSame('full', $this->schedule->fresh()->status);
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/decline", ['reason' => 'Medical Reason'])
            ->assertOk()
            ->assertJsonPath('status', 'declined')
            ->assertJsonPath('cancellationReason', 'Medical Reason');

        $visit->refresh();
        $this->assertSame('declined', $visit->status);
        $this->assertNotNull($visit->cancelled_at);
        $schedule = $this->schedule->fresh();
        $this->assertSame(1, (int) $schedule->slots_taken);
        $this->assertSame('open', $schedule->status);
        $this->assertTrue(AuditLog::where('record_type', 'visit_requests')
            ->where('record_id', $visit->visit_request_id)
            ->where('description', 'Visitor declined assigned visit via mobile app')
            ->exists());
    }

    public function test_repeated_decline_does_not_release_another_seat(): void
    {
        [, $otherVisitor, $otherRelationship] = $this->makeOtherVisitor();
        $visit = $this->staffAssignedVisit();
        app(VisitAssignmentService::class)->assign([
            'visitor_id' => $otherVisitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $otherRelationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/decline")->assertOk();
        $this->postJson("/api/schedules/{$visit->visit_request_id}/decline")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);
        $this->assertSame(1, AuditLog::where('record_id', $visit->visit_request_id)
            ->where('description', 'Visitor declined assigned visit via mobile app')
            ->count());
    }

    public function test_decline_after_confirm_is_refused_and_keeps_the_seat(): void
    {
        $visit = $this->staffAssignedVisit();
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")->assertOk();
        $this->postJson("/api/schedules/{$visit->visit_request_id}/decline")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('confirmed', $visit->fresh()->status);
        $this->assertSame(1, $this->schedule->fresh()->slots_taken, 'A confirmed visit keeps its seat.');
    }

    public function test_confirm_after_decline_is_refused(): void
    {
        $visit = $this->staffAssignedVisit();
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/decline")->assertOk();
        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $visit->refresh();
        $this->assertSame('declined', $visit->status);
        $this->assertNull($visit->confirmed_at);
        $this->assertSame(0, $this->schedule->fresh()->slots_taken);
    }

    public function test_stale_visit_instance_cannot_decline_after_a_concurrent_confirm(): void
    {
        // Both requests loaded the visit while it was pending; the second to
        // take the row lock must see the committed status, not its own copy.
        $stale = $this->staffAssignedVisit();
        $service = app(VisitAssignmentService::class);

        $service->confirmAssignedVisit(VisitRequest::find($stale->visit_request_id));
        $this->assertSame('pending_confirmation', $stale->status);

        try {
            $service->declineAssignedVisit($stale, 'Late decline');
            $this->fail('A stale pending instance must not decline a confirmed visit.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame('confirmed', $stale->fresh()->status);
        $this->assertSame(1, $this->schedule->fresh()->slots_taken);
    }

    public function test_other_or_unauthenticated_visitors_cannot_confirm_or_decline(): void
    {
        $visit = $this->staffAssignedVisit();

        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")->assertUnauthorized();
        $this->postJson("/api/schedules/{$visit->visit_request_id}/decline")->assertUnauthorized();

        [$otherAccount] = $this->makeOtherVisitor();
        Sanctum::actingAs($otherAccount);
        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")->assertNotFound();
        $this->postJson("/api/schedules/{$visit->visit_request_id}/decline")->assertNotFound();

        $this->assertSame('pending_confirmation', $visit->fresh()->status);
        $this->assertSame(1, $this->schedule->fresh()->slots_taken);
    }

    // ------------------------------------------------------------ Staff

    public function test_record_officer_sees_pending_visitor_requests(): void
    {
        $visit = $this->submittedRequest();
        $this->actingAs($this->recordOfficerAccount);

        $approveUrl = route('visitor.visit-requests.approve', [$this->visitor->visitor_id, $visit->visit_request_id]);

        $this->get(route('visitor.index'))
            ->assertOk()
            ->assertSee('Visit Requests Awaiting Review')
            ->assertSee($this->visitor->full_name)
            ->assertSee($approveUrl, false);

        $this->get(route('visitor.show', $this->visitor->visitor_id))
            ->assertOk()
            ->assertSee('Visit Requests Awaiting Review')
            ->assertSee($approveUrl, false)
            ->assertSee(route('visitor.visit-requests.reject', [$this->visitor->visitor_id, $visit->visit_request_id]), false);
    }

    public function test_staff_assigned_visits_are_not_listed_for_review(): void
    {
        $visit = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.index'))
            ->assertOk()
            ->assertSee('No visitor-submitted visit requests are awaiting review.')
            ->assertDontSee(route('visitor.visit-requests.approve', [$this->visitor->visitor_id, $visit->visit_request_id]), false);
    }

    public function test_record_officer_can_approve_request(): void
    {
        $visit = $this->submittedRequest();

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visit-requests.approve', [$this->visitor->visitor_id, $visit->visit_request_id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $visit->refresh();
        $this->assertSame('confirmed', $visit->status);
        $this->assertNotNull($visit->confirmed_at);
        $this->assertSame($this->schedule->schedule_id, $visit->schedule_id);
        $this->assertSame(1, $this->schedule->fresh()->slots_taken, 'The reserved seat stays reserved.');

        $this->assertTrue(Notification::where('account_id', $this->visitorAccount->account_id)
            ->where('title', 'Visit Request Approved')
            ->where('related_record_id', $visit->visit_request_id)
            ->exists());
        $this->assertTrue(AuditLog::where('record_type', 'visit_requests')
            ->where('record_id', $visit->visit_request_id)
            ->where('action_type', 'update')
            ->exists());

        // The approved visit now behaves like any confirmed visit.
        $this->travelTo(CarbonImmutable::parse(self::FRIDAY.' 09:30:00', 'Asia/Manila'));
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertOk()->assertJsonPath('status', 'confirmed');
    }

    public function test_record_officer_can_reject_request_and_release_capacity(): void
    {
        $this->schedule->update(['max_capacity' => 1]);
        $visit = $this->submittedRequest();
        $this->assertSame('full', $this->schedule->fresh()->status);

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visit-requests.reject', [$this->visitor->visitor_id, $visit->visit_request_id]), [
                'cancellation_reason' => 'The PDL has a court hearing that day.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $visit->refresh();
        $this->assertSame('cancelled', $visit->status);
        $this->assertNotNull($visit->cancelled_at);
        $this->assertSame('The PDL has a court hearing that day.', $visit->cancellation_reason);

        $schedule = $this->schedule->fresh();
        $this->assertSame(0, (int) $schedule->slots_taken);
        $this->assertSame('open', $schedule->status);

        $this->assertTrue(Notification::where('account_id', $this->visitorAccount->account_id)
            ->where('title', 'Visit Request Not Approved')
            ->exists());
        $this->assertTrue(AuditLog::where('record_type', 'visit_requests')
            ->where('record_id', $visit->visit_request_id)
            ->where('action_type', 'update')
            ->where('description', 'like', '%The PDL has a court hearing that day.%')
            ->exists());

        // The released seat can be requested by someone else.
        [$otherAccount, , $otherRelationship] = $this->makeOtherVisitor();
        Sanctum::actingAs($otherAccount);
        $this->submit(['relationshipId' => $otherRelationship->relationship_id])->assertCreated();
        $this->assertSame(1, (int) $this->schedule->fresh()->slots_taken);

        // The visitor sees the rejection in history with its reason.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/visits/history')
            ->assertOk()
            ->assertJsonPath('visits.0.status', 'cancelled')
            ->assertJsonPath('visits.0.cancellationReason', 'The PDL has a court hearing that day.');
    }

    public function test_rejection_requires_a_reason(): void
    {
        $visit = $this->submittedRequest();

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visit-requests.reject', [$this->visitor->visitor_id, $visit->visit_request_id]), [
                'cancellation_reason' => '  ',
            ])
            ->assertSessionHasErrors('cancellation_reason');

        $this->assertSame('assigned', $visit->fresh()->status);
        $this->assertSame(1, $this->schedule->fresh()->slots_taken);
    }

    public function test_only_assigned_requests_can_be_approved_or_rejected(): void
    {
        $visit = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $this->actingAs($this->recordOfficerAccount);

        $this->post(route('visitor.visit-requests.approve', [$this->visitor->visitor_id, $visit->visit_request_id]))
            ->assertSessionHasErrors('status');
        $this->post(route('visitor.visit-requests.reject', [$this->visitor->visitor_id, $visit->visit_request_id]), [
            'cancellation_reason' => 'No longer needed.',
        ])->assertSessionHasErrors('status');

        $this->assertSame('pending_confirmation', $visit->fresh()->status);
        $this->assertSame(1, $this->schedule->fresh()->slots_taken);

        // A request that was already rejected cannot be rejected again (no double release).
        $visit->update(['status' => 'cancelled']);
        $this->post(route('visitor.visit-requests.reject', [$this->visitor->visitor_id, $visit->visit_request_id]), [
            'cancellation_reason' => 'Again.',
        ])->assertSessionHasErrors('status');
        $this->assertSame(1, $this->schedule->fresh()->slots_taken);
    }

    public function test_approval_is_refused_when_pdl_became_ineligible(): void
    {
        $visit = $this->submittedRequest();
        $this->pdl->update(['custody_status' => 'released']);

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visit-requests.approve', [$this->visitor->visitor_id, $visit->visit_request_id]))
            ->assertSessionHasErrors('pdl_id');

        $this->assertSame('assigned', $visit->fresh()->status);
    }

    public function test_request_must_belong_to_the_visitor_in_the_url(): void
    {
        $visit = $this->submittedRequest();
        [, $otherVisitor] = $this->makeOtherVisitor();

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visit-requests.approve', [$otherVisitor->visitor_id, $visit->visit_request_id]))
            ->assertNotFound();

        $this->assertSame('assigned', $visit->fresh()->status);
    }

    public function test_unauthorized_users_cannot_approve_or_reject(): void
    {
        $visit = $this->submittedRequest();
        $approve = route('visitor.visit-requests.approve', [$this->visitor->visitor_id, $visit->visit_request_id]);
        $reject = route('visitor.visit-requests.reject', [$this->visitor->visitor_id, $visit->visit_request_id]);
        $body = ['cancellation_reason' => 'Nope.'];

        $this->post($approve)->assertRedirect(route('login'));
        $this->post($reject, $body)->assertRedirect(route('login'));

        foreach ([$this->frontDeskAccount, $this->visitorAccount] as $account) {
            $this->actingAs($account)->post($approve)->assertForbidden();
            $this->actingAs($account)->post($reject, $body)->assertForbidden();
        }

        $this->assertSame('assigned', $visit->fresh()->status);
        $this->assertSame(1, $this->schedule->fresh()->slots_taken);
    }

    // --------------------------------------------------------------- Timeline

    /** GET /api/schedules/{id}/timeline as the visitor, keyed by step id. */
    private function timelineSteps(VisitRequest $visit): \Illuminate\Support\Collection
    {
        Sanctum::actingAs($this->visitorAccount);

        return collect($this->getJson("/api/schedules/{$visit->visit_request_id}/timeline")
            ->assertOk()->json('steps'))->keyBy('id');
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

    public function test_assigned_request_timeline_shows_request_submitted(): void
    {
        $steps = $this->timelineSteps($this->submittedRequest());

        $this->assertSame('Request Submitted', $steps['request_submitted']['title']);
        $this->assertSame('Your visit request was submitted and is awaiting review by facility staff.', $steps['request_submitted']['description']);
        $this->assertSame('completed', $steps['request_submitted']['stepState']);
        $this->assertSame('Request Approved', $steps['request_approved']['title']);
        $this->assertSame('current', $steps['request_approved']['stepState']);
        $this->assertNull($steps['request_approved']['occurredAt']);
        $this->assertArrayNotHasKey('schedule_assigned', $steps);
        $this->assertArrayNotHasKey('attendance_confirmed', $steps);
    }

    public function test_approved_request_timeline_shows_request_approved(): void
    {
        $visit = $this->submittedRequest();
        app(VisitAssignmentService::class)->approveVisitorRequest($visit);

        $steps = $this->timelineSteps($visit);

        $this->assertSame(
            ['account_created', 'documents_submitted', 'identity_verified', 'relationship_verified', 'visitor_eligible',
                'request_submitted', 'request_approved', 'qr_generated', 'checked_in', 'checked_out', 'visit_completed'],
            $steps->keys()->all()
        );
        $this->assertSame('Your visit request was submitted for review by facility staff.', $steps['request_submitted']['description']);
        $this->assertSame('Request Approved', $steps['request_approved']['title']);
        $this->assertSame('Your visit request was approved by facility staff.', $steps['request_approved']['description']);
        $this->assertSame('completed', $steps['request_approved']['stepState']);
        $this->assertNotNull($steps['request_approved']['occurredAt']);
        $this->assertSame('current', $steps['qr_generated']['stepState']);
    }

    public function test_rejected_request_timeline_shows_request_rejected(): void
    {
        $visit = $this->submittedRequest();
        app(VisitAssignmentService::class)->rejectVisitorRequest($visit, 'The PDL has a court hearing that day.');

        $steps = $this->timelineSteps($visit);

        $this->assertSame('Request Rejected', $steps['request_rejected']['title']);
        $this->assertSame(
            'Your visit request was rejected by facility staff. Reason: The PDL has a court hearing that day.',
            $steps['request_rejected']['description']
        );
        $this->assertSame('completed', $steps['request_rejected']['stepState']);
        $this->assertNotNull($steps['request_rejected']['occurredAt']);
        $this->assertSame('request_rejected', $steps->keys()->last());
        $this->assertSame('pending', $steps['request_approved']['stepState']);
        $this->assertArrayNotHasKey('visit_cancelled', $steps);
        $this->assertArrayNotHasKey('schedule_assigned', $steps);
    }

    public function test_staff_assigned_pending_confirmation_timeline_is_unchanged(): void
    {
        $steps = $this->timelineSteps($this->staffAssignedVisit());

        $this->assertSame('Schedule Assigned', $steps['schedule_assigned']['title']);
        $this->assertSame('An officer assigned your visit date and time. No self-booking is required.', $steps['schedule_assigned']['description']);
        $this->assertSame('completed', $steps['schedule_assigned']['stepState']);
        $this->assertSame('Attendance Confirmed', $steps['attendance_confirmed']['title']);
        $this->assertSame('You confirmed attendance for the assigned visit window.', $steps['attendance_confirmed']['description']);
        $this->assertSame('current', $steps['attendance_confirmed']['stepState']);
        $this->assertArrayNotHasKey('request_submitted', $steps);
        $this->assertArrayNotHasKey('request_approved', $steps);
    }

    public function test_staff_assigned_confirmed_timeline_is_unchanged(): void
    {
        $visit = $this->staffAssignedVisit();
        Sanctum::actingAs($this->visitorAccount);
        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")->assertOk();

        $steps = $this->timelineSteps($visit);

        $this->assertSame(
            ['account_created', 'documents_submitted', 'identity_verified', 'relationship_verified', 'visitor_eligible',
                'schedule_assigned', 'attendance_confirmed', 'qr_generated', 'checked_in', 'checked_out', 'visit_completed'],
            $steps->keys()->all()
        );
        $this->assertSame('Schedule Assigned', $steps['schedule_assigned']['title']);
        $this->assertSame('An officer assigned your visit date and time. No self-booking is required.', $steps['schedule_assigned']['description']);
        $this->assertSame('Attendance Confirmed', $steps['attendance_confirmed']['title']);
        $this->assertSame('You confirmed attendance for the assigned visit window.', $steps['attendance_confirmed']['description']);
        $this->assertSame('completed', $steps['attendance_confirmed']['stepState']);
        $this->assertSame('current', $steps['qr_generated']['stepState']);
    }
}
