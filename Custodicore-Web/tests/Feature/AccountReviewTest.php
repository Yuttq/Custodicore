<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Visitor account review: the Account Reviews queue, and approval needing a
 * staff-verified government ID.
 */
class AccountReviewTest extends TestCase
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

    private function pendingVisitor(string $name = 'Ana Reyes'): VisitorProfile
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

    private function id(VisitorProfile $visitor, string $status, ?string $file = 'visitor-ids/id.png'): VisitorId
    {
        return VisitorId::create([
            'visitor_id' => $visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'N-123',
            'file_path' => $file,
            'verification_status' => $status,
        ]);
    }

    public function test_one_approve_needs_an_id_and_the_pdl(): void
    {
        $visitor = $this->pendingVisitor();
        $link = ['pdl_id' => $this->pdl->pdl_id, 'relationship_type' => 'parent'];

        // No ID at all.
        $this->post("/visitors/{$visitor->visitor_id}/approve", $link)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'has not uploaded a government ID'));
        // An ID, but no PDL chosen.
        $id = $this->id($visitor, 'pending');
        $this->post("/visitors/{$visitor->visitor_id}/approve")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Choose the PDL'));
        $this->assertSame('pending', $visitor->fresh()->verification_status);
        $this->assertSame('pending', $id->fresh()->verification_status);

        // One Approve: ID, account and PDL link together.
        $this->post("/visitors/{$visitor->visitor_id}/approve", $link)->assertSessionHas('success');
        $this->assertSame('verified', $visitor->fresh()->verification_status);
        $this->assertSame('verified', $id->fresh()->verification_status);
        $this->assertSame($this->pdl->pdl_id, $visitor->relationships()->sole()->pdl_id);
    }

    public function test_queue_lists_pending_accounts_with_their_ids(): void
    {
        $visitor = $this->pendingVisitor();
        $this->id($visitor, 'pending');

        $this->get('/account-reviews')
            ->assertOk()
            ->assertSee('Ana Reyes')
            ->assertSee('View ID')
            ->assertSee('One-step approval')->assertSee('Approve will')
            ->assertSee('Check the ID and documents, confirm the PDL, then approve.')
            ->assertDontSee('>Verify<', false);

        // Sidebar shows how many are waiting.
        $this->get('/dashboard')->assertSee('Review Queue')->assertSee('class="nav-count"', false);
    }

    public function test_approved_without_id_tab_is_gone(): void
    {
        $this->get('/account-reviews')->assertOk()->assertDontSee('Approved without verified ID');
        // An old link to the removed tab just opens the waiting list.
        $this->get('/account-reviews?tab=recheck')->assertOk()->assertSee('Waiting for approval')
            ->assertDontSee('approved before a verified government ID was required');
    }

    public function test_staff_can_open_the_uploaded_id_picture(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('visitor-ids/id.png', 'png-bytes');
        $visitor = $this->pendingVisitor();
        $id = $this->id($visitor, 'pending');

        $this->get("/visitors/{$visitor->visitor_id}/ids/{$id->visitor_id_doc_id}/file")->assertOk();

        // Only through the owning visitor, and only for Record Officers.
        $other = $this->pendingVisitor('Ben Cruz');
        $this->get("/visitors/{$other->visitor_id}/ids/{$id->visitor_id_doc_id}/file")->assertNotFound();
        $this->actingAs($this->frontDeskAccount, 'web')
            ->get("/visitors/{$visitor->visitor_id}/ids/{$id->visitor_id_doc_id}/file")->assertForbidden();
    }

    public function test_one_approve_leaves_nothing_to_verify_afterwards(): void
    {
        $visitor = $this->pendingVisitor();
        $visitor->update(['intended_pdl_name' => $this->pdl->full_name, 'intended_relationship_type' => 'parent']);
        $id = $this->id($visitor, 'pending');
        // The PDL birth certificate a parent uploads at registration (not linked yet).
        $document = \App\Models\RelationshipDocument::create([
            'visitor_id' => $visitor->visitor_id, 'relationship_id' => null, 'requirement_key' => 'pdl_birth_certificate',
            'file_path' => 'docs/birth.png', 'original_name' => 'birth.png', 'status' => 'pending', 'uploaded_at' => now(),
        ]);

        $this->post("/visitors/{$visitor->visitor_id}/approve", ['pdl_id' => $this->pdl->pdl_id, 'relationship_type' => 'parent'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Linked to') && str_contains($m, 'Relationship approved'));

        $relationship = $visitor->relationships()->sole();
        $this->assertSame('verified', $visitor->fresh()->verification_status);
        $this->assertSame('verified', $id->fresh()->verification_status);
        $this->assertSame($relationship->relationship_id, $document->fresh()->relationship_id);
        $this->assertSame('verified', $document->fresh()->status);
        $this->assertSame('verified', $relationship->verification_status);
        $this->assertSame('Parent', $relationship->relationshipLabel());
    }

    public function test_card_prefills_the_pdl_and_relationship_the_visitor_gave(): void
    {
        $visitor = $this->pendingVisitor();
        $visitor->update(['intended_pdl_name' => $this->pdl->full_name, 'intended_relationship_type' => 'spouse']);
        $this->id($visitor, 'pending');

        $this->get('/account-reviews')->assertOk()
            ->assertSee('name="pdl_id" value="' . $this->pdl->pdl_id . '"', false)
            ->assertSee('Matches the name they gave')
            ->assertSee('<option value="spouse" selected', false)
            ->assertSee('As picked by the visitor in the app.');
    }
}
