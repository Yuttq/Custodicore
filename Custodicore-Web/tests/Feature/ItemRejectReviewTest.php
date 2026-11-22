<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Services\AccountApproval;
use App\Services\ReviewQueue;
use App\Services\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Reject-only review of each submitted item (photo, government ID,
 * registration and relationship files):
 *   - an officer rejects one item with its own reason; only that item changes;
 *   - the visitor replaces only a rejected (or staff-reopened) item; pending
 *     and approved items stay locked; a replacement goes back to pending;
 *   - the final Approve verifies every pending item and never overrides a
 *     rejected one, and is refused while anything needs correcting or two IDs wait;
 *   - staff may ask an approved visitor for a new copy: the account stays
 *     approved, new bookings are blocked until Accept replacements;
 *   - duplicate IDs are set aside, never deleted.
 */
class ItemRejectReviewTest extends TestCase
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

    /** A pending app visitor with a pending photo, one pending ID and an approved PDL link. */
    private function pendingVisitor(string $name = 'Ana Reyes'): VisitorProfile
    {
        $slug = str_replace(' ', '.', strtolower($name));
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id, 'username' => $slug, 'email' => "{$slug}@example.com",
            'password_hash' => Hash::make('password'), 'status' => 'active',
        ]);
        $account->forceFill(['email_verified_at' => now()])->save();

        $visitor = VisitorProfile::create([
            'account_id' => $account->account_id, 'full_name' => $name, 'date_of_birth' => '1992-02-02',
            'contact_number' => '09171112222', 'verification_status' => 'pending',
            'photo_path' => "visitor-photos/{$slug}.png", 'photo_uploaded_at' => now()->subHour(), 'photo_status' => 'pending',
        ]);
        Storage::disk('local')->put("visitor-photos/{$slug}.png", 'photo');
        $this->id($visitor, 'pending');
        VisitorPdlRelationship::create([
            'visitor_id' => $visitor->visitor_id, 'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'immediate_family', 'priority_tier' => 'high_priority', 'verification_status' => 'verified',
        ]);

        return $visitor;
    }

    private function id(VisitorProfile $visitor, string $status, string $number = 'N-1', ?string $uploadedAt = null): VisitorId
    {
        $id = VisitorId::create([
            'visitor_id' => $visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => $number,
            'file_path' => "visitor-ids/{$number}.png", 'verification_status' => $status,
        ]);
        if ($uploadedAt) {
            VisitorId::whereKey($id->visitor_id_doc_id)->update(['uploaded_at' => $uploadedAt]);
        }

        return $id->fresh();
    }

    private function document(VisitorProfile $visitor, string $key, string $status, ?int $relationshipId = null): RelationshipDocument
    {
        return RelationshipDocument::create([
            'visitor_id' => $visitor->visitor_id, 'relationship_id' => $relationshipId, 'requirement_key' => $key,
            'file_path' => "relationship-documents/{$key}.png", 'original_name' => "{$key}.png",
            'status' => $status, 'uploaded_at' => now()->subDay(),
        ]);
    }

    private function asRecordOfficer(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    private function signInAs(VisitorProfile $visitor): void
    {
        Sanctum::actingAs(Account::find($visitor->account_id));
    }

    private function staffId(): int
    {
        return (int) \App\Models\StaffProfile::where('account_id', $this->recordOfficerAccount->account_id)->value('staff_id');
    }

    private function notices(VisitorProfile $visitor, string $type)
    {
        return Notification::where('account_id', $visitor->account_id)->where('notification_type', $type)->get();
    }

    // ---------------------------------------------------------------
    // Reject one item; only that item changes
    // ---------------------------------------------------------------

    public function test_rejecting_the_photo_changes_only_the_photo_and_notifies_with_the_reason(): void
    {
        $visitor = $this->pendingVisitor();
        $document = $this->document($visitor, 'birth_certificate', 'pending');
        $this->asRecordOfficer();

        $this->post(route('visitor.photo.reject', $visitor->visitor_id), ['rejection_reason' => 'Your face is covered by a mask.'])
            ->assertSessionHas('success');

        $visitor->refresh();
        $this->assertSame('rejected', $visitor->photo_status);
        $this->assertSame('Your face is covered by a mask.', $visitor->photo_rejection_reason);
        $this->assertSame($this->staffId(), (int) $visitor->photo_reviewed_by);
        $this->assertSame('pending', $visitor->verification_status);
        $this->assertSame('pending', VisitorId::where('visitor_id', $visitor->visitor_id)->value('verification_status'));
        $this->assertSame('pending', $document->fresh()->status);

        $notice = $this->notices($visitor, 'photo_rejected')->first();
        $this->assertNotNull($notice);
        $this->assertStringContainsString('Reason: Your face is covered by a mask.', $notice->message);
        $this->assertTrue(AuditLog::where('record_type', 'visitor_profiles')->where('record_id', $visitor->visitor_id)
            ->where('description', 'like', 'Rejected photo for Ana Reyes:%')->exists());
    }

    public function test_a_rejection_needs_a_reason_for_every_item(): void
    {
        $visitor = $this->pendingVisitor();
        $id = VisitorId::where('visitor_id', $visitor->visitor_id)->first();
        $document = $this->document($visitor, 'birth_certificate', 'pending');
        $this->asRecordOfficer();

        $this->post(route('visitor.photo.reject', $visitor->visitor_id), ['rejection_reason' => ''])->assertSessionHasErrors('rejection_reason');
        $this->post(route('visitor.ids.reject', [$visitor->visitor_id, $id->visitor_id_doc_id]), ['rejection_reason' => 'no'])->assertSessionHasErrors('rejection_reason');
        $this->post(route('relationship-documents.reject', $document->document_id), [])->assertSessionHasErrors('rejection_reason');

        $this->assertSame('pending', $visitor->fresh()->photo_status);
        $this->assertSame('pending', $id->fresh()->verification_status);
        $this->assertSame('pending', $document->fresh()->status);
    }

    public function test_each_relationship_file_is_rejected_on_its_own(): void
    {
        $visitor = $this->pendingVisitor();
        $relationship = $visitor->relationships()->first();
        $relationship->update(['relationship_type' => 'spouse', 'verification_status' => 'pending']);
        $marriage = $this->document($visitor, 'marriage_certificate', 'pending', $relationship->relationship_id);
        $other = $this->document($visitor, 'cenomar_pdl', 'pending', $relationship->relationship_id);
        $this->asRecordOfficer();

        $this->post(route('relationship-documents.reject', $marriage->document_id), ['rejection_reason' => 'The names do not match.'])
            ->assertSessionHas('success');

        $this->assertSame('rejected', $marriage->fresh()->status);
        $this->assertSame('The names do not match.', $marriage->fresh()->rejection_reason);
        $this->assertSame('pending', $other->fresh()->status);
        $this->assertSame('pending', $relationship->fresh()->verification_status);
        $this->assertCount(1, $this->notices($visitor, 'document_rejected'));
    }

    public function test_walk_in_photo_is_not_rejected_here(): void
    {
        $visitor = $this->pendingVisitor();
        $visitor->update(['registered_via' => 'walk_in']);
        $this->asRecordOfficer();

        $this->post(route('visitor.photo.reject', $visitor->visitor_id), ['rejection_reason' => 'Please retake it.'])
            ->assertSessionHas('error');
        $this->assertSame('pending', $visitor->fresh()->photo_status);
    }

    public function test_only_record_officers_reject_set_aside_or_accept(): void
    {
        $visitor = $this->pendingVisitor();
        $id = VisitorId::where('visitor_id', $visitor->visitor_id)->first();
        $this->actingAs($this->frontDeskAccount, 'web');

        $this->post(route('visitor.photo.reject', $visitor->visitor_id), ['rejection_reason' => 'Please retake it.'])->assertForbidden();
        $this->post(route('visitor.ids.set-aside', [$visitor->visitor_id, $id->visitor_id_doc_id]), ['reason' => 'Duplicate upload.'])->assertForbidden();
        $this->post(route('visitor.replacements.accept', $visitor->visitor_id))->assertForbidden();
    }

    // ---------------------------------------------------------------
    // What the visitor may replace
    // ---------------------------------------------------------------

    public function test_pending_items_cannot_be_replaced_even_when_the_account_is_returned(): void
    {
        $visitor = $this->pendingVisitor();
        $visitor->update(['verification_status' => 'rejected', 'rejection_reason' => 'Wrong birth date.']);
        $this->signInAs($visitor);

        $this->getJson('/api/documents')
            ->assertJsonPath('canReplacePhoto', false)
            ->assertJsonPath('canUploadGovernmentId', false)
            ->assertJsonPath('photoStatus', 'pending');
        $this->post('/api/me/photo', ['photo' => $this->fakePhoto()], ['Accept' => 'application/json'])->assertStatus(409);
        $this->post('/api/documents', ['documentType' => 'national_id', 'file' => $this->fakePhoto('id.png')], ['Accept' => 'application/json'])
            ->assertStatus(409);

        // No duplicate ID was created.
        $this->assertSame(1, VisitorId::where('visitor_id', $visitor->visitor_id)->count());
    }

    public function test_only_the_rejected_item_is_replaced_and_it_returns_to_pending(): void
    {
        $visitor = $this->pendingVisitor();
        $id = VisitorId::where('visitor_id', $visitor->visitor_id)->first();
        $this->asRecordOfficer();
        $this->post(route('visitor.ids.reject', [$visitor->visitor_id, $id->visitor_id_doc_id]), ['rejection_reason' => 'The ID is blurry.']);

        $this->signInAs($visitor);
        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', true)->assertJsonPath('canReplacePhoto', false);
        $this->post('/api/documents', ['documentType' => 'national_id', 'file' => $this->fakePhoto('id.png')], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame('rejected', $id->fresh()->verification_status);
        $this->assertSame(1, VisitorId::where('visitor_id', $visitor->visitor_id)->where('verification_status', 'pending')->count());
        $this->assertSame('pending', $visitor->fresh()->photo_status);
        $this->assertSame('pending', $visitor->fresh()->verification_status);
        // Locked again while it is reviewed.
        $this->signInAs($visitor);
        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', false);
    }

    public function test_approved_items_stay_locked_for_approved_visitors(): void
    {
        $this->visitor->update(['photo_path' => 'visitor-photos/m.png', 'photo_status' => 'verified']);
        $this->id($this->visitor, 'verified');
        Sanctum::actingAs($this->visitorAccount);

        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', false)->assertJsonPath('canReplacePhoto', false)
            ->assertJsonPath('bookingBlockedBy', []);
        $this->post('/api/documents', ['documentType' => 'passport', 'file' => $this->fakePhoto('id.png')], ['Accept' => 'application/json'])
            ->assertStatus(409);
        $this->post('/api/me/photo', ['photo' => $this->fakePhoto()], ['Accept' => 'application/json'])->assertStatus(409);
    }

    // ---------------------------------------------------------------
    // Final Approve
    // ---------------------------------------------------------------

    public function test_final_approve_verifies_every_pending_item_and_never_a_rejected_one(): void
    {
        $visitor = $this->pendingVisitor();
        $older = $this->id($visitor, 'rejected', 'N-0', now()->subDays(3)->toDateTimeString());
        $registration = $this->document($visitor, 'barangay_clearance', 'pending');
        $relationship = $visitor->relationships()->first();
        $linked = $this->document($visitor, 'birth_certificate', 'pending', $relationship->relationship_id);
        $this->asRecordOfficer();

        $this->post(route('visitor.approve', $visitor->visitor_id))->assertSessionHas('success');

        $visitor->refresh();
        $this->assertSame('verified', $visitor->verification_status);
        $this->assertSame('verified', $visitor->photo_status);
        $this->assertSame($this->staffId(), (int) $visitor->photo_reviewed_by);
        $this->assertSame('verified', VisitorId::where('visitor_id', $visitor->visitor_id)->where('id_number', 'N-1')->value('verification_status'));
        $this->assertSame('rejected', $older->fresh()->verification_status);
        $this->assertSame('verified', $registration->fresh()->status);
        $this->assertSame($this->staffId(), (int) $registration->fresh()->reviewed_by);
        $this->assertSame('verified', $linked->fresh()->status);
    }

    public function test_final_approve_is_refused_while_an_item_needs_replacing(): void
    {
        $visitor = $this->pendingVisitor();
        $document = $this->document($visitor, 'barangay_clearance', 'rejected');
        $this->asRecordOfficer();

        $this->post(route('visitor.approve', $visitor->visitor_id))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Barangay'));
        $this->assertSame('pending', $visitor->fresh()->verification_status);
        $this->assertSame('pending', $visitor->fresh()->photo_status);

        // Replaced: approval goes through, the old rejected file stays rejected.
        $replacement = $this->document($visitor, 'barangay_clearance', 'pending');
        $this->post(route('visitor.approve', $visitor->visitor_id))->assertSessionHas('success');
        $this->assertSame('rejected', $document->fresh()->status);
        $this->assertSame('verified', $replacement->fresh()->status);
    }

    public function test_final_approve_rechecks_the_photo_under_its_lock(): void
    {
        $visitor = $this->pendingVisitor();
        // Another officer rejects the photo after this copy was loaded.
        VisitorProfile::whereKey($visitor->visitor_id)->update(['photo_status' => 'rejected', 'photo_rejection_reason' => 'Blurry.']);
        $this->assertSame('pending', $visitor->photo_status);

        try {
            app(AccountApproval::class)->assertNothingToCorrect($visitor->fresh());
            $this->fail('The rejected photo should block approval.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
        $this->expectException(ValidationException::class);
        app(AccountApproval::class)->approve($visitor, $this->staffId(), null, null, null);
    }

    // ---------------------------------------------------------------
    // Duplicate IDs
    // ---------------------------------------------------------------

    public function test_approve_keeps_the_newest_of_duplicate_ids_and_sets_the_older_aside(): void
    {
        $visitor = $this->pendingVisitor();
        $older = VisitorId::where('visitor_id', $visitor->visitor_id)->first();
        VisitorId::whereKey($older->visitor_id_doc_id)->update(['uploaded_at' => now()->subDay()]);
        $newer = $this->id($visitor, 'pending', 'N-2');
        $this->asRecordOfficer();

        // No "Set aside duplicate" button any more: the card explains what Approve does.
        $this->get(route('account-reviews.index'))->assertOk()
            ->assertDontSee(route('visitor.ids.set-aside', [$visitor->visitor_id, $older->visitor_id_doc_id]), false)
            ->assertSee('Approve uses the newest one');

        $this->post(route('visitor.approve', $visitor->visitor_id))->assertSessionHas('success');
        $this->assertSame(0, Notification::where('account_id', $visitor->account_id)->where('notification_type', '!=', 'account_approved')->count());
        $this->assertSame('verified', $newer->fresh()->verification_status);
        $this->assertSame('superseded', $older->fresh()->verification_status);
        $this->assertSame(2, VisitorId::where('visitor_id', $visitor->visitor_id)->count());

        // Never shown to the visitor.
        $this->signInAs($visitor);
        $this->getJson('/api/documents')->assertJsonCount(1, 'governmentIds');
    }

    // ---------------------------------------------------------------
    // Approved visitor: request replacement, booking block, Accept replacements
    // ---------------------------------------------------------------

    public function test_request_replacement_blocks_booking_until_the_replacement_is_accepted(): void
    {
        $id = $this->id($this->visitor, 'verified');
        $this->asRecordOfficer();
        $this->post(route('visitor.ids.reject', [$this->visitor->visitor_id, $id->visitor_id_doc_id]), ['rejection_reason' => 'The ID has expired.'])
            ->assertSessionHas('success');

        $this->assertSame('verified', $this->visitor->fresh()->verification_status);
        $this->assertSame(['Government ID'], $this->visitor->fresh()->bookingBlockers());
        $this->assertBookingBlocked();

        // The visitor replaces only that ID.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', true)->assertJsonPath('bookingBlockedBy', ['Government ID']);
        $this->post('/api/documents', ['documentType' => 'passport', 'file' => $this->fakePhoto('id.png')], ['Accept' => 'application/json'])
            ->assertCreated();

        // Still blocked until staff accept it; listed in the Accounts queue.
        $this->assertBookingBlocked();
        $this->asRecordOfficer();
        $this->assertTrue(ReviewQueue::accountsQuery()->whereKey($this->visitor->visitor_id)->exists());
        $this->get(route('account-reviews.index'))->assertOk()
            ->assertSee('Approved · replacement to check')
            ->assertSee(route('visitor.replacements.accept', $this->visitor->visitor_id), false);

        $this->post(route('visitor.replacements.accept', $this->visitor->visitor_id))->assertSessionHas('success');

        $this->assertSame([], $this->visitor->fresh()->bookingBlockers());
        $this->assertSame('rejected', $id->fresh()->verification_status);
        $this->assertSame(1, VisitorId::where('visitor_id', $this->visitor->visitor_id)->where('verification_status', 'verified')->count());
        $this->assertCount(1, $this->notices($this->visitor, 'replacement_accepted'));
        $this->assertFalse(ReviewQueue::accountsQuery()->whereKey($this->visitor->visitor_id)->exists());
    }

    public function test_a_replacement_on_a_verified_relationship_is_uploaded_and_accepted_without_reopening_it(): void
    {
        $this->relationship->update(['relationship_type' => 'spouse']);
        $document = $this->document($this->visitor, 'marriage_certificate', 'verified', $this->relationship->relationship_id);
        $this->asRecordOfficer();
        $this->post(route('relationship-documents.reject', $document->document_id), ['rejection_reason' => 'The copy is unreadable.'])
            ->assertSessionHas('success');

        $this->assertSame('verified', $this->relationship->fresh()->verification_status);
        $this->assertBookingBlocked();

        Sanctum::actingAs($this->visitorAccount);
        $this->post("/api/relationships/{$this->relationship->relationship_id}/requirements/marriage_certificate",
            ['file' => $this->fakePhoto('bc.png')], ['Accept' => 'application/json'])->assertCreated();
        // A second upload while it waits for review is refused.
        Sanctum::actingAs($this->visitorAccount->fresh());
        $this->post("/api/relationships/{$this->relationship->relationship_id}/requirements/marriage_certificate",
            ['file' => $this->fakePhoto('bc2.png')], ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertBookingBlocked();

        $this->asRecordOfficer();

        $this->post(route('visitor.replacements.accept', $this->visitor->visitor_id))->assertSessionHas('success');
        $this->assertSame([], $this->visitor->fresh()->bookingBlockers());
        $this->assertSame('verified', RelationshipDocument::where('relationship_id', $this->relationship->relationship_id)
            ->orderByDesc('document_id')->value('status'));
    }

    public function test_officer_can_reject_one_replacement_and_accept_the_rest(): void
    {
        $this->visitor->update(['photo_path' => 'visitor-photos/m.png', 'photo_status' => 'verified']);
        $document = $this->document($this->visitor, 'marriage_certificate', 'verified', $this->relationship->relationship_id);
        $this->asRecordOfficer();
        $this->post(route('visitor.photo.reject', $this->visitor->visitor_id), ['rejection_reason' => 'Please send a recent photo.']);
        $this->post(route('relationship-documents.reject', $document->document_id), ['rejection_reason' => 'The copy is unreadable.']);

        // Both replacements arrive.
        $this->visitor->update(['photo_status' => 'pending']);
        $replacement = $this->document($this->visitor, 'marriage_certificate', 'pending', $this->relationship->relationship_id);

        $this->get(route('account-reviews.index'))->assertOk()
            ->assertSee(route('relationship-documents.reject', $replacement->document_id), false);

        // The new birth certificate is still not acceptable: reject it, accept the photo.
        $this->post(route('relationship-documents.reject', $replacement->document_id), ['rejection_reason' => 'Still unreadable.'])
            ->assertSessionHas('success');
        $this->post(route('visitor.replacements.accept', $this->visitor->visitor_id))->assertSessionHas('success');

        $this->assertSame('verified', $this->visitor->fresh()->photo_status);
        $this->assertSame('rejected', $replacement->fresh()->status);
        // The rejected replacement still blocks booking; nothing else does.
        $this->assertSame([$replacement->label()], $this->visitor->fresh()->bookingBlockers());
    }

    public function test_accept_replacements_needs_an_approved_account_and_a_replacement(): void
    {
        $visitor = $this->pendingVisitor();
        $this->asRecordOfficer();

        $this->post(route('visitor.replacements.accept', $visitor->visitor_id))->assertSessionHas('error');
        $this->post(route('visitor.replacements.accept', $this->visitor->visitor_id))->assertSessionHas('error');
        $this->assertSame('pending', $visitor->fresh()->photo_status);
    }

    // ---------------------------------------------------------------
    // Several Government IDs: the newest one is the current ID
    // ---------------------------------------------------------------

    public function test_with_several_approved_ids_only_the_newest_is_current(): void
    {
        $older = $this->id($this->visitor, 'verified', 'OLD-1', now()->subMonths(6)->toDateTimeString());
        $newest = $this->id($this->visitor, 'verified', 'NEW-1', now()->subDay()->toDateTimeString());
        $this->assertSame($newest->visitor_id_doc_id, $this->visitor->fresh()->currentGovernmentId()->visitor_id_doc_id);
        $this->asRecordOfficer();

        // Request replacement is offered on the newest ID only.
        $this->get(route('visitor.show', $this->visitor->visitor_id))->assertOk()
            ->assertSee(route('visitor.ids.reject', [$this->visitor->visitor_id, $newest->visitor_id_doc_id]), false)
            ->assertDontSee(route('visitor.ids.reject', [$this->visitor->visitor_id, $older->visitor_id_doc_id]), false);
        $this->post(route('visitor.ids.reject', [$this->visitor->visitor_id, $older->visitor_id_doc_id]), ['rejection_reason' => 'Old ID expired.'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'newer ID'));
        $this->assertSame('verified', $older->fresh()->verification_status);

        // Approved visitor with an approved current ID: nothing to replace, bookings open.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', false)->assertJsonPath('bookingBlockedBy', []);
    }

    public function test_an_older_approved_id_does_not_prevent_replacing_the_newest_and_bookings_wait_for_it(): void
    {
        $older = $this->id($this->visitor, 'verified', 'OLD-1', now()->subMonths(6)->toDateTimeString());
        $newest = $this->id($this->visitor, 'verified', 'NEW-1', now()->subDay()->toDateTimeString());
        $this->asRecordOfficer();
        $this->post(route('visitor.ids.reject', [$this->visitor->visitor_id, $newest->visitor_id_doc_id]), ['rejection_reason' => 'The ID has expired.'])
            ->assertSessionHas('success');

        // Only the newest ID reopened; the older one stays approved history; account stays approved.
        $this->assertSame('rejected', $newest->fresh()->verification_status);
        $this->assertSame('verified', $older->fresh()->verification_status);
        $this->assertSame('verified', $this->visitor->fresh()->verification_status);
        $this->assertSame(['Government ID'], $this->visitor->fresh()->bookingBlockers());
        $this->assertBookingBlocked();

        // The older approved ID does not stop the replacement.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', true)->assertJsonPath('bookingBlockedBy', ['Government ID']);
        $this->post('/api/documents', ['documentType' => 'passport', 'file' => $this->fakePhoto('id.png')], ['Accept' => 'application/json'])
            ->assertCreated();
        Sanctum::actingAs($this->visitorAccount->fresh());
        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', false);

        // Waiting for acceptance: still blocked, and listed for Accept replacements.
        $this->assertBookingBlocked();
        $this->asRecordOfficer();
        $this->post(route('visitor.replacements.accept', $this->visitor->visitor_id))->assertSessionHas('success');

        $this->assertSame([], $this->visitor->fresh()->bookingBlockers());
        $this->assertSame('verified', $older->fresh()->verification_status);
        $this->assertSame('rejected', $newest->fresh()->verification_status);
        $this->assertSame('verified', $this->visitor->fresh()->currentGovernmentId()->verification_status);
        $this->assertSame(3, VisitorId::where('visitor_id', $this->visitor->visitor_id)->count());
    }

    public function test_staff_multi_session_assignment_is_blocked_too(): void
    {
        $id = $this->id($this->visitor, 'verified');
        $this->asRecordOfficer();
        $this->post(route('visitor.ids.reject', [$this->visitor->visitor_id, $id->visitor_id_doc_id]), ['rejection_reason' => 'The ID has expired.']);

        try {
            app(VisitAssignmentService::class)->assignSessions(
                $this->visitor->fresh(),
                $this->relationship,
                \Carbon\CarbonImmutable::parse($this->schedule->schedule_date),
                ['09:00'],
            );
            $this->fail('A staff multi-session assignment should be blocked.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be booked until', collect($e->errors())->flatten()->first());
        }
    }

    // ---------------------------------------------------------------
    // Notification wording: rejected under review vs replacement requested
    // ---------------------------------------------------------------

    public function test_initial_rejections_keep_the_not_accepted_wording(): void
    {
        $visitor = $this->pendingVisitor();
        $id = VisitorId::where('visitor_id', $visitor->visitor_id)->first();
        $this->asRecordOfficer();

        $this->post(route('visitor.ids.reject', [$visitor->visitor_id, $id->visitor_id_doc_id]), ['rejection_reason' => 'The ID is blurry.']);
        $this->post(route('visitor.photo.reject', $visitor->visitor_id), ['rejection_reason' => 'Your face is covered.']);

        $document = $this->notices($visitor, 'document_rejected')->first();
        $this->assertSame('Document Not Accepted', $document->title);
        $this->assertStringStartsWith('Your National ID was not accepted. Reason: The ID is blurry.', $document->message);
        $photo = $this->notices($visitor, 'photo_rejected')->first();
        $this->assertSame('Photo Not Accepted', $photo->title);
        $this->assertStringStartsWith('Your photo was not accepted.', $photo->message);
    }

    public function test_requesting_a_replacement_of_an_approved_item_says_replacement_requested(): void
    {
        $this->relationship->update(['relationship_type' => 'spouse']);
        $this->visitor->update(['photo_path' => 'visitor-photos/m.png', 'photo_status' => 'verified']);
        $id = $this->id($this->visitor, 'verified');
        $registration = $this->document($this->visitor, 'barangay_clearance', 'verified');
        $file = $this->document($this->visitor, 'marriage_certificate', 'verified', $this->relationship->relationship_id);
        $this->asRecordOfficer();

        $this->post(route('visitor.photo.reject', $this->visitor->visitor_id), ['rejection_reason' => 'Please send a recent photo.'])->assertSessionHas('success');
        $this->post(route('visitor.ids.reject', [$this->visitor->visitor_id, $id->visitor_id_doc_id]), ['rejection_reason' => 'The ID has expired.'])->assertSessionHas('success');
        $this->post(route('relationship-documents.reject', $registration->document_id), ['rejection_reason' => 'Please send a newer clearance.'])->assertSessionHas('success');
        $this->post(route('relationship-documents.reject', $file->document_id), ['rejection_reason' => 'The copy is unreadable.'])->assertSessionHas('success');

        $photo = $this->notices($this->visitor, 'photo_rejected')->first();
        $this->assertSame('Replacement Requested', $photo->title);
        $this->assertStringStartsWith('Facility staff need a new photo of you. Reason: Please send a recent photo.', $photo->message);

        $documents = $this->notices($this->visitor, 'document_rejected');
        $this->assertCount(3, $documents);
        $this->assertTrue($documents->every(fn ($n) => $n->title === 'Replacement Requested'));
        $messages = $documents->pluck('message')->implode(' | ');
        $this->assertStringContainsString('Facility staff need a new copy of your National ID. Reason: The ID has expired.', $messages);
        $this->assertStringContainsString('Facility staff need a new copy of your Barangay Clearance. Reason: Please send a newer clearance.', $messages);
        $this->assertStringContainsString('Reason: The copy is unreadable.', $messages);
        $this->assertStringNotContainsString('was not accepted', $messages);

        // The visitor can replace exactly the reopened relationship file, and nothing else on that checklist.
        Sanctum::actingAs($this->visitorAccount);
        $requirements = collect($this->getJson('/api/documents')->json('relationships.0.requirements'));
        $this->assertTrue($requirements->firstWhere('key', 'marriage_certificate')['canUpload']);
        $this->assertSame('verified', $this->relationship->fresh()->verification_status);
    }

    public function test_set_aside_ids_are_labelled_set_aside_on_front_desk_and_admin_chips(): void
    {
        $html = view('admin.partials.status-chip', ['status' => 'superseded'])->render();
        $this->assertStringContainsString('Set aside', $html);
        $this->assertStringNotContainsString('Superseded', $html);
    }

    private function assertBookingBlocked(): void
    {
        try {
            app(VisitAssignmentService::class)->assign([
                'visitor_id' => $this->visitor->visitor_id,
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->schedule->schedule_id,
            ]);
            $this->fail('A staff assignment should be blocked.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be booked until', collect($e->errors())->flatten()->first());
        }
    }

    // ---------------------------------------------------------------
    // Migration backfill
    // ---------------------------------------------------------------

    public function test_migration_backfills_existing_photos(): void
    {
        // (The test transaction keeps SQLite's foreign keys on, so a rollback
        // here cannot show that child rows are kept; the migration itself runs
        // outside a transaction for that.)
        $pending = $this->pendingVisitor();

        $migration = require database_path('migrations/2026_11_22_000100_add_item_review_fields.php');
        $migration->down();
        // As before the migration: an approved visitor with a photo, a pending one with a photo.
        \Illuminate\Support\Facades\DB::table('visitor_profiles')->where('visitor_id', $this->visitor->visitor_id)
            ->update(['photo_path' => 'visitor-photos/old.png', 'verified_at' => now()->subWeek()]);
        $migration->up();

        $this->assertSame('pending', $pending->fresh()->photo_status);
        $approved = $this->visitor->fresh();
        $this->assertSame('verified', $approved->photo_status);
        $this->assertNotNull($approved->photo_reviewed_at);
        $this->assertSame(0, VisitorProfile::whereNull('photo_path')->whereNotNull('photo_status')->count());
    }
}
