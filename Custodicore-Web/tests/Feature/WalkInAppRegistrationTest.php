<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\VisitorProfile;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\WalkInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\GoogleIdTokenFixtures;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * A walk-in (registered at the Front Desk) who later opens the app must
 * claim that account, not register a second one: two accounts for one person
 * would split the weekly limit and the one-PDL-per-day rule. App and Google
 * registration refuse a match on date of birth + name.
 */
class WalkInAppRegistrationTest extends TestCase
{
    use GoogleIdTokenFixtures;
    use MakesTestImages;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const PASSWORD = 'Secret-pass-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());
    }

    /** A walk-in as WalkInService::register() creates one. */
    private function walkIn(string $first = 'Lourdes', string $last = 'Reyes', string $dob = '1970-05-02'): VisitorProfile
    {
        $account = Account::create([
            'role_id' => Role::where('role_name', 'Visitor')->firstOrFail()->role_id,
            'username' => 'walkin.'.Str::slug("{$first} {$last}", '.'),
            'email' => null,
            'password_hash' => Hash::make(Str::random(64)),
            'status' => 'active',
        ]);

        return VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => "{$first} {$last}",
            'first_name' => $first,
            'last_name' => $last,
            'date_of_birth' => $dob,
            'gender' => 'female',
            'contact_number' => 'N/A',
            'verification_status' => 'verified',
            'registered_via' => 'walk_in',
        ]);
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Lourdes',
            'lastName' => 'Reyes',
            'fullName' => 'Lourdes Reyes',
            'email' => 'lourdes@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'dateOfBirth' => '1970-05-02',
            'gender' => 'female',
            'address' => 'Quezon City',
            'relationshipHint' => 'Spouse',
            'intendedPdlName' => 'Example PDL',
            'contactNumber' => '09170001111',
            'acceptedTerms' => '1',
            'acceptedPrivacy' => '1',
            'photo' => $this->fakePhoto(),
            'consentVersion' => (string) config('legal.version'),
        ], $overrides);
    }

    private function register(array $overrides = [])
    {
        return $this->post('/api/auth/register', $this->form($overrides), ['Accept' => 'application/json']);
    }

    public function test_a_walk_in_cannot_register_again_in_the_app(): void
    {
        $this->walkIn();
        $before = VisitorProfile::count();

        $this->register()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fullName' => WalkInService::ALREADY_A_WALK_IN_MESSAGE]);

        $this->assertSame($before, VisitorProfile::count());
        $this->assertFalse(Account::where('email', 'lourdes@example.com')->exists());
    }

    public function test_a_walk_in_with_an_email_is_told_to_use_forgot_password(): void
    {
        $this->walkIn()->account->update(['email' => 'desk.given@example.com']);

        $this->register()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fullName' => WalkInService::ALREADY_A_WALK_IN_WITH_EMAIL_MESSAGE])
            ->assertDontSee('desk.given@example.com');
    }

    public function test_the_match_ignores_case_spacing_and_a_middle_name(): void
    {
        $this->walkIn();

        // Different case and spacing in the full name.
        $this->register(['fullName' => '  lourdes   REYES ', 'firstName' => null, 'lastName' => null])
            ->assertJsonValidationErrors(['fullName' => WalkInService::ALREADY_A_WALK_IN_MESSAGE]);

        // A middle name in the full name, but the same first and last name.
        $this->register(['fullName' => 'Lourdes M. Reyes'])
            ->assertJsonValidationErrors(['fullName' => WalkInService::ALREADY_A_WALK_IN_MESSAGE]);
    }

    public function test_google_registration_is_refused_too(): void
    {
        $this->walkIn();

        $this->postJson('/api/auth/google/register', ['idToken' => $this->googleIdToken()] + $this->form(['email' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fullName' => WalkInService::ALREADY_A_WALK_IN_MESSAGE]);

        $this->assertSame(0, Account::whereNotNull('google_id')->count());
    }

    public function test_a_different_person_still_registers(): void
    {
        $this->walkIn();

        // Same name, different birthday.
        $this->register(['dateOfBirth' => '1971-05-02'])->assertCreated();

        // Same birthday, different name.
        $this->register(['email' => 'other@example.com', 'fullName' => 'Lourdes Cruz', 'lastName' => 'Cruz'])->assertCreated();
    }

    public function test_an_app_visitor_with_the_same_name_does_not_block_registration(): void
    {
        // Only walk-ins are matched: the fixture visitor registered in the app.
        $this->register([
            'fullName' => $this->visitor->full_name,
            'firstName' => null,
            'lastName' => null,
            'dateOfBirth' => $this->visitor->date_of_birth->toDateString(),
        ])->assertCreated();
    }
}
