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

    public function test_approval_needs_a_verified_id(): void
    {
        $visitor = $this->pendingVisitor();

        // No ID at all.
        $this->post("/visitors/{$visitor->visitor_id}/approve")->assertSessionHas('error');
        // Uploaded but not checked yet.
        $id = $this->id($visitor, 'pending');
        $this->post("/visitors/{$visitor->visitor_id}/approve")->assertSessionHas('error');
        $this->assertSame('pending', $visitor->fresh()->verification_status);

        $this->post("/visitors/{$visitor->visitor_id}/ids/{$id->visitor_id_doc_id}/verify");
        $this->post("/visitors/{$visitor->visitor_id}/approve")->assertSessionHas('success');
        $this->assertSame('verified', $visitor->fresh()->verification_status);
    }

    public function test_queue_lists_pending_accounts_with_their_ids(): void
    {
        $visitor = $this->pendingVisitor();
        $this->id($visitor, 'pending');

        $this->get('/account-reviews')
            ->assertOk()
            ->assertSee('Ana Reyes')
            ->assertSee('View ID')
            ->assertSee('Open and verify the ID before approving.')
            ->assertSee('title="Verify a government ID first"', false);

        // Sidebar shows how many are waiting.
        $this->get('/dashboard')->assertSee('Review Queue')->assertSee('class="nav-count"', false);
    }

    public function test_recheck_tab_lists_approved_visitors_without_a_verified_id(): void
    {
        // The fixture visitor is approved and has no ID record.
        $this->get('/account-reviews?tab=recheck')
            ->assertOk()
            ->assertSee($this->visitor->full_name)
            ->assertSee('approved before a verified government ID was required');

        $this->id($this->visitor, 'verified');
        $this->get('/account-reviews?tab=recheck')->assertSee('Every approved visitor has a verified ID.');
    }

    public function test_staff_can_open_the_uploaded_id_picture(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('visitor-ids/id.png', 'png-bytes');
        $visitor = $this->pendingVisitor();
        $id = $this->id($visitor, 'pending');

        $this->get("/visitors/{$visitor->visitor_id}/ids/{$id->visitor_id_doc_id}/file")->assertOk();

        // Only through the owning visitor, and only for Record Officers.
        $other = $this->pendingVisitor('Ben Cruz');
        $this->get("/visitors/{$other->visitor_id}/ids/{$id->visitor_id_doc_id}/file")->assertNotFound();
        $this->actingAs($this->frontDeskAccount, 'web')
            ->get("/visitors/{$visitor->visitor_id}/ids/{$id->visitor_id_doc_id}/file")->assertForbidden();
    }
}
