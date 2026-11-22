<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\VisitorProfile;
use App\Services\ReturnedAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Returned accounts don't wait forever: one reminder after 7 days, closed
 * after 45 (or by staff), reopened by staff or by the visitor's correction.
 * The stored status stays 'rejected' throughout (nothing new for the app).
 */
class ReturnedAccountsFollowUpTest extends TestCase
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

    private function returnedDaysAgo(int $days): VisitorProfile
    {
        $this->visitor->update([
            'verification_status' => 'rejected',
            'rejection_reason' => 'Please correct your birthday.',
            'verified_at' => now()->subDays($days),
        ]);

        return $this->visitor->fresh();
    }

    private function notes(): \Illuminate\Support\Collection
    {
        return Notification::where('account_id', $this->visitorAccount->account_id)->orderBy('notification_id')->get();
    }

    public function test_no_reminder_before_seven_days(): void
    {
        $this->returnedDaysAgo(6);
        $this->artisan('visitors:returned-follow-up')->assertSuccessful();

        $this->assertNull($this->visitor->fresh()->return_reminded_at);
        $this->assertCount(0, $this->notes());
    }

    public function test_one_reminder_after_seven_days(): void
    {
        $this->returnedDaysAgo(8);
        $this->artisan('visitors:returned-follow-up')->assertSuccessful();
        $this->artisan('visitors:returned-follow-up')->assertSuccessful(); // second run: nothing more

        $this->assertNotNull($this->visitor->fresh()->return_reminded_at);
        $this->assertCount(1, $this->notes());
        $this->assertSame('account_rejected', $this->notes()->first()->notification_type);
        $this->assertStringContainsString('Please correct your birthday.', $this->notes()->first()->message);
        $this->assertNull($this->visitor->fresh()->return_closed_at);
    }

    public function test_closed_after_forty_five_days_status_unchanged(): void
    {
        $this->returnedDaysAgo(46);
        $this->artisan('visitors:returned-follow-up')->assertSuccessful();

        $visitor = $this->visitor->fresh();
        $this->assertNotNull($visitor->return_closed_at);
        $this->assertNull($visitor->return_closed_by);
        $this->assertSame('rejected', $visitor->verification_status);
        $this->assertSame(['Account Closed'], $this->notes()->pluck('title')->all()); // no reminder on the closing day

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->get(route('account-reviews.index', ['tab' => 'rejected']))->assertDontSee($visitor->full_name);
        $this->get(route('account-reviews.index', ['tab' => 'closed']))->assertOk()
            ->assertSee($visitor->full_name)
            ->assertSee('not corrected within 45 days')
            ->assertSee('Reopen');
    }

    public function test_staff_close_and_reopen(): void
    {
        $visitor = $this->returnedDaysAgo(2);
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->get(route('account-reviews.index', ['tab' => 'rejected']))->assertSee('closes automatically on');
        $this->post(route('visitor.return.close', $visitor->visitor_id))->assertSessionHas('success');
        $this->assertNotNull($visitor->fresh()->return_closed_by);
        $this->post(route('visitor.return.close', $visitor->visitor_id))->assertSessionHas('error'); // already closed

        $this->post(route('visitor.return.reopen', $visitor->visitor_id))->assertSessionHas('success');
        $this->assertNull($visitor->fresh()->return_closed_at);
        $this->get(route('account-reviews.index', ['tab' => 'rejected']))->assertSee($visitor->full_name);
    }

    public function test_only_returned_accounts_can_be_closed(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->post(route('visitor.return.close', $this->visitor->visitor_id))->assertSessionHas('error');
        $this->assertNull($this->visitor->fresh()->return_closed_at);
    }

    public function test_visitor_correction_reopens_a_closed_account_for_review(): void
    {
        $this->returnedDaysAgo(50);
        app(ReturnedAccounts::class)->closeOverdue();
        $this->assertNotNull($this->visitor->fresh()->return_closed_at);

        $this->visitor->fresh()->resubmitIfReturned('profile details: date of birth');

        $visitor = $this->visitor->fresh();
        $this->assertSame('pending', $visitor->verification_status);
        $this->assertNull($visitor->return_closed_at);
        $this->assertNull($visitor->return_reminded_at);
    }

    public function test_returning_again_resets_the_follow_up(): void
    {
        $this->returnedDaysAgo(10);
        app(ReturnedAccounts::class)->sendReminders();
        $this->visitor->fresh()->resubmitIfReturned('new photo');

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->post(route('visitor.reject', $this->visitor->visitor_id), ['rejection_reason' => 'Still the wrong birthday.'])
            ->assertSessionHas('success');

        $visitor = $this->visitor->fresh();
        $this->assertSame('rejected', $visitor->verification_status);
        $this->assertNull($visitor->return_reminded_at);
        $this->assertTrue($visitor->verified_at->isToday());
    }
}
