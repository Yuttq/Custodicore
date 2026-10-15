<?php

namespace Tests\Feature;

use App\Mail\VerifyVisitorEmail;
use App\Models\Account;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GoogleIdTokenFixtures;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * The optional "name of the PDL you want to visit" from normal and Google
 * registration: stored on the visitor profile as an unverified hint, shown
 * to the Record Officer, and never used to link a PDL or create a
 * relationship. Email verification and staff review are unchanged.
 */
class IntendedPdlNameRegistrationTest extends TestCase
{
    use GoogleIdTokenFixtures;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const EMAIL = 'juan.dela.cruz@example.com';
    private const GOOGLE_EMAIL = 'google.visitor@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        Mail::fake();
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'fullName' => 'Juan Miguel Dela Cruz',
            'email' => self::EMAIL,
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'dateOfBirth' => '1995-06-15',
            'gender' => 'male',
            'contactNumber' => '09170001111',
            'relationshipHint' => 'Spouse',
            'acceptedTerms' => true,
            'acceptedPrivacy' => true,
        ], $overrides);
    }

    private function profile(string $email = self::EMAIL): VisitorProfile
    {
        return Account::where('email', $email)->firstOrFail()->visitorProfile;
    }

    public function test_name_is_saved_on_the_profile_and_links_nothing(): void
    {
        // Even the exact name of an existing PDL must not link it.
        $this->pdl->update(['full_name' => 'Pedro Santos Reyes']);

        $this->postJson('/api/auth/register', $this->form(['intendedPdlName' => 'Pedro Santos Reyes']))
            ->assertCreated()
            ->assertJsonPath('status', 'verification_required')
            ->assertJsonMissingPath('token');

        $profile = $this->profile();
        $this->assertSame('Pedro Santos Reyes', $profile->intended_pdl_name);
        $this->assertSame('Spouse', $profile->relationship_hint);
        $this->assertSame('pending', $profile->verification_status);
        $this->assertSame(0, VisitorPdlRelationship::where('visitor_id', $profile->visitor_id)->count());
        $this->assertNull($profile->account->email_verified_at);
        Mail::assertSent(VerifyVisitorEmail::class, fn ($m) => $m->hasTo(self::EMAIL));
    }

    public function test_name_is_cleaned_and_snake_case_is_accepted(): void
    {
        $this->postJson('/api/auth/register', $this->form(['intended_pdl_name' => '  pedro   santos reyes ']))
            ->assertCreated();

        $this->assertSame('Pedro Santos Reyes', $this->profile()->intended_pdl_name);
    }

    public function test_registration_without_the_name_is_unchanged(): void
    {
        $this->postJson('/api/auth/register', $this->form())->assertCreated();
        $this->assertNull($this->profile()->intended_pdl_name);

        $this->postJson('/api/auth/register', $this->form(['email' => 'blank@example.com', 'intendedPdlName' => '   ']))
            ->assertCreated();
        $this->assertNull($this->profile('blank@example.com')->intended_pdl_name);
    }

    public static function invalidNames(): array
    {
        return [
            'too short' => ['A'],
            'too long' => [str_repeat('a', 151)],
            'digits' => ['Pedro 123'],
            'symbols' => ['Pedro #1; Reyes'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_name_is_rejected_and_nothing_is_created(string $name): void
    {
        $accounts = Account::count();

        $this->postJson('/api/auth/register', $this->form(['intendedPdlName' => $name]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['intendedPdlName']);

        $this->assertSame($accounts, Account::count());
        Mail::assertNothingSent();
    }

    public function test_google_registration_saves_the_name(): void
    {
        $this->postJson('/api/auth/google/register', ['idToken' => $this->googleIdToken()]
            + $this->form(['email' => null, 'intendedPdlName' => 'Pedro Santos Reyes']))
            ->assertCreated()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('user.verificationStatus', 'pending');

        $profile = $this->profile(self::GOOGLE_EMAIL);
        $this->assertSame('Pedro Santos Reyes', $profile->intended_pdl_name);
        $this->assertNotNull($profile->account->email_verified_at); // unchanged Google behaviour
        $this->assertSame(0, VisitorPdlRelationship::where('visitor_id', $profile->visitor_id)->count());
    }

    public function test_record_officer_sees_the_name_as_an_unverified_hint(): void
    {
        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.show', $this->visitor->visitor_id))
            ->assertOk()
            ->assertDontSee('PDL Name Given at Registration');

        $this->postJson('/api/auth/register', $this->form(['intendedPdlName' => 'Pedro Santos Reyes']))->assertCreated();

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.show', $this->profile()->visitor_id))
            ->assertOk()
            ->assertSee('PDL Name Given at Registration (unverified)')
            ->assertSee('Pedro Santos Reyes')
            ->assertSee('Add PDL Relationship');
    }
}
