<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\VisitorProfile;
use App\Services\WalkInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * An app sign-up whose name doesn't exactly match a walk-in (so sign-up
 * didn't stop it) but who may be the same person: the Review Queue card
 * warns the Records Officer. It is only a warning; approval is unchanged.
 */
class ReviewQueueWalkInMatchTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    private function visitor(string $name, string $via, string $born, string $phone, string $status = 'pending'): VisitorProfile
    {
        $this->n++;
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id, 'username' => "v{$this->n}",
            'email' => $via === 'walk_in' ? null : "v{$this->n}@example.com", 'password_hash' => 'x', 'status' => 'active',
        ]);

        return VisitorProfile::create([
            'account_id' => $account->account_id, 'full_name' => $name, 'date_of_birth' => $born,
            'gender' => 'female', 'contact_number' => $phone, 'verification_status' => $status, 'registered_via' => $via,
        ]);
    }

    public function test_similar_name_and_same_birthday_is_flagged(): void
    {
        $walkIn = $this->visitor('Ma. Cristina Reyes', 'walk_in', '1988-02-14', '09170000001', 'verified');
        $app = $this->visitor('Cristina Reyes-Lopez', 'app', '1988-02-14', '09179999999');

        $matches = WalkInService::possibleWalkInMatches($app);
        $this->assertCount(1, $matches);
        $this->assertTrue($matches[0]['visitor']->is($walkIn));
        $this->assertSame(['same birthday and a similar name'], $matches[0]['reasons']);

        $this->get(route('account-reviews.index'))->assertOk()
            ->assertSee('Possibly already registered as a walk-in')
            ->assertSee('Ma. Cristina Reyes')
            ->assertSee(route('visitor.show', $walkIn->visitor_id), false);
    }

    public function test_same_mobile_number_is_flagged_even_with_another_name(): void
    {
        $this->visitor('Josefina Bautista', 'walk_in', '1975-07-01', '09171234567', 'verified');
        $app = $this->visitor('Pina Garcia', 'app', '1980-01-01', '+63 917 123 4567');

        $matches = WalkInService::possibleWalkInMatches($app);
        $this->assertCount(1, $matches);
        $this->assertSame(['same mobile number'], $matches[0]['reasons']);
    }

    public function test_no_flag_for_unrelated_walk_ins(): void
    {
        // Same birthday but nothing else in common; similar name but another birthday.
        $this->visitor('Pedro Villanueva', 'walk_in', '1988-02-14', '09170000002', 'verified');
        $this->visitor('Cristina Reyes', 'walk_in', '1990-05-05', '09170000003', 'verified');
        // Another app sign-up is never a "walk-in match".
        $this->visitor('Cristina Reyes', 'app', '1988-02-14', '09170000004');
        $app = $this->visitor('Cristina Reyes-Lopez', 'app', '1988-02-14', '09179999999');

        $this->assertCount(0, WalkInService::possibleWalkInMatches($app));
        $this->get(route('account-reviews.index'))->assertOk()
            ->assertDontSee('Possibly already registered as a walk-in');
    }

    public function test_approval_is_not_blocked(): void
    {
        $this->visitor('Ma. Cristina Reyes', 'walk_in', '1988-02-14', '09170000001', 'verified');
        $app = $this->visitor('Cristina Reyes-Lopez', 'app', '1988-02-14', '09179999999');

        // The card still offers the usual review (it only warns).
        $this->get(route('account-reviews.index'))->assertOk()
            ->assertSee('approve-' . $app->visitor_id, false);
    }
}
