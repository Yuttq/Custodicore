<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\VisitorProfile;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GoogleIdTokenFixtures;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Guardian Information from mobile registration (Legal Guardian visitors):
 * optional on POST /auth/register and /auth/google/register (all three
 * fields or none), stored on visitor_profiles.guardian_*, and shown to
 * staff on the visitor details pages.
 */
class GuardianRegistrationDetailsTest extends TestCase
{
    use GoogleIdTokenFixtures;
    use MakesTestImages;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const EMAIL = 'guardian.visitor@example.com';
    private const PASSWORD = 'Secret-pass-1';

    private const GUARDIAN = [
        'guardianFullName' => 'Maria Santos Reyes',
        'guardianRelationship' => 'Aunt',
        'guardianContactNumber' => '09171234567',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedPhase1Fixtures();
        Mail::fake();
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Ana',
            'lastName' => 'Reyes',
            'fullName' => 'Ana Reyes',
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'dateOfBirth' => '1990-03-10',
            'gender' => 'female',
            'address' => 'Quezon City',
            'relationshipHint' => 'Legal Guardian',
            'intendedPdlName' => 'Pedro Santos Reyes',
            'contactNumber' => '09170001111',
            'acceptedTerms' => true,
            'acceptedPrivacy' => true,
            'photo' => $this->fakePhoto(),
            'consentVersion' => (string) config('legal.version'),
        ], $overrides);
    }

    private function profile(string $email = self::EMAIL): VisitorProfile
    {
        return Account::where('email', $email)->firstOrFail()->visitorProfile;
    }

    public function test_registration_saves_the_three_guardian_fields(): void
    {
        $this->postJson('/api/auth/register', $this->form(self::GUARDIAN))
            ->assertCreated()
            ->assertJsonPath('status', 'verification_required');

        $profile = $this->profile();
        $this->assertSame('Maria Santos Reyes', $profile->guardian_full_name);
        $this->assertSame('Aunt', $profile->guardian_relationship);
        $this->assertSame('09171234567', $profile->guardian_contact_number);
        $this->assertTrue($profile->hasGuardianDetails());
        // Not mixed into the visitor's own emergency contact.
        $this->assertNull($profile->emergency_contact_name);
    }

    public function test_snake_case_form_encoded_requests_are_accepted_and_trimmed(): void
    {
        $this->post('/api/auth/register', $this->form([
            'guardian_full_name' => '  Maria Santos Reyes ',
            'guardian_relationship' => 'Aunt',
            'guardian_contact_number' => '+63 917 123 4567',
        ]), ['Accept' => 'application/json'])->assertCreated();

        $profile = $this->profile();
        $this->assertSame('Maria Santos Reyes', $profile->guardian_full_name);
        $this->assertSame('+63 917 123 4567', $profile->guardian_contact_number);
    }

    public function test_registration_without_guardian_details_is_unchanged(): void
    {
        // Older clients and every other relationship send none.
        $this->postJson('/api/auth/register', $this->form(['relationshipHint' => 'Spouse']))
            ->assertCreated();

        $profile = $this->profile();
        $this->assertNull($profile->guardian_full_name);
        $this->assertNull($profile->guardian_relationship);
        $this->assertNull($profile->guardian_contact_number);
        $this->assertFalse($profile->hasGuardianDetails());
    }

    public function test_partial_guardian_details_are_rejected_and_nothing_is_created(): void
    {
        $accounts = Account::count();

        $this->postJson('/api/auth/register', $this->form([
            'guardianFullName' => 'Maria Santos Reyes',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'guardianRelationship' => 'Enter the guardian\'s relationship to you.',
                'guardianContactNumber' => 'Enter the guardian\'s contact number.',
            ])
            ->assertJsonMissingValidationErrors(['guardianFullName']);

        $this->assertSame($accounts, Account::count());
    }

    public function test_guardian_field_lengths_and_contact_format_are_validated(): void
    {
        $this->postJson('/api/auth/register', $this->form([
            'guardianFullName' => str_repeat('a', 151),
            'guardianRelationship' => str_repeat('b', 101),
            'guardianContactNumber' => 'call me',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guardianFullName', 'guardianRelationship', 'guardianContactNumber']);

        $this->postJson('/api/auth/register', $this->form(['guardianContactNumber' => '123456789012345678901'] + self::GUARDIAN))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guardianContactNumber']);

        $this->assertNull(Account::where('email', self::EMAIL)->first());
    }

    public function test_google_registration_saves_guardian_details_too(): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());

        $this->postJson('/api/auth/google/register', [
            'idToken' => $this->googleIdToken(),
        ] + $this->form(self::GUARDIAN))->assertCreated();

        $profile = $this->profile('google.visitor@gmail.com'); // GoogleIdTokenFixtures default
        $this->assertSame('Maria Santos Reyes', $profile->guardian_full_name);
        $this->assertSame('Aunt', $profile->guardian_relationship);
        $this->assertSame('09171234567', $profile->guardian_contact_number);
    }

    public function test_record_officer_sees_guardian_details_only_when_given(): void
    {
        // Existing fixture visitor: no guardian details, page still renders.
        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.show', $this->visitor->visitor_id))
            ->assertOk()
            ->assertDontSee('Guardian Full Name');

        $this->postJson('/api/auth/register', $this->form(self::GUARDIAN))->assertCreated();

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.show', $this->profile()->visitor_id))
            ->assertOk()
            ->assertSee('Guardian Full Name')
            ->assertSee('Maria Santos Reyes')
            ->assertSee('Aunt')
            ->assertSee('09171234567');
    }

    public function test_admin_visitor_page_shows_guardian_details(): void
    {
        $this->postJson('/api/auth/register', $this->form(self::GUARDIAN))->assertCreated();

        $role = Role::firstOrCreate(
            ['role_name' => 'System Administrator/Warden'],
            ['description' => 'Admin'],
        );
        $admin = Account::create([
            'role_id' => $role->role_id,
            'username' => 'warden',
            'email' => 'warden@bjmp.gov.ph',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'web')
            ->get(route('admin.visitors.show', $this->profile()->visitor_id))
            ->assertOk()
            ->assertSee('Maria Santos Reyes')
            ->assertSee('09171234567');

        $this->actingAs($admin, 'web')
            ->get(route('admin.visitors.show', $this->visitor->visitor_id))
            ->assertOk()
            ->assertDontSee('Guardian Full Name');
    }

    public function test_visitors_cannot_open_the_staff_page_with_guardian_details(): void
    {
        $this->postJson('/api/auth/register', $this->form(self::GUARDIAN))->assertCreated();

        $response = $this->actingAs($this->visitorAccount)
            ->get(route('visitor.show', $this->profile()->visitor_id));

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Maria Santos Reyes', (string) $response->getContent());
    }
}
