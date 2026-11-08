<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Notification;
use App\Models\Pdl;
use App\Models\RelationshipDocument;
use App\Models\StaffProfile;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Services\ReviewQueue;
use App\Services\ScheduleAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Initial account approval is final under ordinary review, an approved
 * Government ID cannot be rejected, and an additional PDL is reviewed on its
 * own: rejecting it needs a reason, tells the visitor to send a new request,
 * and leaves the account and every other PDL as they were.
 */
class ApprovalFinalityAndPdlRejectionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Storage::fake('local');
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    private function pendingVisitor(string $name = 'Ana Reyes'): VisitorProfile
    {
        $username = str_replace(' ', '.', strtolower($name));
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id, 'username' => $username, 'email' => "{$username}@example.com",
            'password_hash' => Hash::make('password'), 'status' => 'active',
        ]);

        return VisitorProfile::create([
            'account_id' => $account->account_id, 'full_name' => $name, 'date_of_birth' => '1992-02-02',
            'contact_number' => '09171112222', 'verification_status' => 'pending',
        ]);
    }

    private function id(VisitorProfile $visitor, string $status, string $number = 'N-1'): VisitorId
    {
        return VisitorId::create([
            'visitor_id' => $visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => $number,
            'file_path' => 'visitor-ids/id.png', 'verification_status' => $status,
        ]);
    }

    private function newPdl(string $name, string $number): Pdl
    {
        return Pdl::create([
            'pdl_number' => $number, 'full_name' => $name, 'date_of_birth' => '1988-05-05', 'gender' => 'male',
            'classification' => 'non_drug_related', 'admission_date' => '2024-01-01', 'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);
    }

    /** An additional PDL ("PDL B") of the approved fixture visitor, waiting for review. */
    private function pdlB(): VisitorPdlRelationship
    {
        return VisitorPdlRelationship::create([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->newPdl('Second PDL', 'PDL-TEST-002')->pdl_id,
            'relationship_type' => 'spouse',
            'priority_tier' => VisitorPdlRelationship::priorityTierFor('spouse'),
            'verification_status' => 'pending',
        ]);
    }

    private function notifications(VisitorProfile $visitor, string $type)
    {
        return Notification::where('account_id', $visitor->account_id)->where('notification_type', $type)->get();
    }

    // ---------------------------------------------------------------
    // Phase 1: account approval is final
    // ---------------------------------------------------------------

    public function test_a_pending_account_can_still_be_returned(): void
    {
        $visitor = $this->pendingVisitor();

        $this->post(route('visitor.reject', $visitor->visitor_id), ['rejection_reason' => 'Please fix your address.'])
            ->assertSessionHas('success');

        $this->assertSame('rejected', $visitor->fresh()->verification_status);
        $this->assertCount(1, $this->notifications($visitor, 'account_rejected'));
    }

    public function test_an_approved_account_cannot_be_rejected(): void
    {
        $this->post(route('visitor.reject', $this->visitor->visitor_id), ['rejection_reason' => 'Please fix your address.'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already approved'));

        $fresh = $this->visitor->fresh();
        $this->assertSame('verified', $fresh->verification_status);
        $this->assertNull($fresh->rejection_reason);
        $this->assertCount(0, $this->notifications($this->visitor, 'account_rejected'));
        $this->assertSame('verified', $this->relationship->fresh()->verification_status);

        // The visitor keeps full access in the app.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/visits')->assertOk();
    }

    public function test_a_returned_account_corrects_and_is_approved_as_before(): void
    {
        $visitor = $this->pendingVisitor();
        $this->post(route('visitor.reject', $visitor->visitor_id), ['rejection_reason' => 'Please fix your address.']);

        Sanctum::actingAs(Account::find($visitor->account_id));
        $this->patchJson('/api/me', ['address' => '12 Rizal St.'])->assertOk();

        $this->assertSame('pending', $visitor->fresh()->verification_status);
    }

    // ---------------------------------------------------------------
    // Phase 1: an approved Government ID cannot be rejected
    // ---------------------------------------------------------------

    public function test_a_pending_government_id_can_still_be_rejected(): void
    {
        $visitor = $this->pendingVisitor();
        $id = $this->id($visitor, 'pending');

        $this->post(route('visitor.ids.reject', [$visitor->visitor_id, $id->visitor_id_doc_id]), ['rejection_reason' => 'The ID photo is blurry.'])
            ->assertSessionHas('success', 'ID document rejected.');

        $this->assertSame('rejected', $id->fresh()->verification_status);
    }

    public function test_an_approved_government_id_cannot_be_rejected(): void
    {
        $id = $this->id($this->visitor, 'verified');

        $this->post(route('visitor.ids.reject', [$this->visitor->visitor_id, $id->visitor_id_doc_id]), ['rejection_reason' => 'The ID photo is blurry.'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already approved'));

        $fresh = $id->fresh();
        $this->assertSame('verified', $fresh->verification_status);
        $this->assertNull($fresh->rejection_reason);
        $this->assertCount(0, $this->notifications($this->visitor, 'document_rejected'));
    }

    public function test_rejecting_a_new_pending_id_leaves_the_approved_one_approved(): void
    {
        $approved = $this->id($this->visitor, 'verified', 'N-1');
        $newer = $this->id($this->visitor, 'pending', 'N-2');

        $this->post(route('visitor.ids.reject', [$this->visitor->visitor_id, $newer->visitor_id_doc_id]), ['rejection_reason' => 'The ID photo is blurry.'])
            ->assertSessionHas('success');

        $this->assertSame('rejected', $newer->fresh()->verification_status);
        $this->assertSame('verified', $approved->fresh()->verification_status);
        $this->assertSame('verified', $this->visitor->fresh()->verification_status);
    }

    public function test_the_reject_action_is_shown_only_for_a_pending_id(): void
    {
        $visitor = $this->pendingVisitor();
        $approved = $this->id($visitor, 'verified', 'N-1');
        $pending = $this->id($visitor, 'pending', 'N-2');

        foreach (['/account-reviews', "/visitors/{$visitor->visitor_id}"] as $page) {
            $this->get($page)->assertOk()
                ->assertSee(route('visitor.ids.reject', [$visitor->visitor_id, $pending->visitor_id_doc_id]), false)
                ->assertDontSee(route('visitor.ids.reject', [$visitor->visitor_id, $approved->visitor_id_doc_id]), false);
        }
    }

    // ---------------------------------------------------------------
    // Phase 2: rejecting an additional PDL
    // ---------------------------------------------------------------

    public function test_rejecting_an_additional_pdl_requires_a_reason(): void
    {
        $pdlB = $this->pdlB();
        $url = route('visitor.relationships.reject', [$this->visitor->visitor_id, $pdlB->relationship_id]);

        foreach ([[], ['rejection_reason' => ''], ['rejection_reason' => 'Bad'], ['rejection_reason' => str_repeat('a', 501)]] as $data) {
            $this->post($url, $data)->assertSessionHasErrors('rejection_reason');
        }

        $this->assertSame('pending', $pdlB->fresh()->verification_status);
        $this->assertCount(0, $this->notifications($this->visitor, 'relationship_rejected'));
    }

    public function test_rejecting_pdl_b_keeps_the_account_and_pdl_a_and_tells_the_visitor_what_to_do(): void
    {
        $pdlB = $this->pdlB();

        $this->post(route('visitor.relationships.reject', [$this->visitor->visitor_id, $pdlB->relationship_id]), [
            'rejection_reason' => 'The marriage certificate names do not match.',
        ])->assertSessionHas('success');

        $this->assertSame('rejected', $pdlB->fresh()->verification_status);
        // The account and the approved PDL A are untouched.
        $this->assertSame('verified', $this->visitor->fresh()->verification_status);
        $this->assertSame('verified', $this->relationship->fresh()->verification_status);

        // PDL A stays selectable on the Visitation Calendar; PDL B is not.
        $selectable = app(ScheduleAvailabilityService::class)->verifiedRelationships($this->visitor->fresh())
            ->pluck('relationship_id')->all();
        $this->assertSame([$this->relationship->relationship_id], $selectable);

        // One notification with the reason, the next step and its record.
        $sent = $this->notifications($this->visitor, 'relationship_rejected')->sole();
        $this->assertStringContainsString('Second PDL', $sent->message);
        $this->assertStringContainsString('Reason: The marriage certificate names do not match.', $sent->message);
        $this->assertStringContainsString('send a new Add Another PDL request with corrected documents', $sent->message);
        $this->assertSame('visitor_pdl_relationships', $sent->related_record_type);

        // The app receives the record it is about, and still has full access.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/notifications')->assertOk()->assertJsonFragment([
            'category' => 'relationship_rejected',
            'relatedType' => 'visitor_pdl_relationships',
            'relatedId' => (string) $pdlB->relationship_id,
        ]);
        $this->getJson('/api/visits')->assertOk();

        // A second click sends nothing more.
        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->post(route('visitor.relationships.reject', [$this->visitor->visitor_id, $pdlB->relationship_id]), [
            'rejection_reason' => 'The marriage certificate names do not match.',
        ])->assertSessionHas('error');
        $this->assertCount(1, $this->notifications($this->visitor, 'relationship_rejected'));
    }

    public function test_the_relationship_reject_form_asks_for_a_reason(): void
    {
        $pdlB = $this->pdlB();

        $this->get("/visitors/{$this->visitor->visitor_id}")->assertOk()
            ->assertSee(route('visitor.relationships.reject', [$this->visitor->visitor_id, $pdlB->relationship_id]), false)
            ->assertSee('Why the relationship was rejected (shown to the visitor)');
    }

    public function test_a_rejected_relationship_cannot_be_approved_directly(): void
    {
        $pdlB = $this->pdlB();
        $pdlB->update(['verification_status' => 'rejected']);
        $verifyUrl = route('visitor.relationships.verify', [$this->visitor->visitor_id, $pdlB->relationship_id]);

        // No Approve action for the rejected relationship on the visitor page.
        $this->get("/visitors/{$this->visitor->visitor_id}")->assertOk()
            ->assertDontSee($verifyUrl, false);

        $this->post($verifyUrl)->assertSessionHas('error', fn ($m) => str_contains($m, 'new Add Another PDL request'));

        $this->assertSame('rejected', $pdlB->fresh()->verification_status);
        $this->assertNull($pdlB->fresh()->verified_by);
        $this->assertCount(0, $this->notifications($this->visitor, 'relationship_approved'));
        // The account and the approved PDL A are untouched.
        $this->assertSame('verified', $this->visitor->fresh()->verification_status);
        $this->assertSame('verified', $this->relationship->fresh()->verification_status);
    }

    public function test_a_pending_relationship_still_shows_the_approve_action(): void
    {
        $pdlB = $this->pdlB();

        $this->get("/visitors/{$this->visitor->visitor_id}")->assertOk()
            ->assertSee(route('visitor.relationships.verify', [$this->visitor->visitor_id, $pdlB->relationship_id]), false);
    }

    public function test_a_rejected_relationship_takes_no_document_uploads(): void
    {
        $pdlB = $this->pdlB();
        $pdlB->update(['verification_status' => 'rejected']);

        Sanctum::actingAs($this->visitorAccount);
        $this->post("/api/relationships/{$pdlB->relationship_id}/requirements/marriage_certificate", [
            'file' => UploadedFile::fake()->image('marriage.png'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('status', 'rejected');
        $this->post("/api/relationships/{$pdlB->relationship_id}/supporting-document", [
            'file' => UploadedFile::fake()->image('marriage.png'),
        ], ['Accept' => 'application/json'])->assertStatus(409);

        $this->assertSame(0, RelationshipDocument::where('relationship_id', $pdlB->relationship_id)->count());
        $this->assertSame('rejected', $pdlB->fresh()->verification_status);
    }

    public function test_a_rejected_document_in_a_pending_relationship_can_be_replaced_and_reviewed_again(): void
    {
        $pdlB = $this->pdlB();
        $old = RelationshipDocument::create([
            'relationship_id' => $pdlB->relationship_id, 'visitor_id' => $this->visitor->visitor_id,
            'requirement_key' => 'marriage_certificate', 'file_path' => 'relationship-documents/m.png',
            'original_name' => 'm.png', 'status' => 'pending', 'uploaded_at' => now()->subDay(),
        ]);
        $this->post("/relationship-documents/{$old->document_id}/reject", ['rejection_reason' => 'Wrong names on the certificate.'])
            ->assertSessionHas('success');

        Sanctum::actingAs($this->visitorAccount);
        $this->post("/api/relationships/{$pdlB->relationship_id}/requirements/marriage_certificate", [
            'file' => UploadedFile::fake()->image('marriage.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $latest = RelationshipDocument::where('relationship_id', $pdlB->relationship_id)
            ->where('requirement_key', 'marriage_certificate')->orderByDesc('document_id')->first();
        $this->assertNotSame($old->document_id, $latest->document_id);
        $this->assertSame('pending', $latest->status);
        $this->assertSame('rejected', $old->fresh()->status, 'The rejected file is kept as history.');
        $this->assertSame('pending', $pdlB->fresh()->verification_status);
        $this->assertSame('verified', $this->visitor->fresh()->verification_status);
        // Still in the PDL Links queue, and the officer can review the new file.
        $this->assertTrue(ReviewQueue::pdlLinksQuery()->whereKey($pdlB->relationship_id)->exists());

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->post("/relationship-documents/{$latest->document_id}/verify")->assertSessionHas('success');
        $this->assertSame('verified', $latest->fresh()->status);
    }
}
