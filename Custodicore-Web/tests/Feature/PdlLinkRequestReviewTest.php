<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Notification;
use App\Models\Pdl;
use App\Models\PdlLinkRequest;
use App\Models\RelationshipDocument;
use App\Models\StaffProfile;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Services\PdlLinkRequestService;
use App\Services\PdlRequestNotOpen;
use App\Services\ReviewQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 5B, Stage 3: the Record Officer reviews a visitor's request to add
 * another PDL — lists it under PDL Links, views its documents, matches it to
 * the actual PDL (new / reused pending / reopened rejected relationship) or
 * rejects it. Plus the cancellation change: a visitor whose account is no
 * longer approved can still cancel their own open request.
 */
class PdlLinkRequestReviewTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private Pdl $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Storage::fake('local');
        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->other = $this->newPdl('Juan Dela Cruz', 'PDL-2026-00077');
    }

    // ---------------------------------------------------------------- helpers

    private function newPdl(string $name, string $number): Pdl
    {
        return Pdl::create([
            'pdl_number' => $number,
            'full_name' => $name,
            'date_of_birth' => '1988-05-05',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);
    }

    /** An open parent request with its one document, sent through the Stage 2 service. */
    private function openRequest(?VisitorProfile $visitor = null, string $type = 'parent', ?bool $children = null, string $pdlName = 'Juan Dela Cruz'): PdlLinkRequest
    {
        $visitor ??= $this->visitor;
        $files = match ($type) {
            'live_in_partner' => ['cenomar_visitor' => $this->file(), 'cenomar_pdl' => $this->file(), 'child_birth_certificate' => $this->file()],
            default => ['pdl_birth_certificate' => $this->file()],
        };

        return app(PdlLinkRequestService::class)->submit($visitor, [
            'pdlName' => $pdlName,
            'relationshipType' => $type,
            'hasChildrenTogether' => $children,
        ], $files);
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->create('doc.pdf', 60, 'application/pdf');
    }

    private function storedDocument(array $attributes): RelationshipDocument
    {
        $path = $this->file()->store(PdlLinkRequestService::FOLDER, 'local');

        return RelationshipDocument::create($attributes + [
            'visitor_id' => $this->visitor->visitor_id,
            'requirement_key' => 'pdl_birth_certificate',
            'file_path' => $path,
            'status' => 'pending',
            'uploaded_at' => now()->subDay(),
        ]);
    }

    private function relationshipWith(Pdl $pdl, string $status, string $type = 'parent'): VisitorPdlRelationship
    {
        return VisitorPdlRelationship::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $pdl->pdl_id,
            'relationship_type' => $type,
            'priority_tier' => VisitorPdlRelationship::priorityTierFor($type),
            'verification_status' => $status,
        ]);
    }

    private function link(PdlLinkRequest $request, array $body = [])
    {
        return $this->from(route('pdl-requests.show', $request->request_id))
            ->post(route('pdl-requests.link', $request->request_id), $body + [
                'pdl_id' => $this->other->pdl_id,
                'relationship_type' => 'parent',
            ]);
    }

    private function otherVisitor(): VisitorProfile
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'other',
            'email' => 'other@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $account->forceFill(['email_verified_at' => now()])->save();

        return VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => 'Olga Other',
            'date_of_birth' => '1990-01-01',
            'contact_number' => '09170000009',
            'verification_status' => 'verified',
        ]);
    }

    // ---------------------------------------------------------- queue listing

    public function test_open_requests_are_listed_above_pending_links_and_counted_in_the_badge(): void
    {
        $request = $this->openRequest();
        $closed = $this->openRequest($this->otherVisitor());
        app(PdlLinkRequestService::class)->cancel($closed->visitor, $closed);
        $pendingLink = $this->relationshipWith($this->newPdl('Second PDL', 'PDL-2026-00078'), 'pending');

        // One open request + one pending link; the cancelled request is not counted.
        $this->assertSame(1, ReviewQueue::pdlRequestsQuery()->count());
        $this->assertSame(2, ReviewQueue::counts()['pdl_links']);

        $page = $this->get('/review-queue/pdl-links')->assertOk();
        $page->assertSeeInOrder(['Visitor Requests', 'Juan Dela Cruz', 'Parent', 'Pending Relationship Links', 'Second PDL'])
            ->assertSee(route('pdl-requests.show', $request->request_id), false)
            ->assertDontSee(route('pdl-requests.show', $closed->request_id), false)
            // Existing pending links still open the visitor page.
            ->assertSee(route('visitor.show', $pendingLink->visitor_id) . '#relationships', false)
            // Sidebar badge (sum of every queue) and the tab count.
            ->assertSee('<span class="queue-count has-items">2</span>', false);

        // Only open requests waiting: the Review Queue entry opens PDL Links.
        $pendingLink->update(['verification_status' => 'verified']);
        $this->get('/review-queue')->assertRedirect(route('pdl-links.index'));
    }

    public function test_only_record_officers_can_open_the_request_pages(): void
    {
        $request = $this->openRequest();

        $this->actingAs($this->frontDeskAccount, 'web');
        $this->get(route('pdl-requests.show', $request->request_id))->assertForbidden();
        $this->post(route('pdl-requests.reject', $request->request_id), ['rejection_reason' => 'Not found here.'])->assertForbidden();
        $this->post(route('pdl-requests.link', $request->request_id), ['pdl_id' => $this->other->pdl_id, 'relationship_type' => 'parent'])->assertForbidden();
        $this->assertTrue($request->fresh()->isOpen());
    }

    // ---------------------------------------------------------------- details

    public function test_request_details_show_the_visitor_answers_and_documents(): void
    {
        $request = $this->openRequest(null, 'live_in_partner', true);
        $documents = $request->documents()->get();
        $this->assertCount(3, $documents);

        $page = $this->get(route('pdl-requests.show', $request->request_id))->assertOk();
        $page->assertSee($this->visitor->full_name)
            ->assertSee('Juan Dela Cruz')
            ->assertSee('Live-in Partner')
            ->assertSee('Yes, they have children together')
            ->assertSee($request->created_at->format('M j, Y'))
            ->assertSee('data-include-related="1"', false);
        foreach ($documents as $document) {
            $page->assertSee($document->label())
                ->assertSee(route('relationship-documents.file', $document->document_id), false);
        }

        // Staff can open each file, but it cannot be approved or rejected before linking.
        $first = $documents->first();
        $this->get(route('relationship-documents.file', $first->document_id))->assertOk();
        $this->post(route('relationship-documents.verify', $first->document_id))->assertSessionHas('error');
        $this->post(route('relationship-documents.reject', $first->document_id), ['rejection_reason' => 'Blurry photo.'])->assertSessionHas('error');
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertNull($first->fresh()->relationship_id);
        Storage::disk('local')->assertExists($first->file_path);
    }

    // ----------------------------------------------------------------- search

    public function test_search_with_include_related_returns_pdls_with_pending_or_rejected_links(): void
    {
        $pending = $this->relationshipWith($this->newPdl('Juan Pending', 'PDL-2026-00080'), 'pending');
        $rejected = $this->relationshipWith($this->newPdl('Juan Rejected', 'PDL-2026-00081'), 'rejected');
        $url = "/visitors/{$this->visitor->visitor_id}/relatable-pdls?q=Juan";

        // Default behaviour unchanged: already-related PDLs are left out.
        $default = $this->getJson($url)->assertOk()->json('results');
        $this->assertSame([$this->other->pdl_id], array_column($default, 'id'));
        $this->assertArrayNotHasKey('relationshipStatus', $default[0]);

        $results = collect($this->getJson($url . '&include_related=1')->assertOk()->json('results'))->keyBy('id');
        $this->assertNull($results[$this->other->pdl_id]['relationshipStatus']);
        $this->assertSame('pending', $results[$pending->pdl_id]['relationshipStatus']);
        $this->assertSame('rejected', $results[$rejected->pdl_id]['relationshipStatus']);

        // The fixture's approved link is labelled too, so staff see why linking it is refused.
        $this->getJson("/visitors/{$this->visitor->visitor_id}/relatable-pdls?include_related=1&q=" . urlencode($this->pdl->full_name))
            ->assertJsonPath('results.0.relationshipStatus', 'verified');
    }

    // ---------------------------------------------------------------- linking

    public function test_linking_to_an_unrelated_pdl_creates_a_pending_relationship_with_only_the_request_documents(): void
    {
        // Must not move: a registration upload for the same requirement and
        // another visitor's request document.
        $registrationUpload = $this->storedDocument(['relationship_id' => null, 'pdl_link_request_id' => null]);
        $othersRequest = $this->openRequest($this->otherVisitor());
        $request = $this->openRequest();
        $requestDocument = $request->documents()->sole();

        $this->link($request)
            ->assertRedirect(route('visitor.show', $this->visitor->visitor_id))
            ->assertSessionHas('success');

        $relationship = VisitorPdlRelationship::where('visitor_id', $this->visitor->visitor_id)->where('pdl_id', $this->other->pdl_id)->sole();
        $this->assertSame('pending', $relationship->verification_status);
        $this->assertSame('parent', $relationship->relationship_type);
        $this->assertSame('high_priority', $relationship->priority_tier);

        $request->refresh();
        $this->assertSame('linked', $request->status);
        $this->assertSame($relationship->relationship_id, (int) $request->relationship_id);
        $this->assertNull($request->open_visitor_id);
        $this->assertSame(StaffProfile::where('account_id', $this->recordOfficerAccount->account_id)->value('staff_id'), (int) $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);

        // The request's file moved, still pending, its origin kept.
        $requestDocument->refresh();
        $this->assertSame($relationship->relationship_id, (int) $requestDocument->relationship_id);
        $this->assertSame($request->request_id, (int) $requestDocument->pdl_link_request_id);
        $this->assertSame('pending', $requestDocument->status);
        Storage::disk('local')->assertExists($requestDocument->file_path);

        $this->assertNull($registrationUpload->fresh()->relationship_id);
        $this->assertNull($othersRequest->documents()->sole()->relationship_id);
        $this->assertSame(1, $relationship->documents()->count());

        // The visitor's open slot is free again, and they got the Phase 5A notice.
        $this->assertFalse(PdlLinkRequest::open()->where('visitor_id', $this->visitor->visitor_id)->exists());
        $notice = Notification::where('account_id', $this->visitor->account_id)->where('notification_type', 'relationship_linked')->sole();
        $this->assertSame($relationship->relationship_id, (int) $notice->related_record_id);
        $this->assertStringContainsString('Juan Dela Cruz', $notice->message);

        // Incomplete (no approved ID yet), so not approved: it waits in PDL Links. The
        // visitor page has one relationship Approve that covers its documents; no Approve per document.
        $this->assertSame(1, ReviewQueue::pdlLinksQuery()->count());
        $this->get(route('visitor.show', $this->visitor->visitor_id))->assertOk()
            ->assertSee(route('visitor.relationships.verify', [$this->visitor->visitor_id, $relationship->relationship_id]), false)
            ->assertDontSee(route('relationship-documents.verify', $requestDocument->document_id), false);

        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'N-1',
            'file_path' => 'x.png', 'verification_status' => 'verified',
        ]);
        $this->post(route('relationship-documents.verify', $requestDocument->document_id))->assertSessionHas('success');
        $this->post(route('visitor.relationships.verify', [$this->visitor->visitor_id, $relationship->relationship_id]))
            ->assertSessionHas('success', 'Relationship approved.');
        $this->assertSame('verified', $relationship->fresh()->verification_status);
    }

    public function test_linking_reuses_an_existing_pending_relationship(): void
    {
        // The request comes first: a visitor cannot request a PDL they already
        // have a pending relationship with, but staff may add one meanwhile.
        $request = $this->openRequest();
        $requestDocument = $request->documents()->sole();
        $pending = $this->relationshipWith($this->other, 'pending', 'sibling');
        $ownDocument = $this->storedDocument(['relationship_id' => $pending->relationship_id, 'requirement_key' => 'visitor_birth_certificate']);
        $relationshipsBefore = VisitorPdlRelationship::count();

        $this->link($request)->assertSessionHas('success');

        $this->assertSame($relationshipsBefore, VisitorPdlRelationship::count());
        $pending->refresh();
        $this->assertSame('pending', $pending->verification_status);
        // Staff confirmed the type on the link form.
        $this->assertSame('parent', $pending->relationship_type);
        $this->assertSame($pending->relationship_id, (int) $request->fresh()->relationship_id);
        $this->assertSame($pending->relationship_id, (int) $requestDocument->fresh()->relationship_id);
        // The relationship's own earlier file is kept.
        $this->assertSame($pending->relationship_id, (int) $ownDocument->fresh()->relationship_id);
        $this->assertSame($requestDocument->document_id, $pending->latestDocument('pdl_birth_certificate')?->document_id);
    }

    public function test_linking_reopens_a_rejected_relationship_and_deletes_only_its_rejected_documents(): void
    {
        $staffId = StaffProfile::first()->staff_id;
        $rejected = $this->relationshipWith($this->other, 'rejected');
        $rejected->update([
            'verified_by' => $staffId,
            'verified_at' => now()->subWeek(),
            'officer_confirmations' => ['some_check' => ['by' => 1]],
        ]);
        $oldRejected = $this->storedDocument(['relationship_id' => $rejected->relationship_id, 'status' => 'rejected', 'rejection_reason' => 'Fake']);
        // A previously approved document of the same relationship is kept.
        $oldApproved = $this->storedDocument([
            'relationship_id' => $rejected->relationship_id, 'requirement_key' => 'visitor_birth_certificate',
            'status' => 'verified', 'reviewed_by' => $staffId, 'reviewed_at' => now()->subWeek(),
        ]);
        // Another relationship's file must survive, and a leftover
        // registration upload must not be attached.
        $keep = $this->storedDocument(['relationship_id' => $this->relationship->relationship_id]);
        $registrationUpload = $this->storedDocument(['relationship_id' => null, 'pdl_link_request_id' => null]);
        $request = $this->openRequest();
        $requestDocument = $request->documents()->sole();

        $this->link($request)->assertSessionHas('success');

        // A fresh review: pending, no old reviewer, no old confirmations.
        $rejected->refresh();
        $this->assertSame('pending', $rejected->verification_status);
        $this->assertNull($rejected->verified_by);
        $this->assertNull($rejected->verified_at);
        $this->assertNull($rejected->officer_confirmations);

        $this->assertNull(RelationshipDocument::find($oldRejected->document_id));
        Storage::disk('local')->assertMissing($oldRejected->file_path);

        $oldApproved->refresh();
        $this->assertSame('verified', $oldApproved->status);
        $this->assertSame($rejected->relationship_id, (int) $oldApproved->relationship_id);
        Storage::disk('local')->assertExists($oldApproved->file_path);

        $this->assertNotNull($keep->fresh());
        Storage::disk('local')->assertExists($keep->file_path);
        $this->assertNull($registrationUpload->fresh()->relationship_id);

        $this->assertEqualsCanonicalizing(
            [$requestDocument->document_id, $oldApproved->document_id],
            $rejected->documents()->pluck('document_id')->all()
        );
        Storage::disk('local')->assertExists($requestDocument->file_path);
        $this->assertSame('linked', $request->fresh()->status);
    }

    public function test_an_unapproved_visitor_cannot_be_linked_but_their_request_can_be_rejected(): void
    {
        $request = $this->openRequest();
        $this->visitor->update(['verification_status' => 'rejected']);

        $this->get(route('pdl-requests.show', $request->request_id))->assertOk()
            ->assertSee('not approved right now')
            ->assertSee(route('pdl-requests.reject', $request->request_id), false)
            ->assertDontSee(route('pdl-requests.link', $request->request_id), false);
        $this->link($request)->assertSessionHas('error');
        $this->assertTrue($request->fresh()->isOpen());

        $this->post(route('pdl-requests.reject', $request->request_id), ['rejection_reason' => 'Account is not approved.'])
            ->assertSessionHas('success');
        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_linking_to_an_already_approved_relationship_is_refused(): void
    {
        // The fixture visitor is already approved for the fixture PDL.
        $request = $this->openRequest();
        $requestDocument = $request->documents()->sole();

        $this->link($request, ['pdl_id' => $this->pdl->pdl_id])
            ->assertRedirect(route('pdl-requests.show', $request->request_id))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'already linked'));

        $this->assertTrue($request->fresh()->isOpen());
        $this->assertNull($requestDocument->fresh()->relationship_id);
        $this->assertSame('verified', $this->relationship->fresh()->verification_status);
        $this->assertSame(0, Notification::where('notification_type', 'relationship_linked')->count());
    }

    public function test_link_form_is_validated(): void
    {
        $request = $this->openRequest();

        $this->link($request, ['pdl_id' => ''])->assertSessionHasErrors('pdl_id');
        $this->link($request, ['pdl_id' => 999999])->assertSessionHasErrors('pdl_id');
        $this->link($request, ['relationship_type' => 'friend'])->assertSessionHasErrors('relationship_type');
        $this->link($request, ['relationship_type' => 'live_in_partner'])->assertSessionHasErrors('has_children_together');
        $this->assertTrue($request->fresh()->isOpen());

        // The staff-confirmed answer is saved on the relationship.
        $this->link($request, ['relationship_type' => 'live_in_partner', 'has_children_together' => '0'])->assertSessionHas('success');
        $this->assertFalse(VisitorPdlRelationship::findOrFail($request->fresh()->relationship_id)->has_children_together);
    }

    public function test_a_visitor_who_is_no_longer_approved_cannot_be_linked(): void
    {
        $request = $this->openRequest();
        $this->visitor->update(['verification_status' => 'pending']);

        $this->link($request)->assertSessionHas('error');
        $this->assertTrue($request->fresh()->isOpen());
        $this->assertFalse(VisitorPdlRelationship::where('pdl_id', $this->other->pdl_id)->exists());
    }

    // -------------------------------------------------------------- rejecting

    public function test_rejecting_needs_a_reason_and_deletes_only_the_request_documents(): void
    {
        $keep = [
            $this->storedDocument(['relationship_id' => null, 'pdl_link_request_id' => null]),
            $this->storedDocument(['relationship_id' => $this->relationship->relationship_id]),
        ];
        $othersRequest = $this->openRequest($this->otherVisitor());
        $request = $this->openRequest();
        $requestDocument = $request->documents()->sole();
        $url = route('pdl-requests.reject', $request->request_id);

        $this->post($url, [])->assertSessionHasErrors('rejection_reason');
        $this->post($url, ['rejection_reason' => 'No'])->assertSessionHasErrors('rejection_reason');
        $this->assertTrue($request->fresh()->isOpen());
        $this->assertNotNull($requestDocument->fresh());

        $this->post($url, ['rejection_reason' => 'No PDL with this name is in our custody.'])
            ->assertRedirect(route('pdl-links.index'))
            ->assertSessionHas('success');

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertNull($request->open_visitor_id);
        $this->assertSame('No PDL with this name is in our custody.', $request->rejection_reason);
        $this->assertSame(StaffProfile::where('account_id', $this->recordOfficerAccount->account_id)->value('staff_id'), (int) $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertNull($request->relationship_id);

        $this->assertNull(RelationshipDocument::find($requestDocument->document_id));
        Storage::disk('local')->assertMissing($requestDocument->file_path);
        foreach ([...$keep, $othersRequest->documents()->sole()] as $document) {
            $this->assertNotNull($document->fresh());
            Storage::disk('local')->assertExists($document->file_path);
        }

        $notice = Notification::where('account_id', $this->visitor->account_id)->where('notification_type', 'pdl_request_rejected')->sole();
        $this->assertStringContainsString('No PDL with this name is in our custody.', $notice->message);
        $this->assertSame($request->request_id, (int) $notice->related_record_id);

        // The app sees the reason through GET /api/documents.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/documents')->assertOk()
            ->assertJsonPath('pdlRequest.status', 'rejected')
            ->assertJsonPath('pdlRequest.rejectionReason', 'No PDL with this name is in our custody.')
            ->assertJsonCount(0, 'pdlRequest.documents');
    }

    // ------------------------------------------------------------------ races

    public function test_a_closed_request_cannot_be_linked_rejected_or_cancelled_again(): void
    {
        // Cancelled by the visitor first: staff can neither link nor reject it.
        $cancelled = $this->openRequest();
        app(PdlLinkRequestService::class)->cancel($this->visitor, $cancelled);
        $this->link($cancelled)->assertSessionHas('error', fn ($m) => str_contains($m, 'cancelled'));
        $this->post(route('pdl-requests.reject', $cancelled->request_id), ['rejection_reason' => 'Not found here.'])
            ->assertSessionHas('error');
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertFalse(VisitorPdlRelationship::where('pdl_id', $this->other->pdl_id)->exists());

        // Linked first: a second link, a rejection and a visitor cancel are all refused.
        $linked = $this->openRequest();
        $this->link($linked)->assertSessionHas('success');
        $relationshipId = $linked->fresh()->relationship_id;
        $this->link($linked)->assertSessionHas('error', fn ($m) => str_contains($m, 'already linked'));
        $this->post(route('pdl-requests.reject', $linked->request_id), ['rejection_reason' => 'Not found here.'])
            ->assertSessionHas('error');

        Sanctum::actingAs($this->visitorAccount);
        $this->postJson("/api/pdl-requests/{$linked->request_id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('status', 'linked');
        $linked->refresh();
        $this->assertSame('linked', $linked->status);
        $this->assertSame($relationshipId, $linked->relationship_id);
        $this->assertSame(1, RelationshipDocument::where('relationship_id', $relationshipId)->count());
    }

    public function test_the_service_rechecks_the_locked_row_not_the_stale_model(): void
    {
        // Both officers loaded the request while it was open.
        $request = $this->openRequest();
        $staleCopy = PdlLinkRequest::findOrFail($request->request_id);
        $staffId = StaffProfile::first()->staff_id;
        $service = app(PdlLinkRequestService::class);

        $service->link($request, $this->other->pdl_id, 'parent', null, $staffId);

        try {
            $service->reject($staleCopy, 'Too late now.', $staffId);
            $this->fail('A linked request must not be rejected.');
        } catch (PdlRequestNotOpen $e) {
            $this->assertSame('linked', $e->status);
        }
        try {
            $service->link($staleCopy, $this->other->pdl_id, 'parent', null, $staffId);
            $this->fail('A linked request must not be linked twice.');
        } catch (PdlRequestNotOpen $e) {
            $this->assertSame('linked', $e->status);
        }
        $this->assertSame(1, VisitorPdlRelationship::where('pdl_id', $this->other->pdl_id)->count());
        $this->assertNotNull($request->documents()->sole()->relationship_id);
    }

    // ----------------------------------------------------------- cancellation

    public function test_a_visitor_who_is_no_longer_approved_can_still_cancel_their_own_request(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $this->visitor->update(['verification_status' => 'verified']);
            $request = $this->openRequest();
            $document = $request->documents()->sole();
            $this->visitor->update(['verification_status' => $status]);

            Sanctum::actingAs($this->visitorAccount);
            $this->postJson("/api/pdl-requests/{$request->request_id}/cancel")
                ->assertOk()
                ->assertJsonPath('pdlRequest.status', 'cancelled');
            $this->assertNull(RelationshipDocument::find($document->document_id));
            Storage::disk('local')->assertMissing($document->file_path);

            // Submitting still needs an approved account.
            $this->post('/api/pdl-requests', [
                'pdlName' => 'Juan Dela Cruz', 'relationshipType' => 'parent',
                'documents' => ['pdl_birth_certificate' => $this->file()],
            ], ['Accept' => 'application/json'])->assertForbidden()->assertJsonPath('code', 'visitor_not_approved');
        }
    }

    public function test_cancel_still_needs_a_signed_in_owner(): void
    {
        $request = $this->openRequest();
        $url = "/api/pdl-requests/{$request->request_id}/cancel";

        // Not signed in through the API.
        $this->app['auth']->forgetGuards();
        $this->postJson($url)->assertUnauthorized();

        // Another (not approved) visitor: not found, and left open.
        $other = $this->otherVisitor();
        $other->update(['verification_status' => 'pending']);
        Sanctum::actingAs(Account::find($other->account_id));
        $this->postJson($url)->assertNotFound();
        $this->assertTrue($request->fresh()->isOpen());

        // A staff account has no visitor profile.
        Sanctum::actingAs($this->recordOfficerAccount);
        $this->postJson($url)->assertForbidden();
        $this->assertTrue($request->fresh()->isOpen());
    }

    public function test_each_open_request_is_linked_rejected_or_cancelled_on_its_own(): void
    {
        $maria = $this->newPdl('Maria Clara', 'PDL-2026-00090');
        $a = $this->openRequest();                                    // linked to Juan Dela Cruz
        $b = $this->openRequest(null, 'parent', null, 'Pedro Santos'); // rejected
        $c = $this->openRequest(null, 'parent', null, 'Jose Rizal');   // cancelled by the visitor
        $d = $this->openRequest(null, 'parent', null, 'Maria Clara');  // linked to Maria Clara
        $e = $this->openRequest(null, 'parent', null, 'Andres Bonifacio'); // untouched
        $doc = fn (PdlLinkRequest $r) => $r->documents()->sole();
        [$docA, $docB, $docC, $docD, $docE] = array_map($doc, [$a, $b, $c, $d, $e]);

        // All five are in the staff queue, each on its own row.
        $this->get(route('pdl-links.index'))->assertOk()
            ->assertSee('Pedro Santos')->assertSee('Jose Rizal')->assertSee('Andres Bonifacio');
        $this->assertSame(5, ReviewQueue::pdlRequestsQuery()->count());

        $this->link($a)->assertSessionHas('success');
        $this->post(route('pdl-requests.reject', $b->request_id), ['rejection_reason' => 'No PDL by that name.'])->assertSessionHas('success');
        app(PdlLinkRequestService::class)->cancel($this->visitor, $c);
        $this->link($d, ['pdl_id' => $maria->pdl_id])->assertSessionHas('success');

        $this->assertSame(['linked', 'rejected', 'cancelled', 'linked', 'open'],
            array_map(fn ($r) => $r->fresh()->status, [$a, $b, $c, $d, $e]));

        // Two separate relationships, each holding only its own request's file.
        $relA = VisitorPdlRelationship::where('visitor_id', $this->visitor->visitor_id)->where('pdl_id', $this->other->pdl_id)->sole();
        $relD = VisitorPdlRelationship::where('visitor_id', $this->visitor->visitor_id)->where('pdl_id', $maria->pdl_id)->sole();
        $this->assertSame([$docA->document_id], $relA->documents()->pluck('document_id')->all());
        $this->assertSame([$docD->document_id], $relD->documents()->pluck('document_id')->all());

        // Rejected and cancelled lost only their own files.
        foreach ([$docB, $docC] as $gone) {
            $this->assertNull(RelationshipDocument::find($gone->document_id));
            Storage::disk('local')->assertMissing($gone->file_path);
        }
        // The untouched request keeps its file, unlinked, still in the queue.
        $this->assertNull($docE->fresh()->relationship_id);
        Storage::disk('local')->assertExists($docE->file_path);
        $this->assertSame([$e->request_id], ReviewQueue::pdlRequestsQuery()->pluck('request_id')->all());

        // The rejection notice points at that request only.
        $notice = Notification::where('notification_type', 'pdl_request_rejected')->sole();
        $this->assertSame($b->request_id, (int) $notice->related_record_id);
        $this->assertStringContainsString('Pedro Santos', $notice->message);
    }

    public function test_link_and_approve_approves_a_complete_request_in_one_step(): void
    {
        // An approved ID plus the request's PDL birth certificate: everything a parent needs.
        \App\Models\VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'N-1',
            'file_path' => 'x.png', 'verification_status' => 'verified',
        ]);
        $request = $this->openRequest();
        $document = $request->documents()->sole();

        $this->link($request)
            ->assertRedirect(route('pdl-links.index'))
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Linked and approved'));

        $relationship = VisitorPdlRelationship::where('visitor_id', $this->visitor->visitor_id)->where('pdl_id', $this->other->pdl_id)->sole();
        $this->assertSame('verified', $relationship->verification_status);
        $this->assertSame('verified', $document->fresh()->status);
        $this->assertTrue(Notification::where('account_id', $this->visitor->account_id)->where('notification_type', 'relationship_approved')->exists());
    }

    public function test_pending_link_is_approved_from_the_list_with_its_documents(): void
    {
        \App\Models\VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'N-1',
            'file_path' => 'x.png', 'verification_status' => 'verified',
        ]);
        $relationship = $this->relationshipWith($this->other, 'pending');
        $document = $this->storedDocument(['relationship_id' => $relationship->relationship_id]);
        $approve = route('visitor.relationships.verify', [$this->visitor->visitor_id, $relationship->relationship_id]);

        // One Approve per link in the list; no Approve per document anywhere.
        $this->get(route('pdl-links.index'))->assertOk()->assertSee($approve, false);
        $this->get(route('visitor.show', $this->visitor->visitor_id))->assertOk()
            ->assertDontSee(route('relationship-documents.verify', $document->document_id), false);

        $this->post($approve)->assertSessionHas('success');
        $this->assertSame('verified', $relationship->fresh()->verification_status);
        $this->assertSame('verified', $document->fresh()->status);
    }
}
