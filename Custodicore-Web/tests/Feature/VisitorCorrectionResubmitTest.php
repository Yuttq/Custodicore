<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Notification;
use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use App\Services\ReviewQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Visitor correction workflow:
 *   1. a returned account goes back to Account Review after a saved correction;
 *   2. a rejected registration document can be replaced from the app;
 *   3. the photo can be retaken until the account is approved;
 *   4. rejecting a single ID or document notifies the visitor with the reason.
 * Nothing is approved automatically; approved visitors keep their rules.
 */
class VisitorCorrectionResubmitTest extends TestCase
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

    private function visitorWithStatus(string $status, string $name = 'Ana Reyes'): VisitorProfile
    {
        $slug = str_replace(' ', '.', strtolower($name));
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => $slug,
            'email' => "{$slug}@example.com",
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);

        return VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => $name,
            'date_of_birth' => '1992-02-02',
            'contact_number' => '09171112222',
            'verification_status' => $status,
            'rejection_reason' => $status === 'rejected' ? 'The ID photo is unreadable.' : null,
        ]);
    }

    private function signInAs(VisitorProfile $visitor): void
    {
        Sanctum::actingAs(Account::find($visitor->account_id));
    }

    private function registrationDocument(VisitorProfile $visitor, string $key, string $status = 'pending'): RelationshipDocument
    {
        return RelationshipDocument::create([
            'visitor_id' => $visitor->visitor_id, 'relationship_id' => null, 'requirement_key' => $key,
            'file_path' => "relationship-documents/{$key}.png", 'original_name' => "{$key}.png",
            'status' => $status, 'rejection_reason' => $status === 'rejected' ? 'The page is cut off.' : null,
            'uploaded_at' => now()->subDay(),
        ]);
    }

    private function id(VisitorProfile $visitor, string $status = 'pending'): VisitorId
    {
        return VisitorId::create([
            'visitor_id' => $visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'N-123',
            'file_path' => 'visitor-ids/id.png', 'verification_status' => $status,
        ]);
    }

    private function asRecordOfficer(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    // ---------------------------------------------------------------
    // 1. Returned account → pending after a saved correction
    // ---------------------------------------------------------------

    public function test_profile_correction_moves_a_returned_account_back_to_pending(): void
    {
        $visitor = $this->visitorWithStatus('rejected');
        $this->signInAs($visitor);

        $this->patchJson('/api/me', ['address' => '12 Rizal St., Manila'])->assertOk();

        $visitor->refresh();
        $this->assertSame('pending', $visitor->verification_status);
        // Kept so the officer can see what was asked for.
        $this->assertSame('The ID photo is unreadable.', $visitor->rejection_reason);
        $this->assertTrue(ReviewQueue::accountsQuery()->whereKey($visitor->visitor_id)->exists());
        $this->assertDatabaseHas('audit_logs', ['record_type' => 'visitor_profiles', 'record_id' => $visitor->visitor_id]);
    }

    public function test_corrected_account_reappears_in_the_accounts_queue_not_returned_tab(): void
    {
        $visitor = $this->visitorWithStatus('rejected', 'Lea Bautista');
        $this->signInAs($visitor);
        $this->patchJson('/api/me', ['contactNumber' => '09179998888'])->assertOk();

        $this->asRecordOfficer();
        $this->get('/account-reviews')->assertOk()
            ->assertSee('Lea Bautista')
            ->assertSee('Resubmitted after being returned');
        $this->get('/account-reviews?tab=rejected')->assertOk()->assertDontSee('Lea Bautista');
    }

    public function test_invalid_or_empty_corrections_keep_the_account_returned(): void
    {
        $visitor = $this->visitorWithStatus('rejected');
        $this->signInAs($visitor);

        // Validation error.
        $this->patchJson('/api/me', ['dateOfBirth' => 'not-a-date'])->assertStatus(422);
        // Same value as before: nothing was corrected.
        $this->patchJson('/api/me', ['contactNumber' => '09171112222'])->assertOk();
        // Invalid ID upload.
        $this->post('/api/documents', [
            'documentType' => 'national_id',
            'file' => UploadedFile::fake()->create('id.txt', 5, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
        // Just reading.
        $this->getJson('/api/documents')->assertOk();
        $this->getJson('/api/me')->assertOk();

        $this->assertSame('rejected', $visitor->fresh()->verification_status);
    }

    public function test_new_government_id_moves_a_returned_account_back_to_pending(): void
    {
        $visitor = $this->visitorWithStatus('rejected');
        $this->id($visitor, 'rejected');
        $this->signInAs($visitor);

        $this->post('/api/documents', ['documentType' => 'national_id', 'file' => $this->fakePhoto('id.png')],
            ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('pending', $visitor->fresh()->verification_status);
        // Nothing is approved automatically.
        $this->assertSame(0, $visitor->idDocuments()->where('verification_status', 'verified')->count());
    }

    public function test_pending_and_approved_accounts_keep_their_status_on_upload(): void
    {
        $pending = $this->visitorWithStatus('pending');
        $this->signInAs($pending);
        $this->post('/api/documents', ['documentType' => 'national_id', 'file' => $this->fakePhoto('id.png')],
            ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('pending', $pending->fresh()->verification_status);

        Sanctum::actingAs($this->visitorAccount);
        $this->post('/api/documents', ['documentType' => 'national_id', 'file' => $this->fakePhoto('id.png')],
            ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('verified', $this->visitor->fresh()->verification_status);
    }

    // ---------------------------------------------------------------
    // 2. Replacing a rejected registration document
    // ---------------------------------------------------------------

    public function test_officer_can_reject_a_registration_document_before_approval_and_visitor_is_notified(): void
    {
        $visitor = $this->visitorWithStatus('pending');
        $document = $this->registrationDocument($visitor, 'marriage_certificate');

        $this->asRecordOfficer();
        $this->post("/relationship-documents/{$document->document_id}/reject", ['rejection_reason' => 'The page is cut off.'])
            ->assertSessionHas('success');

        $document->refresh();
        $this->assertSame('rejected', $document->status);
        $this->assertNull($document->relationship_id);
        $this->assertSame('pending', $visitor->fresh()->verification_status);

        $notification = Notification::where('account_id', $visitor->account_id)->sole();
        $this->assertSame('document_rejected', $notification->notification_type);
        $this->assertStringContainsString('Marriage Certificate', $notification->message);
        $this->assertStringContainsString('The page is cut off.', $notification->message);

        $this->get('/account-reviews')->assertSee('Rejected · waiting for a new file')->assertSee('Reason given: The page is cut off.');
    }

    public function test_registration_document_of_an_approved_visitor_still_cannot_be_reviewed_unlinked(): void
    {
        $document = $this->registrationDocument($this->visitor, 'marriage_certificate');

        $this->asRecordOfficer();
        $this->post("/relationship-documents/{$document->document_id}/reject", ['rejection_reason' => 'The page is cut off.'])
            ->assertSessionHas('error');

        $this->assertSame('pending', $document->fresh()->status);
        $this->assertSame(0, Notification::count());
    }

    public function test_walk_in_documents_keep_their_own_review_flow(): void
    {
        $visitor = $this->visitorWithStatus('pending', 'Walk In Visitor');
        $visitor->update(['registered_via' => 'walk_in']);
        $document = $this->registrationDocument($visitor, 'marriage_certificate');

        $this->asRecordOfficer();
        $this->post("/relationship-documents/{$document->document_id}/reject", ['rejection_reason' => 'The page is cut off.'])
            ->assertSessionHas('error');

        $this->assertSame('pending', $document->fresh()->status);
        $this->assertSame(0, Notification::count());
    }

    public function test_documents_api_shows_the_reason_and_lets_unapproved_visitors_replace(): void
    {
        $visitor = $this->visitorWithStatus('rejected');
        $this->registrationDocument($visitor, 'marriage_certificate', 'rejected');
        $this->signInAs($visitor);

        $this->getJson('/api/documents')->assertOk()
            ->assertJsonPath('registrationDocuments.0.status', 'rejected')
            ->assertJsonPath('registrationDocuments.0.rejectionReason', 'The page is cut off.')
            ->assertJsonPath('registrationDocuments.0.canReplace', true)
            ->assertJsonPath('canReplacePhoto', true);
    }

    public function test_returned_visitor_replaces_a_rejected_registration_document(): void
    {
        $visitor = $this->visitorWithStatus('rejected');
        $old = $this->registrationDocument($visitor, 'marriage_certificate', 'rejected');
        $this->signInAs($visitor);

        $this->post('/api/registration-documents/marriage_certificate', ['file' => $this->fakePhoto('cert.png')],
            ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('document.status', 'pending');

        // History kept: the rejected row stays, one new pending row is added.
        $this->assertSame('rejected', $old->fresh()->status);
        $rows = RelationshipDocument::where('visitor_id', $visitor->visitor_id)->where('requirement_key', 'marriage_certificate')->get();
        $this->assertCount(2, $rows);
        $new = $rows->firstWhere('status', 'pending');
        $this->assertNull($new->relationship_id);
        $this->assertNull($new->pdl_link_request_id);
        Storage::disk('local')->assertExists($new->file_path);

        $this->assertSame('pending', $visitor->fresh()->verification_status);
        $this->getJson('/api/documents')->assertJsonPath('registrationDocuments.0.status', 'pending');
    }

    public function test_pending_visitor_replaces_a_rejected_registration_document(): void
    {
        $visitor = $this->visitorWithStatus('pending');
        $this->registrationDocument($visitor, 'marriage_certificate', 'rejected');
        $this->signInAs($visitor);

        $this->post('/api/registration-documents/marriage_certificate', ['file' => $this->fakePhoto('cert.png')],
            ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('pending', $visitor->fresh()->verification_status);
    }

    public function test_replacement_is_refused_when_not_rejected_unknown_invalid_or_approved(): void
    {
        $visitor = $this->visitorWithStatus('rejected');
        $this->registrationDocument($visitor, 'marriage_certificate', 'pending');
        $this->signInAs($visitor);

        $this->post('/api/registration-documents/marriage_certificate', ['file' => $this->fakePhoto('cert.png')],
            ['Accept' => 'application/json'])->assertStatus(409);
        $this->post('/api/registration-documents/cenomar_pdl', ['file' => $this->fakePhoto('cert.png')],
            ['Accept' => 'application/json'])->assertStatus(404);

        RelationshipDocument::where('visitor_id', $visitor->visitor_id)->update(['status' => 'rejected']);
        $this->post('/api/registration-documents/marriage_certificate', [
            'file' => UploadedFile::fake()->create('cert.txt', 5, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(1, RelationshipDocument::where('visitor_id', $visitor->visitor_id)->count());
        $this->assertSame('rejected', $visitor->fresh()->verification_status);

        // Approved visitors use the relationship checklist instead.
        $this->registrationDocument($this->visitor, 'marriage_certificate', 'rejected');
        Sanctum::actingAs($this->visitorAccount);
        $this->post('/api/registration-documents/marriage_certificate', ['file' => $this->fakePhoto('cert.png')],
            ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_replaced_document_is_approved_only_through_one_step_approval(): void
    {
        $visitor = $this->visitorWithStatus('rejected');
        $this->id($visitor);
        $this->registrationDocument($visitor, 'marriage_certificate', 'rejected');
        $link = ['pdl_id' => $this->pdl->pdl_id, 'relationship_type' => 'spouse'];

        // While the newest file is rejected, one-step approval is refused.
        $this->asRecordOfficer();
        $this->post("/visitors/{$visitor->visitor_id}/approve", $link)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Marriage Certificate'));
        $this->assertSame('rejected', $visitor->fresh()->verification_status);

        $this->signInAs($visitor);
        $this->post('/api/registration-documents/marriage_certificate', ['file' => $this->fakePhoto('cert.png')],
            ['Accept' => 'application/json'])->assertCreated();
        $replacement = RelationshipDocument::where('visitor_id', $visitor->visitor_id)->where('status', 'pending')->sole();

        $this->asRecordOfficer();
        $this->post("/visitors/{$visitor->visitor_id}/approve", $link)->assertSessionHas('success');

        $this->assertSame('verified', $visitor->fresh()->verification_status);
        $this->assertSame('verified', $replacement->fresh()->status);
        $this->assertSame('verified', $visitor->relationships()->sole()->verification_status);
    }

    // ---------------------------------------------------------------
    // 3. Photo replacement
    // ---------------------------------------------------------------

    public function test_returned_visitor_retakes_the_photo_and_goes_back_to_pending(): void
    {
        $visitor = $this->visitorWithStatus('rejected');
        $visitor->update(['photo_path' => 'visitor-photos/old.png']);
        Storage::disk('local')->put('visitor-photos/old.png', 'old');
        $this->signInAs($visitor);

        $this->post('/api/me/photo', ['photo' => $this->fakePhoto()], ['Accept' => 'application/json'])->assertCreated();

        $visitor->refresh();
        $this->assertSame('pending', $visitor->verification_status);
        $this->assertNotSame('visitor-photos/old.png', $visitor->photo_path);
        Storage::disk('local')->assertMissing('visitor-photos/old.png');
        Storage::disk('local')->assertExists($visitor->photo_path);
    }

    public function test_pending_visitor_without_a_photo_adds_one_but_cannot_retake_it(): void
    {
        $visitor = $this->visitorWithStatus('pending');
        $this->signInAs($visitor);

        $this->post('/api/me/photo', ['photo' => $this->fakePhoto()], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('pending', $visitor->fresh()->verification_status);
        $path = $visitor->fresh()->photo_path;
        $this->assertNotNull($path);

        // Now under review with the registration: locked.
        $this->post('/api/me/photo', ['photo' => $this->fakePhoto('again.png')], ['Accept' => 'application/json'])
            ->assertStatus(409);
        $this->getJson('/api/documents')->assertJsonPath('canReplacePhoto', false);
        $this->assertSame($path, $visitor->fresh()->photo_path);
    }

    public function test_invalid_photo_keeps_a_returned_account_returned(): void
    {
        $visitor = $this->visitorWithStatus('rejected');
        $this->signInAs($visitor);

        $this->post('/api/me/photo', ['photo' => UploadedFile::fake()->create('me.pdf', 5, 'application/pdf')],
            ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame('rejected', $visitor->fresh()->verification_status);
    }

    public function test_approved_visitor_photo_stays_locked(): void
    {
        $this->visitor->update(['photo_path' => 'visitor-photos/approved.png']);
        Sanctum::actingAs($this->visitorAccount);

        $this->post('/api/me/photo', ['photo' => $this->fakePhoto()], ['Accept' => 'application/json'])->assertStatus(409);
        $this->getJson('/api/documents')->assertJsonPath('canReplacePhoto', false);
        $this->assertSame('visitor-photos/approved.png', $this->visitor->fresh()->photo_path);
    }

    // ---------------------------------------------------------------
    // 4. Notifications on individual rejections
    // ---------------------------------------------------------------

    public function test_rejecting_a_government_id_notifies_the_visitor_with_the_reason(): void
    {
        $visitor = $this->visitorWithStatus('pending');
        $id = $this->id($visitor);

        $this->asRecordOfficer();
        $this->post("/visitors/{$visitor->visitor_id}/ids/{$id->visitor_id_doc_id}/reject", ['rejection_reason' => 'The ID photo is blurry.'])
            ->assertSessionHas('success');
        // A second click is refused and sends nothing more.
        $this->post("/visitors/{$visitor->visitor_id}/ids/{$id->visitor_id_doc_id}/reject", ['rejection_reason' => 'The ID photo is blurry.'])
            ->assertSessionHas('error');

        $notification = Notification::where('account_id', $visitor->account_id)->sole();
        $this->assertSame('document_rejected', $notification->notification_type);
        $this->assertSame('Document Not Accepted', $notification->title);
        $this->assertStringContainsString($id->typeLabel(), $notification->message);
        $this->assertStringContainsString('The ID photo is blurry.', $notification->message);
        $this->assertSame('visitor_ids', $notification->related_record_type);

        // The app lists it with its category.
        Sanctum::actingAs(Account::find($visitor->account_id));
        $this->getJson('/api/notifications')->assertOk()->assertJsonFragment(['category' => 'document_rejected']);
    }

    public function test_rejecting_a_relationship_checklist_document_notifies_the_visitor(): void
    {
        $this->relationship->update(['verification_status' => 'pending', 'relationship_type' => 'spouse']);
        $document = RelationshipDocument::create([
            'visitor_id' => $this->visitor->visitor_id, 'relationship_id' => $this->relationship->relationship_id,
            'requirement_key' => 'marriage_certificate', 'file_path' => 'relationship-documents/m.png',
            'original_name' => 'm.png', 'status' => 'pending', 'uploaded_at' => now(),
        ]);

        $this->asRecordOfficer();
        $this->post("/relationship-documents/{$document->document_id}/reject", ['rejection_reason' => 'Wrong names on the certificate.'])
            ->assertSessionHas('success');
        $this->post("/relationship-documents/{$document->document_id}/reject", ['rejection_reason' => 'Wrong names on the certificate.'])
            ->assertSessionHas('error');

        $this->assertSame('rejected', $document->fresh()->status);
        $notification = Notification::where('account_id', $this->visitorAccount->account_id)->sole();
        $this->assertStringContainsString('Marriage Certificate', $notification->message);
        $this->assertStringContainsString('Wrong names on the certificate.', $notification->message);
        $this->assertSame('relationship_documents', $notification->related_record_type);
    }

    public function test_a_failed_notification_does_not_undo_the_rejection(): void
    {
        $visitor = $this->visitorWithStatus('pending');
        $id = $this->id($visitor);
        $document = $this->registrationDocument($visitor, 'marriage_certificate');
        Notification::creating(function () {
            throw new \RuntimeException('notifications are down');
        });

        $this->asRecordOfficer();
        $this->post("/visitors/{$visitor->visitor_id}/ids/{$id->visitor_id_doc_id}/reject", ['rejection_reason' => 'The ID photo is blurry.'])
            ->assertSessionHas('success');
        $this->post("/relationship-documents/{$document->document_id}/reject", ['rejection_reason' => 'The page is cut off.'])
            ->assertSessionHas('success');

        $this->assertSame('rejected', $id->fresh()->verification_status);
        $this->assertSame('rejected', $document->fresh()->status);
        $this->assertSame(0, Notification::count());
    }
}
