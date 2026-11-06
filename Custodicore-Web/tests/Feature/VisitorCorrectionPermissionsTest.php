<?php

namespace Tests\Feature;

use App\Models\Pdl;
use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Before approval a visitor may only replace what staff asked them to
 * correct, and the API enforces it (not just the app's buttons):
 *   - photo: only while the account is returned for correction (or none yet);
 *   - government ID: never over an approved one; over one waiting for review
 *     only when the account was returned;
 *   - checklist documents: only missing or rejected ones, per relationship;
 *   - the legacy supporting-document endpoint is closed before approval.
 * Approved accounts keep their existing rules.
 */
class VisitorCorrectionPermissionsTest extends TestCase
{
    use MakesTestImages;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function asVisitorWithStatus(string $status): void
    {
        $this->visitor->update([
            'verification_status' => $status,
            'rejection_reason' => $status === 'rejected' ? 'Please retake your photo.' : null,
        ]);
        Sanctum::actingAs($this->visitorAccount);
    }

    private function giveId(string $status): VisitorId
    {
        return VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'N-1',
            'file_path' => 'visitor-ids/id.png', 'verification_status' => $status,
        ]);
    }

    private function uploadId()
    {
        return $this->post('/api/documents', ['documentType' => 'national_id', 'file' => $this->fakePhoto('id.png')],
            ['Accept' => 'application/json']);
    }

    private function retakePhoto()
    {
        return $this->post('/api/me/photo', ['photo' => $this->fakePhoto()], ['Accept' => 'application/json']);
    }

    private function checklistDocument(VisitorPdlRelationship $relationship, string $status): RelationshipDocument
    {
        return RelationshipDocument::create([
            'relationship_id' => $relationship->relationship_id, 'visitor_id' => $this->visitor->visitor_id,
            'requirement_key' => 'marriage_certificate', 'file_path' => 'relationship-documents/m.png',
            'original_name' => 'm.png', 'status' => $status,
            'rejection_reason' => $status === 'rejected' ? 'The seal is not readable.' : null,
            'uploaded_at' => now()->subDay(),
        ]);
    }

    private function uploadChecklist(VisitorPdlRelationship $relationship)
    {
        return $this->post("/api/relationships/{$relationship->relationship_id}/requirements/marriage_certificate",
            ['file' => $this->fakePhoto('cert.png')], ['Accept' => 'application/json']);
    }

    // ---------------------------------------------------------------
    // Photo
    // ---------------------------------------------------------------

    public function test_photo_retake_is_refused_while_the_registration_is_under_review(): void
    {
        $this->visitor->update(['photo_path' => 'visitor-photos/current.png']);
        $this->asVisitorWithStatus('pending');

        $this->getJson('/api/documents')->assertJsonPath('canReplacePhoto', false);
        $this->retakePhoto()->assertStatus(409);
        $this->assertSame('visitor-photos/current.png', $this->visitor->fresh()->photo_path);
    }

    public function test_returned_account_retakes_once_then_the_photo_is_locked_again(): void
    {
        $this->visitor->update(['photo_path' => 'visitor-photos/current.png']);
        $this->asVisitorWithStatus('rejected');

        $this->getJson('/api/documents')->assertJsonPath('canReplacePhoto', true);
        $this->retakePhoto()->assertCreated();
        $this->assertSame('pending', $this->visitor->fresh()->verification_status);

        // The replacement is waiting for review: no further retakes.
        Sanctum::actingAs($this->visitorAccount->fresh()); // a new app request loads the account fresh
        $this->getJson('/api/documents')->assertJsonPath('canReplacePhoto', false);
        $this->retakePhoto()->assertStatus(409);
    }

    // ---------------------------------------------------------------
    // Government ID
    // ---------------------------------------------------------------

    public function test_id_waiting_for_review_cannot_be_replaced_on_a_pending_account(): void
    {
        $this->giveId('pending');
        $this->asVisitorWithStatus('pending');

        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', false);
        $this->uploadId()->assertStatus(409);
        $this->assertSame(1, VisitorId::where('visitor_id', $this->visitor->visitor_id)->count());
    }

    public function test_approved_id_cannot_be_replaced_before_account_approval(): void
    {
        $this->giveId('verified');
        $this->asVisitorWithStatus('rejected');

        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', false);
        $this->uploadId()->assertStatus(409);
        $this->assertSame('rejected', $this->visitor->fresh()->verification_status);
    }

    public function test_rejected_id_can_be_replaced_and_the_replacement_is_locked(): void
    {
        $this->giveId('rejected');
        $this->asVisitorWithStatus('pending');

        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', true);
        $this->uploadId()->assertCreated()->assertJsonPath('status', 'pending');

        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', false);
        $this->uploadId()->assertStatus(409);
    }

    public function test_returned_account_can_replace_an_id_waiting_for_review(): void
    {
        $this->giveId('pending');
        $this->asVisitorWithStatus('rejected');

        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', true);
        $this->uploadId()->assertCreated();
        $this->assertSame('pending', $this->visitor->fresh()->verification_status);
        // Back under review: locked again.
        $this->uploadId()->assertStatus(409);
    }

    public function test_approved_account_keeps_its_id_upload_rule(): void
    {
        $this->giveId('verified');
        Sanctum::actingAs($this->visitorAccount); // fixture visitor is approved

        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', true);
        $this->uploadId()->assertCreated();
    }

    // ---------------------------------------------------------------
    // Checklist documents, per PDL relationship
    // ---------------------------------------------------------------

    public function test_only_the_rejected_document_of_the_right_pdl_can_be_replaced(): void
    {
        $this->asVisitorWithStatus('pending');
        $relA = $this->relationship;
        $relA->update(['relationship_type' => 'spouse', 'verification_status' => 'pending', 'verified_by' => null, 'verified_at' => null]);

        $pdlB = Pdl::create([
            'pdl_number' => 'PDL-TEST-002', 'full_name' => 'Second PDL', 'date_of_birth' => '1991-01-01',
            'gender' => 'male', 'classification' => 'non_drug_related', 'admission_date' => '2024-01-01',
            'custody_status' => 'active', 'registered_by' => $this->pdl->registered_by,
        ]);
        $relB = VisitorPdlRelationship::create([
            'visitor_id' => $this->visitor->visitor_id, 'pdl_id' => $pdlB->pdl_id,
            'relationship_type' => 'spouse', 'priority_tier' => 1, 'verification_status' => 'pending',
        ]);

        $this->checklistDocument($relA, 'rejected');
        $this->checklistDocument($relB, 'pending');

        $requirement = fn ($json, string $pdlName) => collect($json['relationships'])
            ->firstWhere('pdlName', $pdlName)['requirements'];
        $marriage = fn (array $requirements) => collect($requirements)->firstWhere('key', 'marriage_certificate');

        $json = $this->getJson('/api/documents')->assertOk()->json();
        $a = $marriage($requirement($json, 'Example PDL'));
        $b = $marriage($requirement($json, 'Second PDL'));
        $this->assertSame(['rejected', true, 'The seal is not readable.'], [$a['status'], $a['canUpload'], $a['rejectionReason']]);
        $this->assertSame(['pending_review', false, null], [$b['status'], $b['canUpload'], $b['rejectionReason']]);

        // PDL B's document is under review: refused, nothing stored.
        $this->uploadChecklist($relB)->assertStatus(409);
        $this->assertSame(1, RelationshipDocument::where('relationship_id', $relB->relationship_id)->count());

        // PDL A's rejected document: replaced, and now locked under review.
        $this->uploadChecklist($relA)->assertCreated();
        $this->assertSame(2, RelationshipDocument::where('relationship_id', $relA->relationship_id)->count());
        $this->uploadChecklist($relA)->assertStatus(409);
    }

    public function test_verified_checklist_document_cannot_be_replaced_before_approval(): void
    {
        $this->asVisitorWithStatus('rejected');
        $this->relationship->update(['relationship_type' => 'spouse', 'verification_status' => 'pending', 'verified_by' => null, 'verified_at' => null]);
        $this->checklistDocument($this->relationship, 'verified');

        $this->uploadChecklist($this->relationship)->assertStatus(409);
        $this->assertSame('rejected', $this->visitor->fresh()->verification_status);
    }

    public function test_approved_account_can_still_swap_a_document_waiting_for_review(): void
    {
        Sanctum::actingAs($this->visitorAccount); // approved
        $this->relationship->update(['relationship_type' => 'spouse', 'verification_status' => 'pending', 'verified_by' => null, 'verified_at' => null]);
        $this->checklistDocument($this->relationship, 'pending');

        $this->uploadChecklist($this->relationship)->assertCreated();
    }

    // ---------------------------------------------------------------
    // Legacy endpoint
    // ---------------------------------------------------------------

    public function test_legacy_supporting_document_upload_is_closed_before_approval(): void
    {
        $this->asVisitorWithStatus('pending');
        $this->relationship->update(['verification_status' => 'pending', 'verified_by' => null, 'verified_at' => null]);

        $this->post("/api/relationships/{$this->relationship->relationship_id}/supporting-document",
            ['file' => $this->fakePhoto('doc.png')], ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertNull($this->relationship->fresh()->supporting_document_path);
    }
}
