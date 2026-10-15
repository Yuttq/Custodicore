<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Regression: relationship documents uploaded from the mobile app must land
 * in relationship_documents (POST /relationships/{id}/requirements/{key}),
 * which is what the Record Officer checklist reviews. Older uploads stored
 * on supporting_document_path stay viewable by staff.
 */
class RelationshipDocumentUploadFlowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Storage::fake('local');

        $this->relationship->update(['relationship_type' => 'spouse', 'verification_status' => 'pending', 'verified_by' => null, 'verified_at' => null]);
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'visitor-ids/x.png',
            'verification_status' => 'verified',
        ]);
    }

    private function uploadUrl(string $key = 'marriage_certificate'): string
    {
        return "/api/relationships/{$this->relationship->relationship_id}/requirements/{$key}";
    }

    private function upload(string $name = 'marriage.jpg')
    {
        return $this->post($this->uploadUrl(), [
            'file' => UploadedFile::fake()->create($name, 200, 'image/jpeg'),
        ], ['Accept' => 'application/json']);
    }

    private function showVisitor()
    {
        return $this->get("/visitors/{$this->visitor->visitor_id}");
    }

    public function test_app_upload_appears_on_the_officer_checklist_and_can_be_verified(): void
    {
        Sanctum::actingAs($this->visitorAccount);
        $this->upload()->assertCreated()
            ->assertJsonPath('requirements.1.key', 'marriage_certificate')
            ->assertJsonPath('requirements.1.status', 'pending_review');

        $doc = RelationshipDocument::sole();
        $this->assertSame($this->relationship->relationship_id, $doc->relationship_id);
        $this->assertSame('marriage_certificate', $doc->requirement_key);
        $this->assertSame('marriage.jpg', $doc->original_name);

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->showVisitor()->assertOk()
            ->assertSee('Marriage Certificate')
            ->assertSee('Awaiting review')
            ->assertSee(route('relationship-documents.file', $doc->document_id), false);
        $this->get("/relationship-documents/{$doc->document_id}/file")->assertOk();

        $this->post("/relationship-documents/{$doc->document_id}/verify")->assertSessionHas('success');
        $doc->refresh();
        $this->assertSame('verified', $doc->status);
        $this->assertSame($this->recordOfficerAccount->staffProfile->staff_id, $doc->reviewed_by);
        $this->assertNotNull($doc->reviewed_at);

        // Every requirement is met, so the relationship itself can be verified.
        $this->post("/visitors/{$this->visitor->visitor_id}/relationships/{$this->relationship->relationship_id}/verify")
            ->assertSessionHas('success');
        $this->assertSame('verified', $this->relationship->fresh()->verification_status);

        // The visitor sees the verified document, with its review date.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/documents')->assertOk()
            ->assertJsonPath('relationships.0.requirements.1.status', 'met')
            ->assertJsonPath('relationships.0.requirements.1.canUpload', false)
            ->assertJsonPath('relationships.0.requirements.1.reviewedAt', $doc->reviewed_at->toIso8601String());
    }

    public function test_rejected_upload_shows_the_reason_and_can_be_replaced(): void
    {
        Sanctum::actingAs($this->visitorAccount);
        $this->upload('first.jpg')->assertCreated();
        $first = RelationshipDocument::sole();

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->post("/relationship-documents/{$first->document_id}/reject", ['rejection_reason' => 'Photo is blurry'])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->recordOfficerAccount->staffProfile->staff_id, $first->fresh()->reviewed_by);

        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/documents')->assertOk()
            ->assertJsonPath('relationships.0.requirements.1.status', 'rejected')
            ->assertJsonPath('relationships.0.requirements.1.rejectionReason', 'Photo is blurry')
            ->assertJsonPath('relationships.0.requirements.1.canUpload', true);

        $this->travel(1)->minutes();
        $this->upload('second.jpg')->assertCreated()
            ->assertJsonPath('requirements.1.status', 'pending_review')
            ->assertJsonPath('requirements.1.rejectionReason', null);

        // History is kept; the newest upload is the one under review.
        $this->assertSame(2, RelationshipDocument::count());
        $this->assertSame('rejected', $first->fresh()->status);
        $second = RelationshipDocument::where('original_name', 'second.jpg')->sole();
        $this->assertSame('pending', $second->status);

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->showVisitor()->assertOk()
            ->assertSee(route('relationship-documents.file', $second->document_id), false);
        $this->post("/relationship-documents/{$second->document_id}/verify")->assertSessionHas('success');
        $this->assertSame('verified', $second->fresh()->status);
    }

    public function test_review_decisions_and_uploads_are_audit_logged(): void
    {
        Sanctum::actingAs($this->visitorAccount);
        $this->upload()->assertCreated();
        $doc = RelationshipDocument::sole();

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->post("/relationship-documents/{$doc->document_id}/reject", ['rejection_reason' => 'Wrong document']);

        $logs = AuditLog::where('record_type', 'relationship_documents')->where('record_id', $doc->document_id)->pluck('description');
        $this->assertTrue($logs->contains(fn ($d) => str_contains($d, 'Visitor uploaded Marriage Certificate')));
        $this->assertTrue($logs->contains(fn ($d) => str_contains($d, 'Rejected Marriage Certificate') && str_contains($d, 'Wrong document')));
    }

    public function test_a_visitor_cannot_upload_to_another_visitors_relationship(): void
    {
        $otherAccount = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'other.visitor',
            'email' => 'other.visitor@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $otherAccount->forceFill(['email_verified_at' => now()])->save();
        VisitorProfile::create([
            'account_id' => $otherAccount->account_id,
            'full_name' => 'Other Visitor',
            'date_of_birth' => '1980-01-01',
            'gender' => 'male',
            'contact_number' => '09170000000',
            'verification_status' => 'verified',
        ]);

        Sanctum::actingAs($otherAccount);
        $this->upload()->assertNotFound();
        $this->assertSame(0, RelationshipDocument::count());
    }

    public function test_upload_validates_the_file(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $this->post($this->uploadUrl(), [], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post($this->uploadUrl(), ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post($this->uploadUrl(), ['file' => UploadedFile::fake()->create('big.jpg', 10241, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, RelationshipDocument::count());
    }

    public function test_legacy_supporting_document_stays_viewable_by_staff_only(): void
    {
        Storage::disk('local')->put('supporting-documents/old.png', 'img');
        $this->relationship->update(['supporting_document_path' => 'supporting-documents/old.png']);
        $legacyUrl = route('visitor.relationships.legacy-file', [$this->visitor->visitor_id, $this->relationship->relationship_id]);

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->showVisitor()->assertOk()->assertSee($legacyUrl, false);
        $this->get($legacyUrl)->assertOk();

        $this->actingAs($this->frontDeskAccount, 'web');
        $this->get($legacyUrl)->assertForbidden();

        // A legacy file does not count toward the checklist on its own.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/documents')->assertOk()
            ->assertJsonPath('relationships.0.hasSupportingDocument', true)
            ->assertJsonPath('relationships.0.requirements.1.status', 'missing');
    }
}
