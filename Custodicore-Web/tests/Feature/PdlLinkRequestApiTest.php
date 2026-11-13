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

    /** Five different PDL names (the request limit). */
    private const NAMES = ['Juan Dela Cruz', 'Pedro Santos', 'Maria Clara', 'Jose Rizal', 'Andres Bonifacio'];

    public function test_up_to_five_open_requests_and_the_sixth_is_refused(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->assertSame(5, PdlLinkRequestService::MAX_OPEN);

        foreach (self::NAMES as $name) {
            $this->submit($this->parentRequest(['pdlName' => $name]))->assertCreated();
        }
        $this->submit($this->parentRequest(['pdlName' => 'Emilio Aguinaldo']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'pdl_request_limit')
            ->assertJsonPath('message', 'You already have 5 PDL requests waiting for facility staff. Wait for one to be reviewed, or cancel one in My PDLs, before sending another.');

        $this->assertSame(5, PdlLinkRequest::open()->count());
        $this->assertSame(5, RelationshipDocument::count());
        $this->assertCount(5, $this->storedFiles()); // the refused request stored nothing

        // Cancelling one frees a place.
        $first = PdlLinkRequest::where('pdl_name', 'Juan Dela Cruz')->sole();
        $this->post("/api/pdl-requests/{$first->request_id}/cancel", [], ['Accept' => 'application/json'])->assertOk();
        $this->submit($this->parentRequest(['pdlName' => 'Emilio Aguinaldo']))->assertCreated();
        $this->assertSame(5, PdlLinkRequest::open()->count());

        // A rejected one frees a place too.
        PdlLinkRequest::where('pdl_name', 'Pedro Santos')->sole()
            ->update(PdlLinkRequest::closingAttributes(PdlLinkRequest::STATUS_REJECTED) + ['rejection_reason' => 'Not found.']);
        $this->submit($this->parentRequest(['pdlName' => 'Apolinario Mabini']))->assertCreated();
    }

    public function test_the_limit_counts_each_visitors_own_requests_only(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        foreach (self::NAMES as $name) {
            $this->submit($this->parentRequest(['pdlName' => $name]))->assertCreated();
        }

        $other = $this->approvedVisitor('other');
        Sanctum::actingAs(Account::find($other->account_id));
        // Same name as one of the first visitor's requests: not a duplicate for them.
        $this->submit($this->parentRequest())->assertCreated();
        $this->getJson('/api/documents')->assertJsonPath('pdlRequestLimit', ['max' => 5, 'open' => 1]);
    }

    public function test_a_second_open_request_for_the_same_pdl_name_is_refused(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->submit($this->parentRequest())->assertCreated();

        // Case, extra spaces and periods do not make it a different PDL.
        foreach (['Juan Dela Cruz', 'juan  dela cruz', 'JUAN DELA CRUZ.'] as $name) {
            $this->submit($this->parentRequest(['pdlName' => $name]))
                ->assertStatus(409)
                ->assertJsonPath('code', 'duplicate_pdl_request')
                ->assertJsonPath('message', 'You already have a request to add Juan Dela Cruz waiting for facility staff. You can see it in My PDLs.');
        }
        $this->assertSame(1, PdlLinkRequest::count());
        $this->assertCount(1, $this->storedFiles());

        // Once that request is rejected, the same name can be requested again.
        PdlLinkRequest::sole()->update(PdlLinkRequest::closingAttributes(PdlLinkRequest::STATUS_REJECTED) + ['rejection_reason' => 'Not found.']);
        $this->submit($this->parentRequest())->assertCreated();
    }

    public function test_a_pdl_already_on_the_account_is_refused(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        // The fixture visitor has an approved relationship with "Example PDL".
        $this->submit($this->parentRequest(['pdlName' => 'example pdl']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'pdl_already_linked')
            ->assertJsonPath('message', 'Example PDL is already on your account. You can see this PDL in My PDLs.');

        // A pending relationship counts too; a rejected one can be requested again.
        $this->relationship->update(['verification_status' => 'pending']);
        $this->submit($this->parentRequest(['pdlName' => 'Example PDL']))->assertStatus(409)->assertJsonPath('code', 'pdl_already_linked');
        $this->relationship->update(['verification_status' => 'rejected']);
        $this->submit($this->parentRequest(['pdlName' => 'Example PDL']))->assertCreated();

        $this->assertSame(1, PdlLinkRequest::count());
        $this->assertCount(1, $this->storedFiles());
    }

    public function test_the_limit_is_enforced_by_the_service_not_only_the_api(): void
    {
        $service = app(PdlLinkRequestService::class);
        foreach (self::NAMES as $name) {
            $service->submit($this->visitor, ['pdlName' => $name, 'relationshipType' => 'parent', 'hasChildrenTogether' => null],
                ['pdl_birth_certificate' => $this->file()]);
        }

        try {
            $service->submit($this->visitor, ['pdlName' => 'Emilio Aguinaldo', 'relationshipType' => 'parent', 'hasChildrenTogether' => null],
                ['pdl_birth_certificate' => $this->file()]);
            $this->fail('A sixth open request must be refused.');
        } catch (\App\Services\PdlRequestRefused $e) {
            $this->assertSame(\App\Services\PdlRequestRefused::LIMIT, $e->refusal);
        }
        $this->assertSame(5, PdlLinkRequest::count());
        $this->assertCount(5, $this->storedFiles());
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
        // Existing fields are still there, next to the new list and limit.
        $this->getJson('/api/documents')->assertJsonStructure(['verificationStatus', 'governmentIds', 'relationships', 'registrationDocuments', 'pdlRequest', 'pdlRequests', 'pdlRequestLimit']);

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

    public function test_documents_api_lists_every_request_with_its_own_status(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $documents = fn () => $this->getJson('/api/documents')->assertOk();
        $documents()->assertJsonPath('pdlRequests', [])->assertJsonPath('pdlRequestLimit', ['max' => 5, 'open' => 0]);

        foreach (['Juan Dela Cruz', 'Pedro Santos', 'Maria Clara', 'Jose Rizal'] as $i => $name) {
            $this->travel($i)->minutes(); // distinct created_at for a stable order
            $this->submit($this->parentRequest(['pdlName' => $name]))->assertCreated();
        }
        $this->travelBack();
        $byName = fn () => PdlLinkRequest::all()->keyBy('pdl_name');
        $byName()['Pedro Santos']->update(PdlLinkRequest::closingAttributes(PdlLinkRequest::STATUS_REJECTED) + ['rejection_reason' => 'No PDL by that name.']);
        $this->post("/api/pdl-requests/{$byName()['Maria Clara']->request_id}/cancel", [], ['Accept' => 'application/json'])->assertOk();
        $byName()['Jose Rizal']->update(PdlLinkRequest::closingAttributes(PdlLinkRequest::STATUS_LINKED));

        $response = $documents();
        // Newest first; the linked one is shown as a PDL instead.
        $this->assertSame(['Maria Clara', 'Pedro Santos', 'Juan Dela Cruz'], array_column($response->json('pdlRequests'), 'pdlName'));
        $this->assertSame(['cancelled', 'rejected', 'open'], array_column($response->json('pdlRequests'), 'status'));
        $this->assertSame([null, 'No PDL by that name.', null], array_column($response->json('pdlRequests'), 'rejectionReason'));
        $response->assertJsonPath('pdlRequestLimit', ['max' => 5, 'open' => 1]);
        // Older builds still get one request: the open one before newer closed ones.
        $response->assertJsonPath('pdlRequest.pdlName', 'Juan Dela Cruz');

        // Closed requests drop off after 30 days; open ones stay.
        $this->travel(31)->days();
        $this->assertSame(['Juan Dela Cruz'], array_column($documents()->json('pdlRequests'), 'pdlName'));
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
