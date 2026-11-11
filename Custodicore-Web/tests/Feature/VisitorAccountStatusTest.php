<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Admin → Visitor Management: the Warden can deactivate / reactivate a
 * visitor's ACCOUNT (accounts.status). Verification is never changed, the
 * visitor is signed out of the app, the gate refuses check-in, and every
 * change needs the Warden's password and is audit-logged.
 *
 * "Now" is Wednesday 2026-10-07 10:00 Asia/Manila; the visit is on
 * Friday 2026-10-09 at 09:00.
 */
class VisitorAccountStatusTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const WARDEN_PASSWORD = 'Warden-pass-123';

    private Account $warden;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        $this->publishSessions('2026-10-01', '2026-11-30');

        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'x.png',
            'verification_status' => 'verified',
        ]);

        $this->warden = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'warden',
            'email' => 'warden@example.com',
            'password_hash' => Hash::make(self::WARDEN_PASSWORD),
            'status' => 'active',
        ]);
    }

    private function setStatus(string $action, array $extra = [], ?Account $as = null)
    {
        return $this->actingAs($as ?? $this->warden, 'web')->patch(
            route('admin.visitors.account-status', $this->visitor->visitor_id),
            array_merge(['action' => $action, 'current_password' => self::WARDEN_PASSWORD], $extra)
        );
    }

    private function confirmedFridayVisit(): VisitRequest
    {
        Sanctum::actingAs($this->visitorAccount);
        $id = $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => '2026-10-09',
            'startTimes' => ['09:00'],
        ])->assertCreated()->json('id');
        $this->completeRequirements();

        return app(VisitAssignmentService::class)->approveVisitorRequest(VisitRequest::findOrFail($id));
    }

    public function test_deactivating_switches_off_the_account_only_and_signs_the_visitor_out(): void
    {
        $this->visitorAccount->createToken('mobile');

        $this->setStatus('deactivate', ['reason' => 'Banned by facility order'])
            ->assertRedirect(route('admin.visitors.show', $this->visitor->visitor_id))
            ->assertSessionHas('status');

        $this->assertSame('inactive', $this->visitorAccount->fresh()->status);
        $this->assertSame('verified', $this->visitor->fresh()->verification_status);
        $this->assertSame(0, $this->visitorAccount->tokens()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action_type' => 'update',
            'record_type' => 'accounts',
            'record_id' => $this->visitorAccount->account_id,
            'description' => "Deactivated visitor Maria D. Santos's account (password re-confirmed). Reason: Banned by facility order",
        ]);
    }

    public function test_reactivating_restores_access_and_keeps_verification(): void
    {
        $this->setStatus('deactivate', ['reason' => 'Mistake']);
        $this->setStatus('reactivate')->assertSessionHas('status');

        $this->assertSame('active', $this->visitorAccount->fresh()->status);
        $this->assertSame('verified', $this->visitor->fresh()->verification_status);
        $this->assertDatabaseHas('audit_logs', [
            'record_id' => $this->visitorAccount->account_id,
            'description' => "Reactivated visitor Maria D. Santos's account (password re-confirmed)",
        ]);
    }

    public function test_a_deactivated_visitor_cannot_use_the_app_and_can_after_reactivation(): void
    {
        $token = $this->visitorAccount->createToken('mobile')->plainTextToken;
        $this->setStatus('deactivate', ['reason' => 'Test']);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'maria.santos@example.com', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This account is not active. Contact facility staff.');

        $this->setStatus('reactivate');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'maria.santos@example.com', 'password' => 'password'])
            ->assertOk();
    }

    public function test_deactivating_needs_a_reason_and_the_wardens_password(): void
    {
        $this->setStatus('deactivate')->assertSessionHasErrors('reason');
        $this->setStatus('deactivate', ['reason' => 'x', 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');

        $this->assertSame('active', $this->visitorAccount->fresh()->status);
    }

    public function test_repeating_an_action_changes_nothing(): void
    {
        $this->setStatus('reactivate')->assertSessionHas('status', "Maria D. Santos's account is already active.");
        $this->assertDatabaseMissing('audit_logs', ['record_id' => $this->visitorAccount->account_id, 'action_type' => 'update']);
    }

    public function test_only_the_warden_can_change_a_visitors_account(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web')
            ->patch(route('admin.visitors.account-status', $this->visitor->visitor_id), [
                'action' => 'deactivate', 'reason' => 'x', 'current_password' => 'password',
            ])
            ->assertForbidden();

        $this->assertSame('active', $this->visitorAccount->fresh()->status);
    }

    public function test_the_staff_toggle_no_longer_accepts_a_visitor_account(): void
    {
        $this->actingAs($this->warden, 'web')
            ->patch(route('admin.users.toggle-status', $this->visitorAccount->account_id), [
                'current_password' => self::WARDEN_PASSWORD,
            ])
            ->assertNotFound();

        $this->assertSame('active', $this->visitorAccount->fresh()->status);
    }

    public function test_the_gate_refuses_a_saved_pass_after_deactivation_but_allows_it_after_reactivation(): void
    {
        $visit = $this->confirmedFridayVisit();
        Sanctum::actingAs($this->visitorAccount);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:05:00', 'Asia/Manila'));
        $token = $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertOk()->json('qrToken');

        $this->setStatus('deactivate', ['reason' => 'Banned']);

        $scan = fn () => $this->actingAs($this->frontDeskAccount, 'web')
            ->postJson('/front-desk/checkin-checkout/scan', ['qr_token' => $token]);
        $confirm = fn () => $this->actingAs($this->frontDeskAccount, 'web')
            ->postJson('/front-desk/checkin-checkout/confirm', [
                'qr_token' => $token,
                'id_surrendered_type' => 'national_id',
                'id_match_status' => 'matched',
            ]);

        $scan()->assertStatus(422)->assertJsonPath('message', "This visitor's account is deactivated. Do not admit them; refer them to the Warden.");
        $confirm()->assertStatus(422);
        $this->assertSame(0, $visit->checkins()->count());
        // The visit itself is not cancelled.
        $this->assertSame('confirmed', $visit->fresh()->status);

        $this->setStatus('reactivate');
        $scan()->assertOk();
    }

    public function test_profile_keeps_disabling_behind_a_popup_and_drops_the_sign_up_guess(): void
    {
        $this->visitor->forceFill(['relationship_hint' => 'Spouse'])->save();

        $page = $this->actingAs($this->warden, 'web')->get(route('admin.visitors.show', $this->visitor->visitor_id))
            ->assertOk()
            ->assertSee('disable-account-modal', false)
            ->assertSee('Reason (required)')
            ->assertDontSee('Said at sign-up');

        // The popup only opens on its own after a rejected reason.
        $this->assertStringNotContainsString("getElementById('disable-account-modal').showModal();</script>", $page->getContent());

        $this->setStatus('deactivate', ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->warden, 'web')->withSession(['errors' => session('errors')])
            ->get(route('admin.visitors.show', $this->visitor->visitor_id))
            ->assertSee("getElementById('disable-account-modal').showModal();</script>", false);
    }

    public function test_the_visitor_pages_show_the_account_status_separately(): void
    {
        $this->setStatus('deactivate', ['reason' => 'Test']);

        $this->actingAs($this->warden, 'web')->get(route('admin.visitors.index'))
            ->assertOk()->assertSee('Inactive')->assertSee('Verified');
        $this->actingAs($this->warden, 'web')->get(route('admin.visitors.show', $this->visitor->visitor_id))
            ->assertOk()->assertSee('Account deactivated')->assertSee('Enable Account');
    }
}
