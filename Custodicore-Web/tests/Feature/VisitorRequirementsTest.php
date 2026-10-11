<?php

namespace Tests\Feature;

use App\Models\EligibilityAssessment;
use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Services\RelationshipRequirements;
use App\Services\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * BJMP visitor requirements: checklist rules per relationship, visitor
 * uploads from the app, Record Officer review, and the approval gates.
 */
class VisitorRequirementsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        $this->relationship->update(['relationship_type' => 'spouse', 'verification_status' => 'pending', 'verified_by' => null, 'verified_at' => null]);
    }

    private function keys(VisitorPdlRelationship $relationship): array
    {
        return array_column(RelationshipRequirements::for($relationship->fresh()), 'key');
    }

    private function verifiedId(): void
    {
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'visitor-ids/x.png',
            'verification_status' => 'verified',
        ]);
    }

    private function document(string $key, string $status = 'pending'): RelationshipDocument
    {
        return RelationshipDocument::create([
            'relationship_id' => $this->relationship->relationship_id,
            'requirement_key' => $key,
            'file_path' => "relationship-documents/{$key}.png",
            'status' => $status,
            'uploaded_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // Checklist rules
    // -----------------------------------------------------------------
    public function test_checklist_per_relationship_type(): void
    {
        $expect = [
            'spouse' => ['valid_id', 'marriage_certificate'],
            'child' => ['valid_id', 'visitor_birth_certificate'],
            'parent' => ['valid_id', 'pdl_birth_certificate'],
            'sibling' => ['valid_id', 'visitor_birth_certificate', 'pdl_birth_certificate'],
            'legal_guardian' => ['valid_id', 'guardianship_authorization', 'no_immediate_family'],
            'extended_relative' => ['valid_id', 'official_authorization'],
            'legal_counsel' => ['valid_id', 'proof_of_counsel'],
        ];

        foreach ($expect as $type => $keys) {
            $this->relationship->update(['relationship_type' => $type]);
            $this->assertSame($keys, $this->keys($this->relationship), $type);
        }
    }

    public function test_live_in_partner_depends_on_children_together(): void
    {
        $this->relationship->update(['relationship_type' => 'live_in_partner', 'has_children_together' => null]);
        $this->assertSame(['valid_id', 'children_together', 'cenomar_visitor', 'cenomar_pdl'], $this->keys($this->relationship));

        $this->relationship->update(['has_children_together' => true]);
        $this->assertSame(['valid_id', 'cenomar_visitor', 'cenomar_pdl', 'child_birth_certificate'], $this->keys($this->relationship));

        $this->relationship->update(['has_children_together' => false]);
        $this->assertSame(['valid_id', 'cenomar_visitor', 'cenomar_pdl', 'witness_statement'], $this->keys($this->relationship));
    }

    public function test_minor_needs_birth_certificate_and_guardian_and_must_be_at_least_three(): void
    {
        $this->relationship->update(['relationship_type' => 'child']);
        $this->visitor->update(['date_of_birth' => now()->subYears(10)->toDateString()]);
        // Child + minor share the visitor's birth certificate; no valid ID for minors.
        $this->assertSame(['visitor_birth_certificate', 'accompanying_guardian'], $this->keys($this->relationship));

        $this->visitor->update(['date_of_birth' => now()->subYears(2)->toDateString()]);
        $items = RelationshipRequirements::for($this->relationship->fresh());
        $this->assertSame('minimum_age', $items[0]['key']);
        $this->assertSame('blocked', $items[0]['status']);
    }

    public function test_legacy_type_must_be_reclassified(): void
    {
        $this->relationship->update(['relationship_type' => 'immediate_family']);
        $items = RelationshipRequirements::for($this->relationship->fresh());

        $this->assertSame('reclassify', $items[0]['key']);
        $this->assertFalse(RelationshipRequirements::allMet($items));
    }

    public function test_statuses_follow_latest_upload(): void
    {
        $this->verifiedId();
        $this->document('marriage_certificate', 'rejected');
        $this->assertFalse(RelationshipRequirements::allMet(RelationshipRequirements::for($this->relationship->fresh())));

        $this->travel(1)->minutes();
        $this->document('marriage_certificate', 'verified');
        $this->assertTrue(RelationshipRequirements::allMet(RelationshipRequirements::for($this->relationship->fresh())));
    }

    // -----------------------------------------------------------------
    // Mobile API
    // -----------------------------------------------------------------
    public function test_documents_endpoint_lists_requirements(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $this->getJson('/api/documents')
            ->assertOk()
            ->assertJsonPath('relationships.0.relationshipLabel', 'Spouse')
            ->assertJsonPath('relationships.0.requirements.1.key', 'marriage_certificate')
            ->assertJsonPath('relationships.0.requirements.1.status', 'missing')
            ->assertJsonPath('relationships.0.requirements.1.canUpload', true)
            ->assertJsonPath('relationships.0.requirements.0.canUpload', false);
    }

    public function test_visitor_uploads_a_required_document(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->visitorAccount);

        $this->post("/api/relationships/{$this->relationship->relationship_id}/requirements/marriage_certificate", [
            'file' => UploadedFile::fake()->create('marriage.jpg', 200, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('requirements.1.status', 'pending_review')
            ->assertJsonMissingPath('requirements.1.filePath');

        $doc = RelationshipDocument::firstOrFail();
        $this->assertSame('pending', $doc->status);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_upload_rejects_unrequired_keys_and_locked_requirements(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->visitorAccount);
        $file = fn () => ['file' => UploadedFile::fake()->create('x.jpg', 50, 'image/jpeg')];
        $url = "/api/relationships/{$this->relationship->relationship_id}/requirements";

        // Spouses don't need a CENOMAR.
        $this->post("{$url}/cenomar_visitor", $file(), ['Accept' => 'application/json'])->assertStatus(422);

        $this->relationship->update(['verification_status' => 'verified']);
        $this->post("{$url}/marriage_certificate", $file(), ['Accept' => 'application/json'])->assertStatus(409);

        $this->relationship->update(['verification_status' => 'pending']);
        $this->document('marriage_certificate', 'verified');
        $this->post("{$url}/marriage_certificate", $file(), ['Accept' => 'application/json'])->assertStatus(409);

        $this->assertSame(1, RelationshipDocument::count());
    }

    // -----------------------------------------------------------------
    // Record Officer review + gates
    // -----------------------------------------------------------------
    public function test_officer_reviews_documents_and_verify_unlocks_when_all_met(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('relationship-documents/marriage_certificate.png', 'img');
        $this->actingAs($this->recordOfficerAccount, 'web');
        $doc = $this->document('marriage_certificate');

        $this->get("/visitors/{$this->visitor->visitor_id}")
            ->assertOk()
            ->assertSee('Requirements for Spouse')
            ->assertSee('Marriage Certificate')
            ->assertSee('Awaiting review');

        $this->get("/relationship-documents/{$doc->document_id}/file")->assertOk();

        $this->post("/relationship-documents/{$doc->document_id}/reject", ['rejection_reason' => 'Blurry photo'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $doc->fresh()->status);

        $verifyUrl = "/visitors/{$this->visitor->visitor_id}/relationships/{$this->relationship->relationship_id}/verify";
        $this->post($verifyUrl)->assertSessionHas('error');

        // A reviewed document can't be reviewed again; the visitor uploads a new one.
        $this->post("/relationship-documents/{$doc->document_id}/verify")->assertSessionHas('error');
        $this->travel(1)->minutes();
        $newDoc = $this->document('marriage_certificate');

        $this->verifiedId();
        $this->post("/relationship-documents/{$newDoc->document_id}/verify");
        $this->post($verifyUrl)->assertSessionHas('success');
        $this->assertSame('verified', $this->relationship->fresh()->verification_status);
    }

    public function test_guardian_confirmation_for_minor_requires_a_name(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->visitor->update(['date_of_birth' => now()->subYears(8)->toDateString()]);
        $url = "/visitors/{$this->visitor->visitor_id}/relationships/{$this->relationship->relationship_id}/confirmations/accompanying_guardian";

        $this->post($url, [])->assertSessionHasErrors('note');
        $this->post($url, ['note' => 'Ana Santos (mother)'])->assertSessionHasNoErrors();

        $item = collect(RelationshipRequirements::for($this->relationship->fresh()))->firstWhere('key', 'accompanying_guardian');
        $this->assertSame('met', $item['status']);
        $this->assertSame('Ana Santos (mother)', $item['note']);
    }

    public function test_reclassifying_a_verified_relationship_sends_it_back_to_pending(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->relationship->update(['relationship_type' => 'immediate_family', 'verification_status' => 'verified']);

        $this->patch("/visitors/{$this->visitor->visitor_id}/relationships/{$this->relationship->relationship_id}", [
            'relationship_type' => 'live_in_partner',
            'has_children_together' => '0',
        ])->assertSessionHasNoErrors();

        $rel = $this->relationship->fresh();
        $this->assertSame('live_in_partner', $rel->relationship_type);
        $this->assertFalse($rel->has_children_together);
        $this->assertSame('pending', $rel->verification_status);
        $this->assertSame('requires_verification', $rel->priority_tier);
    }

    public function test_eligibility_approval_is_blocked_until_requirements_are_met(): void
    {
        $this->relationship->update(['verification_status' => 'verified']);
        $visit = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $assessment = EligibilityAssessment::updateOrCreate(['visit_request_id' => $visit->visit_request_id], [
            'identity_check_result' => 'flagged',
            'relationship_check_result' => 'pass',
            'history_check_result' => 'clean',
            'pdl_restriction_check_result' => 'no_restriction',
            'overall_result' => 'flagged_for_review',
            'flagged_reason' => 'Identity not yet verified.',
        ]);

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->get('/eligibility')->assertOk()->assertSee('0 of 2 met')->assertSee('Marriage Certificate');

        $this->post("/eligibility/{$assessment->assessment_id}/review", ['decision' => 'eligible'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Marriage Certificate'));
        $this->assertSame('flagged_for_review', $assessment->fresh()->overall_result);

        $this->verifiedId();
        $this->document('marriage_certificate', 'verified');
        $this->post("/eligibility/{$assessment->assessment_id}/review", ['decision' => 'eligible'])->assertSessionHas('success');
        $this->assertSame('eligible', $assessment->fresh()->overall_result);
    }

    public function test_other_roles_cannot_review_documents(): void
    {
        $doc = $this->document('marriage_certificate');
        $this->actingAs($this->frontDeskAccount, 'web');

        $this->post("/relationship-documents/{$doc->document_id}/verify")->assertForbidden();
        $this->get("/relationship-documents/{$doc->document_id}/file")->assertForbidden();
    }
}
