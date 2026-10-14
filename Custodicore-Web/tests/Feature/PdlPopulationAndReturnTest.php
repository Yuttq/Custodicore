<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Pdl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * - PDL totals count only PDLs in custody (released/transferred/deceased are
 *   subtracted).
 * - Visitor accounts are "returned" for correction instead of "rejected"
 *   (wording only; the stored status stays 'rejected' for the mobile app).
 */
class PdlPopulationAndReturnTest extends TestCase
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

    private function addPdl(string $number, string $status): void
    {
        Pdl::create([
            'pdl_number' => $number,
            'full_name' => "Pdl {$number}",
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'classification' => 'drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => $status,
            'registered_by' => $this->pdl->registered_by,
        ]);
    }

    public function test_released_transferred_and_deceased_pdls_are_subtracted_from_the_total(): void
    {
        // Fixture PDL is active → 1 active + 1 more active.
        $this->addPdl('P-A2', 'active');
        $this->addPdl('P-R1', 'released');
        $this->addPdl('P-T1', 'transferred');
        $this->addPdl('P-D1', 'deceased');

        $this->get('/pdls')->assertOk()->assertViewHas('stats', fn ($s) => $s['total_population'] === 2 && $s['transferred'] === 1);
        $this->get('/dashboard')->assertOk()->assertViewHas('stats', fn ($s) => $s['total_pdls'] === 2 && $s['released'] === 1 && $s['transferred'] === 1);
    }

    public function test_transferring_a_pdl_lowers_the_total(): void
    {
        $this->get('/pdls')->assertViewHas('stats', fn ($s) => $s['total_population'] === 1);

        $this->pdl->update(['custody_status' => 'transferred']);

        $this->get('/pdls')->assertViewHas('stats', fn ($s) => $s['total_population'] === 0 && $s['transferred'] === 1);
    }

    public function test_visitor_account_is_returned_not_rejected(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);

        $this->get("/visitors/{$this->visitor->visitor_id}")->assertSee('>Return<', false)->assertDontSee('Reject Visitor');

        $this->post("/visitors/{$this->visitor->visitor_id}/reject", ['rejection_reason' => 'Upload a clearer ID photo.'])
            ->assertSessionHas('success', 'Account returned. The visitor has been notified of what to fix.');

        // Stored value unchanged for the mobile app.
        $this->assertSame('rejected', $this->visitor->fresh()->verification_status);
        $notification = Notification::where('account_id', $this->visitor->account_id)->latest('notification_id')->first();
        $this->assertSame('Account Returned for Correction', $notification->title);
        $this->assertStringContainsString('What to fix: Upload a clearer ID photo.', $notification->message);

        $this->get("/visitors/{$this->visitor->visitor_id}")->assertSee('RETURNED');
        $this->get('/account-reviews?tab=rejected')->assertSee('Returned')->assertSee('What to fix: Upload a clearer ID photo.');
    }
}
