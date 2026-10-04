<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Notification;
use App\Models\Pdl;
use App\Models\StaffProfile;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

class VisitAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
    }

    public function test_record_officer_can_assign_valid_visit(): void
    {
        $response = $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertRedirect(route('visitor.show', $this->visitor->visitor_id));
        $response->assertSessionHas('success');

        $visit = VisitRequest::first();
        $this->assertNotNull($visit);
        $this->assertSame('pending_confirmation', $visit->status);
        $this->assertSame($this->visitor->visitor_id, $visit->visitor_id);
        $this->assertSame($this->pdl->pdl_id, $visit->pdl_id);
        $this->assertSame($this->schedule->schedule_id, $visit->schedule_id);

        $this->assertSame(1, $this->schedule->fresh()->slots_taken);

        $notification = Notification::where('account_id', $this->visitorAccount->account_id)->first();
        $this->assertNotNull($notification);
        $this->assertSame('visits', $notification->notification_type);
        $this->assertSame('visit_requests', $notification->related_record_type);
        $this->assertSame($visit->visit_request_id, $notification->related_record_id);
    }

    public function test_schedule_capacity_is_respected(): void
    {
        $this->schedule->update(['slots_taken' => 2, 'status' => 'full', 'max_capacity' => 2]);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('schedule_id');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_rejected_visitor_cannot_be_assigned(): void
    {
        $this->visitor->update(['verification_status' => 'rejected']);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertSessionHasErrors('visitor_id');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_inactive_pdl_is_rejected(): void
    {
        $this->pdl->update(['custody_status' => 'released']);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertSessionHasErrors('pdl_id');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_invalid_relationship_is_rejected(): void
    {
        $otherPdl = Pdl::create([
            'pdl_number' => 'PDL-TEST-002',
            'full_name' => 'Other PDL',
            'date_of_birth' => '1991-01-01',
            'gender' => 'female',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);

        $wrongRel = VisitorPdlRelationship::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $otherPdl->pdl_id,
            'relationship_type' => 'approved_relative',
            'priority_tier' => 'requires_verification',
            'verification_status' => 'verified',
        ]);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $wrongRel->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertSessionHasErrors('relationship_id');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_rejected_relationship_is_rejected(): void
    {
        $this->relationship->update(['verification_status' => 'rejected']);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertSessionHasErrors('relationship_id');
    }

    public function test_duplicate_assignment_is_rejected(): void
    {
        app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);

        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertSessionHasErrors('schedule_id');
        $this->assertSame(1, VisitRequest::count());
    }

    public function test_front_desk_cannot_assign_visits(): void
    {
        $response = $this->actingAs($this->frontDeskAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertForbidden();
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_visitor_cannot_assign_visits(): void
    {
        $response = $this->actingAs($this->visitorAccount)
            ->post(route('visitor.visits.assign', $this->visitor->visitor_id), [
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);

        $response->assertForbidden();
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_capacity_fills_after_second_assignment(): void
    {
        $secondAccount = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'carlo.ramos',
            'email' => 'carlo.ramos@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $secondVisitor = VisitorProfile::create([
            'account_id' => $secondAccount->account_id,
            'full_name' => 'Carlo J. Ramos',
            'date_of_birth' => '1979-11-02',
            'gender' => 'male',
            'contact_number' => '09285550199',
            'verification_status' => 'verified',
        ]);
        $secondRel = VisitorPdlRelationship::create([
            'visitor_id' => $secondVisitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'legal_counsel',
            'priority_tier' => 'high_priority',
            'verification_status' => 'verified',
        ]);

        $service = app(VisitAssignmentService::class);
        $service->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $service->assign([
            'visitor_id' => $secondVisitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $secondRel->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);

        $schedule = $this->schedule->fresh();
        $this->assertSame(2, $schedule->slots_taken);
        $this->assertSame('full', $schedule->status);

        $this->expectException(ValidationException::class);
        $service->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
    }
}
