<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\RelationshipDocument;
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

    /** A document the visitor sent with the registration (not linked to a PDL yet). */
    private function registrationDocument(VisitorProfile $visitor, string $key): RelationshipDocument
    {
        return RelationshipDocument::create([
            'visitor_id' => $visitor->visitor_id, 'relationship_id' => null, 'requirement_key' => $key,
            'file_path' => "docs/{$key}.png", 'original_name' => "{$key}.png", 'status' => 'pending', 'uploaded_at' => now(),
        ]);
    }

    private function assertNothingApproved(VisitorProfile $visitor, VisitorId $id): void
    {
        $this->assertSame('pending', $visitor->fresh()->verification_status);
        $this->assertSame('pending', $id->fresh()->verification_status);
        $this->assertSame(0, $visitor->relationships()->count());
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
        $this->assertNothingApproved($visitor, $id);

        // The PDL, but the parent's proof is missing: refused as a whole.
        $this->post("/visitors/{$visitor->visitor_id}/approve", $link)
            ->assertSessionHas('error', fn ($m) => str_contains($m, "PDL's PSA Birth Certificate") && str_contains($m, 'Nothing was approved'));
        $this->assertNothingApproved($visitor, $id);

        // One Approve: ID, account, PDL link and relationship together.
        $this->registrationDocument($visitor, 'pdl_birth_certificate');
        $this->post("/visitors/{$visitor->visitor_id}/approve", $link)->assertSessionHas('success');
        $this->assertSame('verified', $visitor->fresh()->verification_status);
        $this->assertSame('verified', $id->fresh()->verification_status);
        $this->assertSame($this->pdl->pdl_id, $visitor->relationships()->sole()->pdl_id);
        $this->assertSame('verified', $visitor->relationships()->sole()->verification_status);
    }

    public function test_an_unticked_confirmation_refuses_and_rolls_back_the_whole_approval(): void
    {
        $visitor = $this->pendingVisitor();
        $id = $this->id($visitor, 'pending');
        $document = $this->registrationDocument($visitor, 'guardianship_authorization');

        $this->post("/visitors/{$visitor->visitor_id}/approve", ['pdl_id' => $this->pdl->pdl_id, 'relationship_type' => 'legal_guardian'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'No immediate family available') && str_contains($m, 'Nothing was approved'));

        // The link, the document review and the ID approval were all rolled back.
        $this->assertNothingApproved($visitor, $id);
        $this->assertNull($document->fresh()->relationship_id);
        $this->assertSame('pending', $document->fresh()->status);
    }

    public function test_confirmations_ticked_on_the_card_approve_the_relationship_in_the_same_step(): void
    {
        $visitor = $this->pendingVisitor();
        $this->id($visitor, 'pending');
        $this->registrationDocument($visitor, 'guardianship_authorization');

        $this->post("/visitors/{$visitor->visitor_id}/approve", [
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'legal_guardian',
            'confirmations' => ['no_immediate_family'],
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'Relationship approved'));

        $relationship = $visitor->relationships()->sole();
        $this->assertSame('verified', $visitor->fresh()->verification_status);
        $this->assertSame('verified', $relationship->verification_status);
        $staffId = \App\Models\StaffProfile::where('account_id', $this->recordOfficerAccount->account_id)->value('staff_id');
        $this->assertSame($staffId, $relationship->confirmation('no_immediate_family')['by']);
    }

    public function test_a_minor_needs_the_accompanying_adults_name(): void
    {
        $visitor = $this->pendingVisitor();
        $visitor->update(['date_of_birth' => now()->subYears(10)->toDateString()]);
        $id = $this->id($visitor, 'pending');
        $this->registrationDocument($visitor, 'visitor_birth_certificate');
        $link = ['pdl_id' => $this->pdl->pdl_id, 'relationship_type' => 'child', 'confirmations' => ['accompanying_guardian']];

        // Ticked, but no name: refused.
        $this->post("/visitors/{$visitor->visitor_id}/approve", $link)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'name of the parent or guardian'));
        $this->assertNothingApproved($visitor, $id);

        $this->post("/visitors/{$visitor->visitor_id}/approve", $link + ['accompanying_adult' => 'Maria Reyes'])
            ->assertSessionHas('success');
        $relationship = $visitor->relationships()->sole();
        $this->assertSame('verified', $relationship->verification_status);
        $this->assertSame('Maria Reyes', $relationship->confirmation('accompanying_guardian')['note']);
    }

    public function test_a_live_in_partner_needs_the_children_together_answer(): void
    {
        $visitor = $this->pendingVisitor();
        $id = $this->id($visitor, 'pending');
        // Both CENOMARs only: no witness statement is needed without children.
        foreach (['cenomar_visitor', 'cenomar_pdl'] as $key) {
            $this->registrationDocument($visitor, $key);
        }
        $link = ['pdl_id' => $this->pdl->pdl_id, 'relationship_type' => 'live_in_partner'];

        $this->post("/visitors/{$visitor->visitor_id}/approve", $link)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'children together'));
        $this->assertNothingApproved($visitor, $id);

        $this->post("/visitors/{$visitor->visitor_id}/approve", $link + ['has_children_together' => '0'])
            ->assertSessionHas('success');
        $relationship = $visitor->relationships()->sole();
        $this->assertFalse($relationship->has_children_together);
        $this->assertSame('verified', $relationship->verification_status);
    }

    public function test_confirmations_complete_a_link_already_on_file(): void
    {
        $visitor = $this->pendingVisitor();
        $id = $this->id($visitor, 'pending');
        $relationship = \App\Models\VisitorPdlRelationship::create([
            'visitor_id' => $visitor->visitor_id, 'pdl_id' => $this->pdl->pdl_id, 'relationship_type' => 'legal_guardian',
            'priority_tier' => \App\Models\VisitorPdlRelationship::priorityTierFor('legal_guardian'), 'verification_status' => 'pending',
        ]);
        RelationshipDocument::create([
            'visitor_id' => $visitor->visitor_id, 'relationship_id' => $relationship->relationship_id, 'requirement_key' => 'guardianship_authorization',
            'file_path' => 'docs/g.png', 'original_name' => 'g.png', 'status' => 'pending', 'uploaded_at' => now(),
        ]);

        // The card lists the confirmation to tick for this link.
        $this->get('/account-reviews')->assertOk()
            ->assertSee('Officer confirmations')
            ->assertSee('name="confirmations[]" value="no_immediate_family"', false)
            ->assertSee('"state":"confirm"', false);

        $this->post("/visitors/{$visitor->visitor_id}/approve")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'No immediate family available'));
        $this->assertSame('pending', $visitor->fresh()->verification_status);
        $this->assertSame('pending', $id->fresh()->verification_status);
        $this->assertNull($relationship->fresh()->officer_confirmations);

        $this->post("/visitors/{$visitor->visitor_id}/approve", ['confirmations' => ['no_immediate_family']])
            ->assertSessionHas('success');
        $this->assertSame('verified', $relationship->fresh()->verification_status);
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
        $document = RelationshipDocument::create([
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
