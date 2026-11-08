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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 5A: additional PDLs of approved visitors — the Review Queue's PDL
 * Links list, the visitor's notifications, and every relationship (rejected
 * ones included) in GET /api/documents for the app's "My PDLs".
 */
class PdlLinksAndNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

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

    private function pendingLink(VisitorProfile $visitor, Pdl $pdl, string $type = 'spouse'): VisitorPdlRelationship
    {
        return VisitorPdlRelationship::create([
            'visitor_id' => $visitor->visitor_id,
            'pdl_id' => $pdl->pdl_id,
            'relationship_type' => $type,
            'priority_tier' => VisitorPdlRelationship::priorityTierFor($type),
            'verification_status' => 'pending',
        ]);
    }

    private function pendingAccount(string $name): VisitorProfile
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => str_replace(' ', '.', strtolower($name)),
            'email' => str_replace(' ', '.', strtolower($name)) . '@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);

        return VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => $name,
            'date_of_birth' => '1992-02-02',
            'contact_number' => '09171112222',
            'verification_status' => 'pending',
        ]);
    }

    /** Every requirement of a spouse link met: a verified ID and marriage certificate. */
    private function completeSpouseRequirements(VisitorPdlRelationship $relationship): void
    {
        VisitorId::create([
            'visitor_id' => $relationship->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'N-' . $relationship->relationship_id,
            'file_path' => 'visitor-ids/id.png',
            'verification_status' => 'verified',
        ]);
        RelationshipDocument::create([
            'relationship_id' => $relationship->relationship_id,
            'requirement_key' => 'marriage_certificate',
            'file_path' => 'relationship-documents/marriage_certificate.png',
            'status' => 'verified',
            'uploaded_at' => now(),
        ]);
    }

    private function notificationsOf(VisitorProfile $visitor, string $type)
    {
        return Notification::where('account_id', $visitor->account_id)->where('notification_type', $type)->get();
    }

    public function test_pdl_links_lists_pending_links_of_approved_visitors_only(): void
    {
        $second = $this->newPdl('Second PDL', 'PDL-TEST-002');
        $link = $this->pendingLink($this->visitor, $second);
        // A pending account's PDL belongs to Step 1 (Accounts), not here.
        $pendingVisitor = $this->pendingAccount('Paula Pending');
        $this->pendingLink($pendingVisitor, $second, 'parent');

        $this->assertSame(1, ReviewQueue::counts()['pdl_links']);

        $this->get('/review-queue/pdl-links')
            ->assertOk()
            ->assertSee('PDL Links')
            ->assertSee($this->visitor->full_name)
            ->assertSee('Second PDL')
            ->assertSee('Spouse')
            ->assertSee('Pending')
            ->assertSee(route('visitor.show', $link->visitor_id) . '#relationships', false)
            ->assertDontSee('Paula Pending')
            // The fixture's verified relationship is not waiting.
            ->assertDontSee('Example PDL');

        // The other Review Queue pages show the PDL Links tab too.
        $this->get('/account-reviews')->assertOk()->assertSee('PDL Links');
    }

    public function test_review_queue_opens_pdl_links_when_only_those_are_waiting(): void
    {
        $this->get('/review-queue')->assertRedirect(route('account-reviews.index'));

        $this->pendingLink($this->visitor, $this->newPdl('Second PDL', 'PDL-TEST-002'));

        $this->get('/review-queue')->assertRedirect(route('pdl-links.index'));
    }

    public function test_linking_an_additional_pdl_notifies_only_an_approved_visitor(): void
    {
        $second = $this->newPdl('Second PDL', 'PDL-TEST-002');

        $this->post(route('visitor.relationships.store', $this->visitor->visitor_id), [
            'pdl_id' => $second->pdl_id,
            'relationship_type' => 'spouse',
        ])->assertSessionHas('success');

        $sent = $this->notificationsOf($this->visitor, 'relationship_linked');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Second PDL', $sent->first()->message);
        $this->assertStringContainsString('Spouse', $sent->first()->message);

        // A pending account's PDL is approved with the account (its own notification).
        $pendingVisitor = $this->pendingAccount('Paula Pending');
        $this->post(route('visitor.relationships.store', $pendingVisitor->visitor_id), [
            'pdl_id' => $second->pdl_id,
            'relationship_type' => 'parent',
        ])->assertSessionHas('success');
        $this->assertCount(0, $this->notificationsOf($pendingVisitor, 'relationship_linked'));
    }

    public function test_approving_and_rejecting_notify_once_with_the_pdl_name(): void
    {
        $rejected = $this->pendingLink($this->visitor, $this->newPdl('Rejected PDL', 'PDL-TEST-002'));
        $url = route('visitor.relationships.reject', [$this->visitor->visitor_id, $rejected->relationship_id]);
        $reason = ['rejection_reason' => 'The marriage certificate names do not match.'];
        $this->post($url, $reason)->assertSessionHas('success');
        $this->post($url, $reason)->assertSessionHas('error'); // already rejected: no second notification

        $sent = $this->notificationsOf($this->visitor, 'relationship_rejected');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Rejected PDL', $sent->first()->message);

        $approved = $this->pendingLink($this->visitor, $this->newPdl('Approved PDL', 'PDL-TEST-003'));
        $this->completeSpouseRequirements($approved);
        $url = route('visitor.relationships.verify', [$this->visitor->visitor_id, $approved->relationship_id]);
        $this->post($url)->assertSessionHas('success');
        $this->post($url)->assertSessionHas('error'); // already approved: no second notification

        $sent = $this->notificationsOf($this->visitor, 'relationship_approved');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Approved PDL', $sent->first()->message);
        $this->assertSame('verified', $approved->fresh()->verification_status);
    }

    public function test_an_incomplete_link_is_not_approved_and_sends_nothing(): void
    {
        $link = $this->pendingLink($this->visitor, $this->newPdl('Second PDL', 'PDL-TEST-002'));

        $this->post(route('visitor.relationships.verify', [$this->visitor->visitor_id, $link->relationship_id]))
            ->assertSessionHas('error');

        $this->assertSame('pending', $link->fresh()->verification_status);
        $this->assertCount(0, $this->notificationsOf($this->visitor, 'relationship_approved'));
    }

    public function test_documents_api_lists_every_relationship_including_rejected(): void
    {
        $rejected = $this->pendingLink($this->visitor, $this->newPdl('Rejected PDL', 'PDL-TEST-002'));
        $rejected->update(['verification_status' => 'rejected']);

        Sanctum::actingAs($this->visitorAccount);
        $relationships = collect($this->getJson('/api/documents')->assertOk()->json('relationships'));

        $this->assertSame('rejected', $relationships->firstWhere('pdlName', 'Rejected PDL')['status']);
        $this->assertSame('verified', $relationships->firstWhere('pdlName', 'Example PDL')['status']);
        $this->assertSame('Spouse', $relationships->firstWhere('pdlName', 'Rejected PDL')['relationshipLabel']);
    }
}
