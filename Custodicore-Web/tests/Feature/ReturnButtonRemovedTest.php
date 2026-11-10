<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Review Queue card: the decision is Approve or Return for the whole account
 * (no Reject on the ID or documents there). The visitor page has no Return
 * button and keeps its individual Reject buttons. Accounts that were returned
 * before keep working.
 */
class ReturnButtonRemovedTest extends TestCase
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

    private function visitorWithStatus(string $status): VisitorProfile
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id, 'username' => 'ana.reyes', 'email' => 'ana.reyes@example.com',
            'password_hash' => Hash::make('password'), 'status' => 'active',
        ]);
        $visitor = VisitorProfile::create([
            'account_id' => $account->account_id, 'full_name' => 'Ana Reyes', 'date_of_birth' => '1992-02-02',
            'contact_number' => '09171112222', 'verification_status' => $status,
            'rejection_reason' => $status === 'rejected' ? 'Please fix your address.' : null,
        ]);
        VisitorId::create([
            'visitor_id' => $visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'N-123',
            'file_path' => 'visitor-ids/id.png', 'verification_status' => 'pending',
        ]);

        return $visitor;
    }

    public function test_review_card_is_approve_or_return_and_visitor_page_keeps_reject(): void
    {
        $visitor = $this->visitorWithStatus('pending');
        $id = $visitor->idDocuments()->sole();
        $returnUrl = route('visitor.reject', $visitor->visitor_id);
        $idRejectUrl = route('visitor.ids.reject', [$visitor->visitor_id, $id->visitor_id_doc_id]);

        $this->actingAs($this->recordOfficerAccount, 'web');

        // Review Queue card: Approve or Return the whole account, no item Reject.
        $this->get('/account-reviews')->assertOk()
            ->assertSee($returnUrl, false)
            ->assertSee(route('visitor.approve', $visitor->visitor_id), false)
            ->assertDontSee($idRejectUrl, false);

        // Visitor page: no Return; the ID keeps its Reject.
        $this->get("/visitors/{$visitor->visitor_id}")->assertOk()
            ->assertDontSee($returnUrl, false)
            ->assertSee($idRejectUrl, false)
            ->assertSee(route('visitor.approve', $visitor->visitor_id), false);

        // Returning from the card sends it back with what to fix.
        $this->post($returnUrl, ['rejection_reason' => 'The ID photo is unreadable.'])->assertSessionHas('success');
        $this->assertSame('rejected', $visitor->fresh()->verification_status);
    }

    public function test_previously_returned_account_is_still_listed_and_can_resubmit(): void
    {
        $visitor = $this->visitorWithStatus('rejected');

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->get('/account-reviews?tab=rejected')->assertOk()
            ->assertSee('Ana Reyes')
            ->assertSee('Returned. What to fix: Please fix your address.');
        $this->get("/visitors/{$visitor->visitor_id}")->assertOk();

        // The visitor's correction moves it back to Account Review, as before.
        Sanctum::actingAs(Account::find($visitor->account_id));
        $this->patchJson('/api/me', ['address' => '12 Rizal St.'])->assertOk();
        $this->assertSame('pending', $visitor->fresh()->verification_status);
    }

    public function test_backend_return_route_still_works(): void
    {
        $visitor = $this->visitorWithStatus('pending');

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->post(route('visitor.reject', $visitor->visitor_id), ['rejection_reason' => 'Please fix your address.'])
            ->assertSessionHas('success');
        $this->assertSame('rejected', $visitor->fresh()->verification_status);
    }
}
