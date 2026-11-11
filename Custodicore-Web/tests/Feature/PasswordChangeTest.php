<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PasswordChangeController;
use App\Mail\VisitorPasswordChanged;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * POST /api/me/password — Security Settings → Change Password in the app.
 * See PasswordChangeController for the rules.
 */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const OLD_PASSWORD = 'password'; // SeedsVisitAssignmentFixtures
    private const NEW_PASSWORD = 'NewSecret123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        Mail::fake();
    }

    private function change(string $token, array $overrides = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/me/password', array_merge([
            'currentPassword' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides));
    }

    private function tokenFor(Account $account): string
    {
        return $account->createToken('mobile')->plainTextToken;
    }

    private function passwordIs(string $password): bool
    {
        return Hash::check($password, $this->visitorAccount->fresh()->password_hash);
    }

    public function test_visitor_changes_password_keeps_this_device_and_signs_out_others(): void
    {
        $other = $this->tokenFor($this->visitorAccount);
        $mine = $this->tokenFor($this->visitorAccount);

        $this->change($mine)->assertOk()->assertJsonPath('status', 'password_changed');

        $this->assertTrue($this->passwordIs(self::NEW_PASSWORD));
        $this->assertNull(PersonalAccessToken::findToken($other));
        $this->assertNotNull(PersonalAccessToken::findToken($mine));

        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($mine)->getJson('/api/me')->assertOk();

        $this->postJson('/api/auth/login', ['email' => $this->visitorAccount->email, 'password' => self::NEW_PASSWORD])
            ->assertOk();
    }

    public function test_visitor_is_emailed_and_the_change_is_audited_without_the_password(): void
    {
        $this->change($this->tokenFor($this->visitorAccount))->assertOk();

        Mail::assertSent(VisitorPasswordChanged::class, fn ($m) => $m->hasTo($this->visitorAccount->email));

        $log = AuditLog::where('record_type', 'accounts')
            ->where('record_id', $this->visitorAccount->account_id)
            ->latest('audit_log_id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->visitorAccount->account_id, $log->account_id);
        $this->assertStringContainsString('changed their password', $log->description);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $log->description);
    }

    public function test_email_lists_the_time_and_what_to_do(): void
    {
        $mail = new VisitorPasswordChanged($this->visitorAccount, now());
        $html = $mail->render();

        $this->assertStringContainsString('Your password was changed', $html);
        $this->assertStringContainsString('Forgot password', $html);
        $this->assertStringNotContainsString('http', strip_tags(str_replace('http://www.w3.org', '', $html)));
    }

    public function test_wrong_current_password_is_refused_and_changes_nothing(): void
    {
        $token = $this->tokenFor($this->visitorAccount);

        $this->change($token, ['currentPassword' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['currentPassword'])
            ->assertJsonMissingValidationErrors(['password']);

        $this->assertTrue($this->passwordIs(self::OLD_PASSWORD));
        Mail::assertNothingSent();
    }

    public function test_too_many_wrong_current_passwords_lock_the_account_even_for_the_right_one(): void
    {
        $token = $this->tokenFor($this->visitorAccount);

        for ($i = 0; $i < PasswordChangeController::LOCK_MAX_FAILURES; $i++) {
            $this->change($token, ['currentPassword' => "wrong{$i}"])->assertStatus(422);
        }

        $this->change($token)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertTrue($this->passwordIs(self::OLD_PASSWORD));

        $this->travel(PasswordChangeController::LOCK_DECAY_SECONDS + 1)->seconds();
        $this->change($token)->assertOk();
    }

    public function test_new_password_must_meet_the_policy(): void
    {
        $token = $this->tokenFor($this->visitorAccount);

        foreach (['short1!A', 'alllowercase123!', 'NoNumbersHere!!', 'NoSymbols12345', 'Has Space123!'] as $weak) {
            $this->change($token, ['password' => $weak, 'password_confirmation' => $weak])
                ->assertStatus(422)->assertJsonValidationErrors(['password']);
        }

        $this->assertTrue($this->passwordIs(self::OLD_PASSWORD));
    }

    public function test_new_password_must_be_confirmed_and_differ_from_email_and_current(): void
    {
        $token = $this->tokenFor($this->visitorAccount);

        $this->change($token, ['password_confirmation' => 'Different123!'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $strongOld = 'OldSecret123!';
        $this->visitorAccount->forceFill(['password_hash' => Hash::make($strongOld)])->save();
        $this->change($token, ['currentPassword' => $strongOld, 'password' => $strongOld, 'password_confirmation' => $strongOld])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertTrue($this->passwordIs($strongOld));
        Mail::assertNothingSent();
    }

    public function test_requires_sign_in(): void
    {
        $this->postJson('/api/me/password', [
            'currentPassword' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnauthorized();
    }

    public function test_staff_tokens_cannot_use_it(): void
    {
        $staff = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'warden',
            'email' => 'warden@bjmp.gov.ph',
            'password_hash' => Hash::make(self::OLD_PASSWORD),
            'status' => 'active',
        ]);

        $this->change($this->tokenFor($staff))->assertForbidden();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $staff->fresh()->password_hash));
    }
}
