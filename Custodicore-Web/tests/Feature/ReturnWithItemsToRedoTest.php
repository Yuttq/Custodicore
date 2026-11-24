<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\RelationshipDocument;
use App\Models\VisitorId;
use App\Services\ReturnForCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Return asks which items the visitor must upload again. The app only
 * accepts a new copy of a rejected item, so the ticked items are rejected
 * with the return; without that a returned visitor could not re-upload a
 * blurry ID that was still "waiting for review".
 */
class ReturnWithItemsToRedoTest extends TestCase
{
    use MakesTestImages;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private VisitorId $id;
    private RelationshipDocument $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures(completeRequirements: false);
        $this->withoutVite();
        Storage::fake('local');

        $this->visitor->update([
            'verification_status' => 'pending', 'verified_at' => null,
            'photo_path' => 'visitor-photos/current.png', 'photo_status' => 'pending',
        ]);
        $this->relationship->update(['verification_status' => 'pending', 'verified_by' => null, 'verified_at' => null]);
        $this->id = VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'N-1',
            'file_path' => 'visitor-ids/id.png', 'verification_status' => 'pending', 'uploaded_at' => now()->subDay(),
        ]);
        $this->doc = RelationshipDocument::create([
            'relationship_id' => null, 'visitor_id' => $this->visitor->visitor_id,
            'requirement_key' => 'birth_certificate', 'file_path' => 'relationship-documents/b.png',
            'original_name' => 'b.png', 'status' => 'pending', 'uploaded_at' => now()->subDay(),
        ]);
    }

    private function returnAccount(array $redo)
    {
        $this->actingAs($this->recordOfficerAccount, 'web');

        return $this->post(route('visitor.reject', $this->visitor->visitor_id), [
            'rejection_reason' => 'The ID picture is blurry. Please upload a clear copy.',
            'redo' => $redo,
        ]);
    }

    public function test_return_form_lists_the_items_that_can_be_redone(): void
    {
        $keys = ReturnForCorrection::items($this->visitor->fresh())->pluck('key')->all();
        $this->assertSame(['photo', 'id:' . $this->id->visitor_id_doc_id, 'doc:' . $this->doc->document_id], $keys);

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->get(route('account-reviews.index'))->assertOk()
            ->assertSee('What must they upload again?')
            ->assertSee('value="id:' . $this->id->visitor_id_doc_id . '"', false);
    }

    public function test_ticked_items_are_unlocked_for_the_visitor(): void
    {
        $this->returnAccount(['photo', 'id:' . $this->id->visitor_id_doc_id])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $visitor = $this->visitor->fresh();
        $this->assertSame('rejected', $visitor->verification_status);
        $this->assertSame('rejected', $visitor->photo_status);
        $this->assertSame('rejected', $this->id->fresh()->verification_status);
        $this->assertSame('The ID picture is blurry. Please upload a clear copy.', $this->id->fresh()->rejection_reason);
        // Not ticked: unchanged.
        $this->assertSame('pending', $this->doc->fresh()->status);

        // One notification, naming what to upload again.
        $note = Notification::where('account_id', $this->visitorAccount->account_id)->where('notification_type', 'account_rejected')->sole();
        $this->assertStringContainsString('Upload a new Photo, National ID', $note->message);

        // The app now accepts a new ID and photo, which sends the account back for review.
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', true)->assertJsonPath('canReplacePhoto', true);
        $this->post('/api/documents', ['documentType' => 'national_id', 'file' => $this->fakePhoto('id.png')], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertSame('pending', $this->visitor->fresh()->verification_status);
    }

    public function test_ticked_document_can_be_replaced(): void
    {
        $this->returnAccount(['doc:' . $this->doc->document_id])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $this->doc->fresh()->status);

        Sanctum::actingAs($this->visitorAccount);
        $this->post('/api/registration-documents/birth_certificate', ['file' => $this->fakePhoto('b2.png')], ['Accept' => 'application/json'])
            ->assertSuccessful();
        $this->assertSame(2, RelationshipDocument::where('visitor_id', $this->visitor->visitor_id)->whereNull('relationship_id')->count());
    }

    public function test_return_without_ticks_leaves_items_locked(): void
    {
        // Details only (name, birthday…): nothing is unlocked, as before.
        $this->returnAccount([])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $this->visitor->fresh()->verification_status);
        $this->assertSame('pending', $this->visitor->fresh()->photo_status);
        $this->assertSame('pending', $this->id->fresh()->verification_status);
        Sanctum::actingAs($this->visitorAccount);
        $this->getJson('/api/documents')->assertJsonPath('canUploadGovernmentId', false);
    }

    public function test_keys_for_other_visitors_or_bad_values_are_ignored(): void
    {
        $otherAccount = \App\Models\Account::create([
            'role_id' => $this->visitorRole->role_id, 'username' => 'other.visitor',
            'email' => 'other@example.com', 'password_hash' => 'x', 'status' => 'active',
        ]);
        $otherVisitor = \App\Models\VisitorProfile::create([
            'account_id' => $otherAccount->account_id, 'full_name' => 'Other Visitor', 'date_of_birth' => '1990-01-01',
            'gender' => 'male', 'contact_number' => '09170000099', 'verification_status' => 'pending',
        ]);
        $other = VisitorId::create([
            'visitor_id' => $otherVisitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'X',
            'file_path' => 'visitor-ids/x.png', 'verification_status' => 'pending',
        ]);

        $this->returnAccount(['id:' . $other->visitor_id_doc_id, 'nonsense', 'doc:abc'])->assertSessionHasNoErrors();

        $this->assertSame('pending', $other->fresh()->verification_status);
        $this->assertSame('pending', $this->id->fresh()->verification_status);
        $this->assertSame('rejected', $this->visitor->fresh()->verification_status);
    }
}
