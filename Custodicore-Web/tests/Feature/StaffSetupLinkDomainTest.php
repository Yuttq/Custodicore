<?php

namespace Tests\Feature;

use App\Mail\StaffAccountInvitation;
use App\Models\Account;
use App\Models\Role;
use App\Services\Auth\StaffInvitationService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Officer setup links point to the configured public domain (APP_URL), not
 * to whatever host the Warden happens to be browsing on — the old Azure
 * hostname is flagged by Google Safe Browsing, so links to it are blocked
 * by Gmail. The token itself is unchanged: 64 random characters, stored
 * only as a SHA-256 hash, 72-hour expiry, single use.
 *
 * Also: the BJMP seal is not shown on the public sign-in / setup pages, but
 * stays on the signed-in staff pages.
 */
class StaffSetupLinkDomainTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://app.custodicore.me';
    private const OLD_HOST = 'https://custodicore-api-demo-duf0cjhjbnezg2bg.malaysiawest-01.azurewebsites.net';

    private Account $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->withoutVite();
        config(['app.url' => self::APP_URL, 'mail.default' => 'smtp']);
        Mail::fake();

        $this->admin = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'warden',
            'email' => 'warden@example.com',
            'password_hash' => Hash::make('Admin-pass-123'),
            'status' => 'active',
        ]);
    }

    private function registerOfficerFrom(string $host): Account
    {
        $this->actingAs($this->admin, 'web')
            ->post($host . '/admin/users', [
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'role_name' => 'Front Desk Officer',
                'contact_number' => '09181234567',
                'personal_email' => 'ana.reyes@example.com',
            ])
            ->assertRedirect();

        return Account::where('email', 'ana.reyes@example.com')->firstOrFail();
    }

    private function sentSetupUrl(): string
    {
        $url = null;
        Mail::assertSent(StaffAccountInvitation::class, function (StaffAccountInvitation $mail) use (&$url) {
            $url = $mail->setupUrl;

            return true;
        });

        return $url;
    }

    // ------------------------------------------------------------ Link domain

    public function test_setup_link_uses_app_url_even_when_the_warden_is_on_the_old_azure_host(): void
    {
        $this->registerOfficerFrom(self::OLD_HOST);

        $url = $this->sentSetupUrl();

        $this->assertStringStartsWith(self::APP_URL . '/staff/setup/', $url);
        $this->assertStringNotContainsString('azurewebsites.net', $url);
    }

    public function test_setup_link_uses_app_url_when_the_warden_is_on_the_new_domain(): void
    {
        $this->registerOfficerFrom(self::APP_URL);

        $this->assertStringStartsWith(self::APP_URL . '/staff/setup/', $this->sentSetupUrl());
    }

    public function test_a_trailing_slash_in_app_url_does_not_double_the_slash(): void
    {
        config(['app.url' => self::APP_URL . '/']);

        $this->assertSame(self::APP_URL . '/staff/setup/abc', app(StaffInvitationService::class)->setupUrl('abc'));
    }

    public function test_the_fallback_link_shown_to_the_warden_also_uses_app_url(): void
    {
        // Mail not connected: the link is flashed once for the Warden to hand over.
        config(['mail.default' => 'log']);

        $this->actingAs($this->admin, 'web')
            ->post(self::OLD_HOST . '/admin/users', [
                'first_name' => 'Ana', 'last_name' => 'Reyes', 'role_name' => 'Front Desk Officer',
                'contact_number' => '09181234567', 'personal_email' => 'ana.reyes@example.com',
            ])
            ->assertSessionHas('setup_link', fn ($link) => str_starts_with($link['url'], self::APP_URL . '/staff/setup/'));
    }

    // ------------------------------------------------------------ Token security (unchanged)

    public function test_token_is_64_random_characters_stored_only_as_a_sha256_hash_for_72_hours(): void
    {
        $this->travelTo(now()->startOfMinute());
        $account = $this->registerOfficerFrom(self::APP_URL)->fresh();
        $token = Str::afterLast($this->sentSetupUrl(), '/');

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $account->invitation_token_hash);
        $this->assertNotSame($token, $account->invitation_token_hash);
        $this->assertTrue($account->invitation_expires_at->equalTo(now()->addHours(72)));
        $this->assertSame('inactive', $account->status);
    }

    public function test_link_works_once_then_is_rejected(): void
    {
        $this->registerOfficerFrom(self::APP_URL);
        $token = Str::afterLast($this->sentSetupUrl(), '/');
        $service = app(StaffInvitationService::class);

        $this->assertSame(StaffInvitationService::OK, $service->find($token)[0]);
        $this->assertTrue($service->complete($token, 'Officer-pass-123!', 'ana.reyes', '09181234567'));

        $this->assertFalse($service->complete($token, 'Another-pass-456!', 'ana.reyes', '09181234567'));
        $this->assertSame(StaffInvitationService::INVALID, $service->find($token)[0]);
        $this->assertNull(Account::where('email', 'ana.reyes@example.com')->value('invitation_token_hash'));
    }

    public function test_link_expires_after_72_hours(): void
    {
        $this->registerOfficerFrom(self::APP_URL);
        $token = Str::afterLast($this->sentSetupUrl(), '/');
        $service = app(StaffInvitationService::class);

        $this->travel(72)->hours();
        $this->travel(1)->minutes();

        $this->assertSame(StaffInvitationService::EXPIRED, $service->find($token)[0]);
        $this->assertFalse($service->complete($token, 'Officer-pass-123!', 'ana.reyes', '09181234567'));
    }

    public function test_resending_replaces_the_old_link(): void
    {
        $account = $this->registerOfficerFrom(self::APP_URL);
        $oldToken = Str::afterLast($this->sentSetupUrl(), '/');

        $newUrl = app(StaffInvitationService::class)->send($account->fresh())['url'];

        $this->assertStringStartsWith(self::APP_URL . '/staff/setup/', $newUrl);
        $this->assertSame(StaffInvitationService::INVALID, app(StaffInvitationService::class)->find($oldToken)[0]);
        $this->assertSame(StaffInvitationService::OK, app(StaffInvitationService::class)->find(Str::afterLast($newUrl, '/'))[0]);
    }

    // ------------------------------------------------------------ BJMP seal

    public function test_public_login_page_shows_the_bjmp_seal(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('images/bjmp-logo.png', false)
            ->assertDontSee('Capstone project prototype. This is not an official BJMP website.');
    }

    public function test_public_setup_page_does_not_show_the_bjmp_seal(): void
    {
        $this->registerOfficerFrom(self::APP_URL);
        $url = $this->sentSetupUrl();
        auth()->logout();

        $this->get(Str::after($url, self::APP_URL))
            ->assertOk()
            ->assertDontSee('bjmp-logo', false);
    }

    public function test_signed_in_staff_pages_keep_the_bjmp_seal(): void
    {
        $this->actingAs($this->admin, 'web')
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('images/bjmp-logo.png', false);
    }
}
