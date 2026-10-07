<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Notification;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 4 visitor API: history, timeline, notifications, profile,
 * government ID / supporting documents, verification status.
 */
class VisitorPhase4ApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private Account $otherAccount;
    private VisitorProfile $otherVisitor;
    private VisitorPdlRelationship $otherRelationship;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();

        $this->otherAccount = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'carlo.ramos',
            'email' => 'carlo.ramos@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->otherVisitor = VisitorProfile::create([
            'account_id' => $this->otherAccount->account_id,
            'full_name' => 'Carlo Ramos',
            'date_of_birth' => '1990-02-02',
            'contact_number' => '09170000002',
            'verification_status' => 'pending',
        ]);
        $this->otherRelationship = VisitorPdlRelationship::create([
            'visitor_id' => $this->otherVisitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'approved_relative',
            'priority_tier' => 'requires_verification',
            'verification_status' => 'pending',
        ]);
    }

    private function assignVisit(): VisitRequest
    {
        return app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
    }

    private function otherVisitorVisit(string $status = 'completed'): VisitRequest
    {
        return VisitRequest::create([
            'visitor_id' => $this->otherVisitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->otherRelationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
            'status' => $status,
        ]);
    }

    // ---------------------------------------------------------------- History

    public function test_visitor_can_retrieve_own_history_with_final_statuses_only(): void
    {
        $completed = $this->assignVisit();
        $completed->update(['status' => 'completed']);

        Sanctum::actingAs($this->visitorAccount);

        $this->getJson('/api/visits/history')
            ->assertOk()
            ->assertJsonCount(1, 'visits')
            ->assertJsonPath('visits.0.id', (string) $completed->visit_request_id)
            ->assertJsonPath('visits.0.status', 'completed');

        // An active (pending_confirmation) visit is not history.
        $completed->update(['status' => 'pending_confirmation']);
        $this->getJson('/api/visits/history')->assertOk()->assertJsonCount(0, 'visits');
    }

    public function test_visitor_cannot_retrieve_another_visitors_history(): void
    {
        $this->otherVisitorVisit('completed');

        Sanctum::actingAs($this->visitorAccount);

        $this->getJson('/api/visits/history')->assertOk()->assertJsonCount(0, 'visits');
    }

    // --------------------------------------------------------------- Timeline

    public function test_visitor_can_retrieve_own_timeline(): void
    {
        $visit = $this->assignVisit();

        Sanctum::actingAs($this->visitorAccount);

        $response = $this->getJson("/api/schedules/{$visit->visit_request_id}/timeline")->assertOk();
        $steps = collect($response->json('steps'))->keyBy('id');

        $this->assertSame('completed', $steps['schedule_assigned']['stepState']);
        $this->assertNotNull($steps['schedule_assigned']['occurredAt']);
        $this->assertNull($steps['attendance_confirmed']['occurredAt']);
    }

    public function test_timeline_works_when_relationship_was_verified_by_staff(): void
    {
        // verified_at on visitor_pdl_relationships is not date-cast (string) —
        // the timeline used to 500 on any staff-verified relationship.
        $this->relationship->update(['verified_at' => now()->subDay()]);
        $visit = $this->assignVisit();

        Sanctum::actingAs($this->visitorAccount);

        $steps = collect($this->getJson("/api/schedules/{$visit->visit_request_id}/timeline")
            ->assertOk()->json('steps'))->keyBy('id');

        $this->assertSame('completed', $steps['relationship_verified']['stepState']);
        $this->assertNotNull($steps['relationship_verified']['occurredAt']);
    }

    public function test_declined_visit_timeline_has_real_declined_event(): void
    {
        $visit = $this->assignVisit();
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson("/api/schedules/{$visit->visit_request_id}/decline", ['reason' => 'Sick'])->assertOk();

        $steps = collect($this->getJson("/api/schedules/{$visit->visit_request_id}/timeline")->json('steps'))->keyBy('id');

        $this->assertSame('completed', $steps['visit_declined']['stepState']);
        $this->assertSame('Sick', $steps['visit_declined']['description']);
        $this->assertNotNull($steps['visit_declined']['occurredAt']);
    }

    public function test_visitor_cannot_retrieve_another_visitors_timeline(): void
    {
        $others = $this->otherVisitorVisit();

        Sanctum::actingAs($this->visitorAccount);

        $this->getJson("/api/schedules/{$others->visit_request_id}/timeline")->assertNotFound();
    }

    // ---------------------------------------------------------- Notifications

    public function test_visitor_can_retrieve_own_notifications_and_mark_read(): void
    {
        $this->assignVisit(); // creates a real "visits" notification

        Sanctum::actingAs($this->visitorAccount);

        $list = $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.category', 'visits')
            ->assertJsonPath('notifications.0.read', false);

        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJson(['unreadCount' => 1]);

        $id = $list->json('notifications.0.id');
        $this->patchJson("/api/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('notification.read', true);

        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJson(['unreadCount' => 0]);
    }

    public function test_visitor_cannot_retrieve_or_read_another_visitors_notifications(): void
    {
        $theirs = Notification::notify($this->otherAccount->account_id, 'visits', 'Their visit', 'Not yours');

        Sanctum::actingAs($this->visitorAccount);

        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'notifications');
        $this->patchJson("/api/notifications/{$theirs->notification_id}/read")->assertNotFound();
        $this->assertFalse($theirs->fresh()->is_read);
    }

    // ---------------------------------------------------------------- Profile

    public function test_visitor_can_retrieve_own_profile(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('email', 'maria.santos@example.com')
            ->assertJsonPath('fullName', 'Maria D. Santos')
            ->assertJsonPath('dateOfBirth', '1985-03-14')
            ->assertJsonPath('gender', 'female')
            ->assertJsonPath('contactNumber', '09171234567')
            ->assertJsonPath('verificationStatus', 'verified');
    }

    public function test_visitor_can_update_allowed_profile_fields(): void
    {
        Sanctum::actingAs($this->otherAccount); // pending visitor

        $this->patchJson('/api/me', [
            'fullName' => 'Carlo M. Ramos',
            'dateOfBirth' => '1990-03-03',
            'gender' => 'male',
            'address' => 'Pasig City',
            'contactNumber' => '09179998888',
        ])
            ->assertOk()
            ->assertJsonPath('fullName', 'Carlo M. Ramos')
            ->assertJsonPath('address', 'Pasig City')
            ->assertJsonPath('gender', 'male')
            ->assertJsonPath('verificationStatus', 'pending');

        $this->assertDatabaseHas('visitor_profiles', [
            'visitor_id' => $this->otherVisitor->visitor_id,
            'full_name' => 'Carlo M. Ramos',
            'contact_number' => '09179998888',
            'gender' => 'male',
        ]);
    }

    public function test_profile_update_rejects_invalid_data(): void
    {
        Sanctum::actingAs($this->otherAccount);

        $this->patchJson('/api/me', ['dateOfBirth' => '03/03/1990'])->assertUnprocessable()->assertJsonValidationErrors('dateOfBirth');
        $this->patchJson('/api/me', ['dateOfBirth' => now()->addDay()->toDateString()])->assertUnprocessable();
        $this->patchJson('/api/me', ['gender' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('gender');
        $this->patchJson('/api/me', ['gender' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('gender');
        $this->patchJson('/api/me', ['gender' => 'Prefer not to say'])->assertUnprocessable()->assertJsonValidationErrors('gender');
        $this->patchJson('/api/me', ['gender' => null])->assertUnprocessable()->assertJsonValidationErrors('gender');
        $this->patchJson('/api/me', ['fullName' => ''])->assertUnprocessable()->assertJsonValidationErrors('fullName');
    }

    public function test_visitor_cannot_modify_restricted_fields(): void
    {
        Sanctum::actingAs($this->otherAccount);

        foreach ([
            ['verificationStatus' => 'verified'],
            ['verification_status' => 'verified'],
            ['email' => 'hacker@example.com'],
            ['role' => 'Record Officer'],
            ['status' => 'active'],
        ] as $payload) {
            $this->patchJson('/api/me', $payload + ['address' => 'Somewhere'])->assertUnprocessable();
        }

        $this->assertDatabaseHas('visitor_profiles', [
            'visitor_id' => $this->otherVisitor->visitor_id,
            'verification_status' => 'pending',
            'address' => null,
        ]);
        $this->assertSame('carlo.ramos@example.com', $this->otherAccount->fresh()->email);
    }

    public function test_verified_visitor_cannot_change_name_but_can_change_contact(): void
    {
        Sanctum::actingAs($this->visitorAccount); // verified

        $this->patchJson('/api/me', ['fullName' => 'Someone Else'])->assertStatus(409);
        $this->assertSame('Maria D. Santos', $this->visitor->fresh()->full_name);

        $this->patchJson('/api/me', ['contactNumber' => '09175550000'])->assertOk()
            ->assertJsonPath('contactNumber', '09175550000');
    }

    public function test_staff_account_cannot_use_visitor_profile_update(): void
    {
        Sanctum::actingAs($this->recordOfficerAccount);

        $this->patchJson('/api/me', ['address' => 'X'])->assertForbidden();
    }

    // -------------------------------------------------------------- Documents

    public function test_visitor_can_submit_allowed_government_id(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->otherAccount);

        $response = $this->postJson('/api/documents', [
            'documentType' => 'passport',
            'file' => UploadedFile::fake()->create('passport.png', 200, 'image/png'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('documentType', 'passport')
            ->assertJsonPath('status', 'pending')
            ->assertJsonMissingPath('filePath');

        $doc = VisitorId::where('visitor_id', $this->otherVisitor->visitor_id)->firstOrFail();
        Storage::disk('public')->assertExists($doc->file_path);

        // Display labels are accepted and mapped to the stored key.
        $this->postJson('/api/documents', [
            'documentType' => "Driver's License",
            'file' => UploadedFile::fake()->create('license.pdf', 200, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('documentType', 'drivers_license');
    }

    public function test_invalid_document_uploads_are_rejected(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->otherAccount);

        $this->postJson('/api/documents', [
            'documentType' => 'library_card',
            'file' => UploadedFile::fake()->create('id.png', 100, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('documentType');

        $this->postJson('/api/documents', [
            'documentType' => 'passport',
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->postJson('/api/documents', [
            'documentType' => 'passport',
            'file' => UploadedFile::fake()->create('huge.png', 10241, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->postJson('/api/documents', ['documentType' => 'passport'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, VisitorId::count());
    }

    public function test_documents_list_only_contains_own_documents(): void
    {
        VisitorId::create([
            'visitor_id' => $this->otherVisitor->visitor_id,
            'id_type' => 'umid',
            'id_number' => 'X-1',
            'file_path' => 'visitor-ids/x.png',
            'verification_status' => 'pending',
        ]);

        Sanctum::actingAs($this->visitorAccount);

        $response = $this->getJson('/api/documents')->assertOk()
            ->assertJsonPath('verificationStatus', 'verified')
            ->assertJsonCount(0, 'governmentIds')
            ->assertJsonCount(1, 'relationships')
            ->assertJsonPath('relationships.0.id', (string) $this->relationship->relationship_id);

        $this->assertStringNotContainsString('visitor-ids/', $response->getContent());
        $this->assertStringNotContainsString('X-1', $response->getContent());
    }

    public function test_visitor_can_upload_supporting_document_to_own_relationship_only(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->otherAccount);

        $this->postJson("/api/relationships/{$this->otherRelationship->relationship_id}/supporting-document", [
            'file' => UploadedFile::fake()->create('birth-cert.pdf', 300, 'application/pdf'),
        ])
            ->assertCreated()
            ->assertJsonPath('hasSupportingDocument', true)
            ->assertJsonPath('status', 'pending');

        $path = $this->otherRelationship->fresh()->supporting_document_path;
        Storage::disk('local')->assertExists($path);

        // Maria's relationship belongs to another visitor.
        $this->postJson("/api/relationships/{$this->relationship->relationship_id}/supporting-document", [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertNotFound();
        $this->assertNull($this->relationship->fresh()->supporting_document_path);
    }

    public function test_verified_relationship_supporting_document_cannot_be_replaced(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->visitorAccount); // Maria's relationship is verified

        $this->postJson("/api/relationships/{$this->relationship->relationship_id}/supporting-document", [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertStatus(409);
    }

    public function test_visitor_cannot_change_own_verification_status_through_uploads(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Sanctum::actingAs($this->otherAccount);

        $this->postJson('/api/documents', [
            'documentType' => 'national_id',
            'verification_status' => 'verified',
            'status' => 'verified',
            'file' => UploadedFile::fake()->create('id.png', 100, 'image/png'),
        ])->assertCreated()->assertJsonPath('status', 'pending');

        $this->postJson("/api/relationships/{$this->otherRelationship->relationship_id}/supporting-document", [
            'verification_status' => 'verified',
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('status', 'pending');

        $this->assertSame('pending', VisitorId::first()->verification_status);
        $this->assertSame('pending', $this->otherRelationship->fresh()->verification_status);
        $this->assertSame('pending', $this->otherVisitor->fresh()->verification_status);
    }

    public function test_staff_account_cannot_use_visitor_document_endpoints(): void
    {
        Sanctum::actingAs($this->frontDeskAccount);

        $this->getJson('/api/documents')->assertForbidden();
    }
}
