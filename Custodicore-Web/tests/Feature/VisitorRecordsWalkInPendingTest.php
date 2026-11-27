<?php

namespace Tests\Feature;

use App\Models\VisitorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Walk-ins are approved or rejected at the Front Desk (they hold the original
 * requirements); Records only keeps the record. So Visitor Records' "Pending
 * Verification" matches the Review Queue (app sign-ups only), and a pending
 * walk-in shows "At Front Desk" instead of "Pending".
 */
class VisitorRecordsWalkInPendingTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->actingAs($this->recordOfficerAccount, 'web');

        $make = function (string $name, string $via, int $i) {
            $account = \App\Models\Account::create([
                'role_id' => $this->visitorRole->role_id, 'username' => 'v' . $i,
                'email' => "v{$i}@example.com", 'password_hash' => 'x', 'status' => 'active',
            ]);
            VisitorProfile::create([
                'account_id' => $account->account_id, 'full_name' => $name, 'date_of_birth' => '1990-01-01',
                'gender' => 'female', 'contact_number' => '0917000000' . $i,
                'verification_status' => 'pending', 'registered_via' => $via,
            ]);
        };
        $make('App Pending', 'app', 1);
        $make('Walkin One', 'walk_in', 2);
        $make('Walkin Two', 'walk_in', 3);
    }

    public function test_pending_verification_card_counts_only_what_the_review_queue_shows(): void
    {
        $this->assertSame(1, \App\Services\ReviewQueue::accountsQuery()->count());

        $this->get(route('visitor.index'))->assertOk()
            ->assertSeeInOrder(['Pending Verification', '<div class="stat-value">1</div>'], false);
    }

    public function test_pending_walk_ins_are_labelled_at_front_desk(): void
    {
        $this->get(route('visitor.index'))
            ->assertSeeInOrder(['Walkin Two', 'AT FRONT DESK'])
            ->assertSeeInOrder(['App Pending', 'PENDING']);

        $walkIn = VisitorProfile::where('full_name', 'Walkin One')->sole();
        $this->get(route('visitor.show', $walkIn->visitor_id))->assertOk()
            ->assertSee('AT FRONT DESK')
            ->assertDontSee('Approve Visitor');
    }

    public function test_status_filter_separates_review_queue_and_front_desk(): void
    {
        $this->get(route('visitor.index', ['status' => 'pending']))
            ->assertSee('App Pending')->assertDontSee('Walkin One')->assertDontSee('One, Walkin')->assertDontSee('Walkin Two')->assertDontSee('Two, Walkin');

        $this->get(route('visitor.index', ['status' => 'walk_in_pending']))
            ->assertSee('Walkin One')->assertSee('Walkin Two')->assertDontSee('App Pending')->assertDontSee('Pending, App');

        $this->get(route('visitor.index', ['status' => 'verified']))
            ->assertSee('Maria D. Santos')->assertDontSee('App Pending')->assertDontSee('Pending, App');
    }
}
