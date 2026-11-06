<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PdlLinkRequest;
use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 5B, Stage 1: pdl_link_requests and the separation of a request's
 * documents from registration documents. No request workflow yet.
 */
class PdlLinkRequestFoundationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
    }

    private function request(VisitorProfile $visitor, array $attributes = []): PdlLinkRequest
    {
        return PdlLinkRequest::create($attributes + [
            'visitor_id' => $visitor->visitor_id,
            'pdl_name' => 'Juan Dela Cruz',
            'relationship_type' => 'parent',
        ]);
    }

    private function document(VisitorProfile $visitor, string $key, ?PdlLinkRequest $request = null): RelationshipDocument
    {
        return RelationshipDocument::create([
            'relationship_id' => null,
            'visitor_id' => $visitor->visitor_id,
            'pdl_link_request_id' => $request?->request_id,
            'requirement_key' => $key,
            'file_path' => "relationship-documents/{$key}.png",
            'original_name' => "{$key}.png",
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);
    }

    private function pendingVisitor(): VisitorProfile
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'ana.reyes',
            'email' => 'ana.reyes@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);

        return VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => 'Ana Reyes',
            'date_of_birth' => '1992-02-02',
            'contact_number' => '09171112222',
            'verification_status' => 'pending',
        ]);
    }

    public function test_a_new_request_is_open_and_holds_the_visitors_open_slot(): void
    {
        $request = $this->request($this->visitor, ['has_children_together' => false]);

        $request->refresh();
        $this->assertSame(PdlLinkRequest::STATUS_OPEN, $request->status);
        $this->assertTrue($request->isOpen());
        $this->assertSame($this->visitor->visitor_id, (int) $request->open_visitor_id);
        $this->assertFalse($request->has_children_together);
        $this->assertSame('Parent', $request->relationshipLabel());
        $this->assertTrue($request->visitor->is($this->visitor));
        $this->assertSame([$request->request_id], $this->visitor->pdlLinkRequests()->pluck('request_id')->all());
    }

    public function test_the_database_allows_only_one_open_request_per_visitor(): void
    {
        $first = $this->request($this->visitor);

        try {
            $this->request($this->visitor, ['pdl_name' => 'Another PDL']);
            $this->fail('A second open request must be refused.');
        } catch (QueryException) {
            // Expected: unique open_visitor_id.
        }

        // Closing frees the slot; closed requests never block each other.
        $first->update(PdlLinkRequest::closingAttributes(PdlLinkRequest::STATUS_CANCELLED));
        $this->assertNull($first->fresh()->open_visitor_id);
        $second = $this->request($this->visitor, ['pdl_name' => 'Another PDL']);
        $second->update(PdlLinkRequest::closingAttributes(PdlLinkRequest::STATUS_REJECTED));
        $this->request($this->visitor, ['pdl_name' => 'Third PDL']);

        $this->assertSame(1, PdlLinkRequest::open()->where('visitor_id', $this->visitor->visitor_id)->count());
        $this->assertSame(3, $this->visitor->pdlLinkRequests()->count());
    }

    public function test_a_request_links_to_its_documents_and_relationship(): void
    {
        $request = $this->request($this->visitor);
        $document = $this->document($this->visitor, 'pdl_birth_certificate', $request);

        $this->assertSame([$document->document_id], $request->documents()->pluck('document_id')->all());
        $this->assertTrue($document->pdlLinkRequest->is($request));

        $request->update(PdlLinkRequest::closingAttributes(PdlLinkRequest::STATUS_LINKED) + [
            'relationship_id' => $this->relationship->relationship_id,
        ]);
        $this->assertTrue($request->fresh()->relationship->is($this->relationship));
        $this->assertSame([$request->request_id], $this->relationship->pdlLinkRequests()->pluck('request_id')->all());
    }

    public function test_request_documents_are_not_registration_documents(): void
    {
        $registration = $this->document($this->visitor, 'pdl_birth_certificate');
        $requestDocument = $this->document($this->visitor, 'marriage_certificate', $this->request($this->visitor));

        $this->assertSame([$registration->document_id], $this->visitor->registrationDocuments()->pluck('document_id')->all());
        $this->assertSame(
            [$registration->document_id],
            RelationshipDocument::registrationUploads()->pluck('document_id')->all()
        );
        $this->assertNotContains($requestDocument->document_id, $this->visitor->fresh()->registrationDocuments->pluck('document_id'));
    }

    public function test_a_new_relationship_never_takes_a_request_document(): void
    {
        // Same requirement key on both: only the registration file may attach.
        $registration = $this->document($this->visitor, 'marriage_certificate');
        $request = $this->request($this->visitor, ['relationship_type' => 'spouse']);
        $requestDocument = $this->document($this->visitor, 'marriage_certificate', $request);

        $relationship = VisitorPdlRelationship::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->newPdlId(),
            'relationship_type' => 'spouse',
            'priority_tier' => VisitorPdlRelationship::priorityTierFor('spouse'),
            'verification_status' => 'pending',
        ]);

        $this->assertSame($relationship->relationship_id, $registration->fresh()->relationship_id);
        $this->assertNull($requestDocument->fresh()->relationship_id);
        $this->assertSame($request->request_id, $requestDocument->fresh()->pdl_link_request_id);
    }

    public function test_one_step_account_approval_ignores_request_documents(): void
    {
        $visitor = $this->pendingVisitor();
        VisitorId::create([
            'visitor_id' => $visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'N-1',
            'file_path' => 'visitor-ids/id.png', 'verification_status' => 'pending',
        ]);
        $registration = $this->document($visitor, 'pdl_birth_certificate');
        // Not possible through the app (requests need an approved account),
        // but approval must still never pick such a file up.
        $requestDocument = $this->document($visitor, 'pdl_birth_certificate', $this->request($visitor));

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->post("/visitors/{$visitor->visitor_id}/approve", ['pdl_id' => $this->pdl->pdl_id, 'relationship_type' => 'parent'])
            ->assertSessionHas('success');

        $relationship = $visitor->relationships()->sole();
        $this->assertSame('verified', $relationship->verification_status);
        $this->assertSame($relationship->relationship_id, $registration->fresh()->relationship_id);
        $this->assertSame('verified', $registration->fresh()->status);
        $this->assertNull($requestDocument->fresh()->relationship_id);
        $this->assertSame('pending', $requestDocument->fresh()->status);
    }

    public function test_documents_api_does_not_list_request_files_as_registration_documents(): void
    {
        $this->document($this->visitor, 'pdl_birth_certificate');
        $this->document($this->visitor, 'marriage_certificate', $this->request($this->visitor));

        Sanctum::actingAs($this->visitorAccount);
        $keys = collect($this->getJson('/api/documents')->assertOk()->json('registrationDocuments'))->pluck('key')->all();

        $this->assertSame(['pdl_birth_certificate'], $keys);
    }

    private function newPdlId(): int
    {
        return \App\Models\Pdl::create([
            'pdl_number' => 'PDL-TEST-002',
            'full_name' => 'Second PDL',
            'date_of_birth' => '1988-05-05',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => \App\Models\StaffProfile::first()->staff_id,
        ])->pdl_id;
    }
}
