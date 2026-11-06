<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PdlLinkRequest;
use App\Models\RelationshipDocument;
use App\Models\VisitorProfile;
use App\Services\PdlLinkRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 5B, Stage 2: the visitor's request API (submit / cancel), the
 * request's document lifecycle, and `pdlRequest` in GET /api/documents.
 */
class PdlLinkRequestApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        Storage::fake('local');
        Sanctum::actingAs($this->visitorAccount);
    }

    private function file(string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    /** A parent request with its one required document (adult visitor). */
    private function parentRequest(array $overrides = []): array
    {
        return $overrides + [
            'pdlName' => 'Juan Dela Cruz',
            'relationshipType' => 'parent',
            'documents' => ['pdl_birth_certificate' => $this->file('birth.pdf')],
        ];
    }

    private function submit(array $body)
    {
        return $this->post('/api/pdl-requests', $body, ['Accept' => 'application/json']);
    }

    private function storedFiles(): array
    {
        return Storage::disk('local')->allFiles(PdlLinkRequestService::FOLDER);
    }

    private function approvedVisitor(string $username): VisitorProfile
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => $username,
            'email' => "{$username}@example.com",
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $account->forceFill(['email_verified_at' => now()])->save();

        return VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => ucfirst($username) . ' Visitor',
            'date_of_birth' => '1990-01-01',
            'contact_number' => '09170000001',
            'verification_status' => 'verified',
        ]);
    }

    public function test_an_approved_visitor_submits_a_request_with_its_documents(): void
    {
        $response = $this->submit($this->parentRequest())->assertCreated();

        $request = PdlLinkRequest::sole();
        $this->assertSame('open', $request->status);
        $this->assertSame('Juan Dela Cruz', $request->pdl_name);
        $this->assertNull($request->relationship_id);
        $this->assertNull($request->has_children_together);

        $document = RelationshipDocument::sole();
        $this->assertSame($request->request_id, $document->pdl_link_request_id);
        $this->assertNull($document->relationship_id);
        $this->assertSame('pdl_birth_certificate', $document->requirement_key);
        $this->assertSame('pending', $document->status);
        Storage::disk('local')->assertExists($document->file_path);
        // Private storage, never a registration document.
        $this->assertCount(0, $this->visitor->registrationDocuments()->get());

        $response->assertJsonPath('pdlRequest.status', 'open')
            ->assertJsonPath('pdlRequest.relationshipLabel', 'Parent')
            ->assertJsonPath('pdlRequest.documents.0.key', 'pdl_birth_certificate')
            ->assertJsonMissingPath('pdlRequest.documents.0.file_path')
            ->assertJsonMissingPath('pdlRequest.documents.0.filePath');
        $this->assertStringNotContainsString($document->file_path, $response->getContent());
    }

    public function test_live_in_partner_needs_the_children_answer_and_its_matching_document(): void
    {
        $base = [
            'pdlName' => 'Juan Dela Cruz',
            'relationshipType' => 'live_in_partner',
            'documents' => [
                'cenomar_visitor' => $this->file(),
                'cenomar_pdl' => $this->file(),
            ],
        ];

        $this->submit($base)->assertStatus(422)->assertJsonValidationErrors('hasChildrenTogether');

        // "Has children" also needs the child's birth certificate.
        $this->submit($base + ['hasChildrenTogether' => 'true'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents.child_birth_certificate']);

        // The witness statement is no longer required, so it is not accepted either.
        $withWitness = $base;
        $withWitness['documents']['witness_statement'] = $this->file();
        $this->submit($withWitness + ['hasChildrenTogether' => 'false'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);
        $this->assertSame(0, PdlLinkRequest::count());
        $this->assertSame([], $this->storedFiles());

        // Without children: both CENOMARs are enough.
        $this->submit($base + ['hasChildrenTogether' => 'false'])->assertCreated();
        $this->assertFalse(PdlLinkRequest::sole()->has_children_together);
        $this->assertSame(2, RelationshipDocument::count());
    }

    public function test_invalid_requests_are_refused_and_store_nothing(): void
    {
        // Seven tries in a row: the rate limit has its own test below.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Not one of the six registration types.
        $this->submit($this->parentRequest(['relationshipType' => 'sibling']))->assertStatus(422)->assertJsonValidationErrors('relationshipType');
        $this->submit($this->parentRequest(['pdlName' => 'Juan 123']))->assertStatus(422)->assertJsonValidationErrors('pdlName');
        $this->submit($this->parentRequest(['pdlName' => '']))->assertStatus(422)->assertJsonValidationErrors('pdlName');
        $this->submit($this->parentRequest(['documents' => []]))->assertStatus(422)->assertJsonValidationErrors('documents');
        $this->submit($this->parentRequest(['documents' => ['marriage_certificate' => $this->file()]]))
            ->assertStatus(422)->assertJsonValidationErrors(['documents', 'documents.pdl_birth_certificate']);
        $this->submit($this->parentRequest(['documents' => ['pdl_birth_certificate' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain')]]))
            ->assertStatus(422)->assertJsonValidationErrors('documents.pdl_birth_certificate');
        $this->submit($this->parentRequest(['documents' => ['pdl_birth_certificate' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]]))
            ->assertStatus(422)->assertJsonValidationErrors('documents.pdl_birth_certificate');

        $this->assertSame(0, PdlLinkRequest::count());
        $this->assertSame(0, RelationshipDocument::count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_minor_also_sends_their_own_birth_certificate(): void
    {
        $this->visitor->update(['date_of_birth' => now()->subYears(15)->toDateString()]);

        $this->submit($this->parentRequest())
            ->assertStatus(422)->assertJsonValidationErrors('documents.visitor_birth_certificate');

        $this->submit($this->parentRequest(['documents' => [
            'pdl_birth_certificate' => $this->file(),
            'visitor_birth_certificate' => $this->file(),
        ]]))->assertCreated();
        $this->assertSame(2, RelationshipDocument::where('pdl_link_request_id', PdlLinkRequest::sole()->request_id)->count());
    }

    public function test_only_one_open_request_at_a_time(): void
    {
        $this->submit($this->parentRequest())->assertCreated();
        $this->submit($this->parentRequest(['pdlName' => 'Pedro Santos']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'open_request_exists');

        $this->assertSame(1, PdlLinkRequest::count());
        $this->assertSame(1, RelationshipDocument::count());
        $this->assertCount(1, $this->storedFiles()); // the refused request stored nothing
    }

    public function test_submissions_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->submit($this->parentRequest(['pdlName' => '']))->assertStatus(422);
        }
        $this->submit($this->parentRequest())->assertStatus(429);
        $this->assertSame(0, PdlLinkRequest::count());
    }

    public function test_a_visitor_who_is_not_approved_cannot_submit(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);

        $this->submit($this->parentRequest())->assertForbidden()->assertJsonPath('code', 'visitor_not_approved');
        $this->assertSame(0, PdlLinkRequest::count());
    }

    public function test_cancelling_deletes_only_that_requests_documents_and_files(): void
    {
        // Files that must survive: a registration file, a relationship's file,
        // and another visitor's open request.
        $keep = [];
        foreach ([
            ['relationship_id' => null, 'visitor_id' => $this->visitor->visitor_id, 'pdl_link_request_id' => null],
            ['relationship_id' => $this->relationship->relationship_id, 'visitor_id' => $this->visitor->visitor_id, 'pdl_link_request_id' => null],
        ] as $i => $owner) {
            $path = $this->file()->store(PdlLinkRequestService::FOLDER, 'local');
            $keep[] = RelationshipDocument::create($owner + [
                'requirement_key' => 'marriage_certificate', 'file_path' => $path, 'status' => 'pending', 'uploaded_at' => now(),
            ]);
        }
        $other = $this->approvedVisitor('other');
        Sanctum::actingAs(Account::find($other->account_id));
        $this->submit($this->parentRequest())->assertCreated();
        $otherDocument = RelationshipDocument::where('visitor_id', $other->visitor_id)->sole();

        Sanctum::actingAs($this->visitorAccount);
        $this->submit($this->parentRequest())->assertCreated();
        $mine = PdlLinkRequest::where('visitor_id', $this->visitor->visitor_id)->sole();
        $myDocument = RelationshipDocument::where('pdl_link_request_id', $mine->request_id)->sole();

        $this->post("/api/pdl-requests/{$mine->request_id}/cancel", [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('pdlRequest.status', 'cancelled')
            ->assertJsonCount(0, 'pdlRequest.documents');

        $mine->refresh();
        $this->assertSame('cancelled', $mine->status);
        $this->assertNull($mine->open_visitor_id);
        $this->assertNull(RelationshipDocument::find($myDocument->document_id));
        Storage::disk('local')->assertMissing($myDocument->file_path);

        foreach ([...$keep, $otherDocument] as $document) {
            $this->assertNotNull(RelationshipDocument::find($document->document_id));
            Storage::disk('local')->assertExists($document->file_path);
        }

        // The open slot is free again.
        $this->submit($this->parentRequest(['pdlName' => 'Pedro Santos']))->assertCreated();
    }

    public function test_cancel_needs_the_visitors_own_open_request(): void
    {
        $this->submit($this->parentRequest())->assertCreated();
        $mine = PdlLinkRequest::sole();
        $url = "/api/pdl-requests/{$mine->request_id}/cancel";

        // Someone else's request: not found, and left open.
        Sanctum::actingAs(Account::find($this->approvedVisitor('other')->account_id));
        $this->post($url, [], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertTrue($mine->fresh()->isOpen());

        Sanctum::actingAs($this->visitorAccount);
        $this->post('/api/pdl-requests/999999/cancel', [], ['Accept' => 'application/json'])->assertNotFound();
        $this->post($url, [], ['Accept' => 'application/json'])->assertOk();
        $this->post($url, [], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_documents_api_returns_the_relevant_request(): void
    {
        $pdlRequest = fn () => $this->getJson('/api/documents')->assertOk()->json('pdlRequest');
        $this->assertNull($pdlRequest());

        $this->submit($this->parentRequest())->assertCreated();
        $request = PdlLinkRequest::sole();
        $this->assertSame('open', $pdlRequest()['status']);
        $this->assertSame('Juan Dela Cruz', $pdlRequest()['pdlName']);
        $this->assertSame('parent', $pdlRequest()['relationshipType']);
        $this->assertNull($pdlRequest()['rejectionReason']);
        // Existing fields are still there.
        $this->getJson('/api/documents')->assertJsonStructure(['verificationStatus', 'governmentIds', 'relationships', 'registrationDocuments', 'pdlRequest']);

        // Rejected (staff action, later stage): shown with the reason for 30 days.
        $request->update(PdlLinkRequest::closingAttributes(PdlLinkRequest::STATUS_REJECTED) + ['rejection_reason' => 'No PDL by that name.']);
        $this->assertSame('rejected', $pdlRequest()['status']);
        $this->assertSame('No PDL by that name.', $pdlRequest()['rejectionReason']);

        $this->travel(31)->days();
        $this->assertNull($pdlRequest());
        $this->travelBack();

        // Linked: shown as a relationship under My PDLs instead.
        $request->update(['status' => PdlLinkRequest::STATUS_LINKED]);
        $this->assertNull($pdlRequest());
    }

    public function test_a_storage_failure_leaves_no_request_rows_or_files(): void
    {
        $first = $this->file('first.pdf');
        $failing = \Mockery::mock(UploadedFile::fake()->create('second.pdf', 50, 'application/pdf'))->makePartial();
        $failing->shouldReceive('store')->andThrow(new \RuntimeException('Disk full'));

        try {
            app(PdlLinkRequestService::class)->submit($this->visitor, [
                'pdlName' => 'Juan Dela Cruz', 'relationshipType' => 'parent', 'hasChildrenTogether' => null,
            ], ['pdl_birth_certificate' => $first, 'visitor_birth_certificate' => $failing]);
            $this->fail('The storage failure must reach the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Disk full', $e->getMessage());
        }

        $this->assertSame(0, PdlLinkRequest::count());
        $this->assertSame(0, RelationshipDocument::count());
        $this->assertSame([], $this->storedFiles()); // the first file was removed again
    }
}
