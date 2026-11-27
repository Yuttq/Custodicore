<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Pdl;
use App\Models\VisitorProfile;
use App\Services\WalkInService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * The name standard: visitors are asked Last / First / optional Middle /
 * optional Suffix; staff screens show "Last, First Middle Suffix"; the
 * stored full_name and the API's fullName stay in natural order, so
 * duplicate checks, walk-in matching and older app versions keep working.
 */
class VisitorNameStandardTest extends TestCase
{
    use MakesTestImages;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const EMAIL = 'juan.dela.cruz@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 08:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        Mail::fake();
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'fullName' => 'Juan Santos Dela Cruz Jr.',
            'lastName' => 'Dela Cruz',
            'firstName' => 'Juan',
            'middleName' => 'Santos',
            'suffix' => 'Jr.',
            'email' => self::EMAIL,
            'password' => 'Secret-pass-1',
            'password_confirmation' => 'Secret-pass-1',
            'dateOfBirth' => '1995-06-15',
            'gender' => 'male',
            'contactNumber' => '09170001111',
            'intendedPdlName' => 'Example PDL',
            'acceptedTerms' => true,
            'acceptedPrivacy' => true,
            'photo' => $this->fakePhoto(),
        ], $overrides);
    }

    private function registered(): VisitorProfile
    {
        return Account::where('email', self::EMAIL)->firstOrFail()->visitorProfile;
    }

    // --- Model ---------------------------------------------------------

    public function test_visitor_list_name_is_last_name_first_and_full_name_stays_natural(): void
    {
        $this->visitor->update(['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.']);
        $visitor = $this->visitor->fresh();

        $this->assertSame('Dela Cruz, Juan Santos Jr.', $visitor->listName());
        $this->assertSame('Juan Santos Dela Cruz Jr.', $visitor->full_name);

        $visitor->update(['middle_name' => null, 'suffix' => null]);
        $this->assertSame('Dela Cruz, Juan', $visitor->fresh()->listName());
        $this->assertSame('Juan Dela Cruz', $visitor->fresh()->full_name);
    }

    public function test_an_older_visitor_with_only_a_full_name_keeps_it(): void
    {
        // The fixture visitor has only full_name ("Maria D. Santos").
        $this->assertSame('Maria D. Santos', $this->visitor->listName());

        // Changing something else never rewrites the stored name.
        $this->visitor->update(['address' => 'Quezon City']);
        $this->assertSame('Maria D. Santos', $this->visitor->fresh()->full_name);
    }

    // --- Registration API --------------------------------------------------

    public function test_registration_stores_middle_name_and_suffix_with_a_natural_full_name(): void
    {
        $this->postJson('/api/auth/register', $this->registration())->assertCreated();

        $profile = $this->registered();
        $this->assertSame(
            ['Juan', 'Santos', 'Dela Cruz', 'Jr.', 'Juan Santos Dela Cruz Jr.'],
            [$profile->first_name, $profile->middle_name, $profile->last_name, $profile->suffix, $profile->full_name]
        );
        $this->assertSame('Dela Cruz, Juan Santos Jr.', $profile->listName());
    }

    public function test_an_older_app_without_middle_name_or_suffix_registers_as_before(): void
    {
        $this->postJson('/api/auth/register', $this->registration([
            'fullName' => 'Juan Dela Cruz', 'middleName' => null, 'suffix' => null,
        ]))->assertCreated();

        $profile = $this->registered();
        $this->assertSame('Juan Dela Cruz', $profile->full_name);
        $this->assertNull($profile->middle_name);
        $this->assertNull($profile->suffix);
    }

    public function test_suffix_is_normalized_and_unknown_ones_are_refused(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['suffix' => 'Esq.']))
            ->assertUnprocessable()->assertJsonValidationErrors('suffix');

        $this->postJson('/api/auth/register', $this->registration(['suffix' => 'iii']))->assertCreated();
        $this->assertSame('III', $this->registered()->suffix);
    }

    public function test_a_walk_in_with_a_middle_name_still_blocks_an_app_sign_up_by_first_and_last_name(): void
    {
        $account = Account::create([
            'role_id' => $this->visitorAccount->role_id, 'username' => 'walkin1', 'email' => null,
            'password_hash' => 'x', 'status' => 'active',
        ]);
        VisitorProfile::create([
            'account_id' => $account->account_id,
            'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz',
            'date_of_birth' => '1995-06-15', 'gender' => 'male', 'contact_number' => '09170002222',
            'verification_status' => 'pending', 'registered_via' => 'walk_in',
        ]);

        $this->assertNotNull(WalkInService::matchingWalkIn('Juan Dela Cruz', 'Juan', 'Dela Cruz', '1995-06-15'));
        $this->postJson('/api/auth/register', $this->registration(['fullName' => 'Juan Dela Cruz', 'middleName' => null, 'suffix' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('fullName');
    }

    // --- Profile API ---------------------------------------------------

    public function test_profile_update_with_parts_keeps_parts_and_full_name_in_sync(): void
    {
        $this->visitor->update(['verification_status' => 'pending', 'first_name' => 'Maria', 'last_name' => 'Santos']);
        Sanctum::actingAs($this->visitorAccount);

        $this->patchJson('/api/me', [
            'fullName' => 'Maria Dizon Santos-Reyes', 'lastName' => 'Santos-Reyes', 'firstName' => 'Maria', 'middleName' => 'Dizon',
        ])->assertOk()
            ->assertJsonPath('fullName', 'Maria Dizon Santos-Reyes')
            ->assertJsonPath('lastName', 'Santos-Reyes')
            ->assertJsonPath('middleName', 'Dizon')
            ->assertJsonPath('suffix', null);

        $visitor = $this->visitor->fresh();
        $this->assertSame('Maria Dizon Santos-Reyes', $visitor->full_name);
        $this->assertSame('Santos-Reyes, Maria Dizon', $visitor->listName());
    }

    public function test_an_older_app_changing_the_one_line_name_clears_the_stale_parts(): void
    {
        $this->visitor->update(['verification_status' => 'pending', 'first_name' => 'Maria', 'last_name' => 'Santos']);
        Sanctum::actingAs($this->visitorAccount);

        $this->patchJson('/api/me', ['fullName' => 'Maria Clara Santos'])->assertOk();

        $visitor = $this->visitor->fresh();
        $this->assertSame('Maria Clara Santos', $visitor->full_name);
        $this->assertNull($visitor->first_name);
        $this->assertNull($visitor->last_name);
        $this->assertSame('Maria Clara Santos', $visitor->listName(), 'Shown as typed, never with a stale first/last name.');
    }

    public function test_a_verified_visitor_cannot_change_name_parts(): void
    {
        $this->visitor->update(['first_name' => 'Maria', 'last_name' => 'Santos']);
        Sanctum::actingAs($this->visitorAccount);

        $this->patchJson('/api/me', ['lastName' => 'Reyes', 'firstName' => 'Maria'])->assertStatus(409);
        $this->assertSame('Santos', $this->visitor->fresh()->last_name);

        // Sending the same name again is not a change.
        $this->patchJson('/api/me', ['lastName' => 'Santos', 'firstName' => 'Maria', 'address' => 'Pasig'])->assertOk();
    }

    public function test_name_parts_need_both_first_and_last_name(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);
        Sanctum::actingAs($this->visitorAccount);

        $this->patchJson('/api/me', ['middleName' => 'Dizon'])
            ->assertUnprocessable()->assertJsonValidationErrors(['firstName', 'lastName']);
    }

    // --- Front Desk walk-in form --------------------------------------------

    public function test_walk_in_form_asks_last_first_middle_suffix(): void
    {
        $html = $this->actingAs($this->frontDeskAccount)->get(route('frontdesk.walk-ins.create'))->assertOk()->getContent();

        $positions = array_map(fn ($name) => strpos($html, 'name="'.$name.'"'), ['last_name', 'first_name', 'middle_name', 'suffix']);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Last, First, Middle, Suffix');
    }

    public function test_walk_in_registration_stores_middle_name_and_suffix(): void
    {
        $this->actingAs($this->frontDeskAccount)->post(route('frontdesk.walk-ins.store'), [
            'last_name' => 'reyes', 'first_name' => 'LOURDES', 'middle_name' => 'garcia', 'suffix' => 'iii',
            'date_of_birth' => '1970-05-02', 'gender' => 'female', 'contact_number' => '09171234567',
            'address' => 'Brgy. San Isidro, Quezon City',
            'photo' => UploadedFile::fake()->image('face.jpg'),
            'id_type' => 'national_id', 'government_id' => UploadedFile::fake()->image('id.jpg'),
            'pdl_id' => $this->pdl->pdl_id, 'requested_date' => '2026-10-16', 'requested_start_times' => ['09:00'],
            'visit_purpose_type' => 'Family visit', 'visit_purpose' => 'Family visit', 'relationship_type' => 'spouse',
            'documents' => ['marriage_certificate' => UploadedFile::fake()->create('marriage.pdf', 200, 'application/pdf')],
            'consent' => '1',
        ])->assertSessionHasNoErrors();

        $walkIn = VisitorProfile::where('registered_via', 'walk_in')->firstOrFail();
        $this->assertSame('Lourdes Garcia Reyes III', $walkIn->full_name);
        $this->assertSame('Reyes, Lourdes Garcia III', $walkIn->listName());
    }

    // --- Search and sorting ------------------------------------------------

    public function test_search_finds_a_name_typed_last_name_first(): void
    {
        $this->pdl->update(['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz']);
        $this->visitor->update(['first_name' => 'Maria', 'middle_name' => 'Dizon', 'last_name' => 'Santos']);

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('pdl.index', ['q' => 'Dela Cruz, Juan']))->assertOk()->assertSee('PDL-TEST-001');

        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.visitor-lookup', ['q' => 'Santos, Maria']))->assertOk()->assertSee('Santos, Maria Dizon');
        $this->actingAs($this->frontDeskAccount)
            ->get(route('frontdesk.visitor-lookup', ['q' => 'Maria Santos']))->assertOk()->assertSee('Santos, Maria Dizon');
    }

    public function test_visitor_lookup_is_alphabetical_by_last_name(): void
    {
        $this->visitor->update(['first_name' => 'Maria', 'last_name' => 'Santos']); // "Maria Santos"
        foreach ([['Zed', 'Abad'], ['Ana', 'Reyes']] as $i => [$first, $last]) {
            $account = Account::create([
                'role_id' => $this->visitorAccount->role_id, 'username' => "visitor{$i}", 'email' => "v{$i}@example.com",
                'password_hash' => 'x', 'status' => 'active',
            ]);
            VisitorProfile::create([
                'account_id' => $account->account_id, 'first_name' => $first, 'last_name' => $last,
                'date_of_birth' => '1990-01-01', 'gender' => 'female', 'contact_number' => '0917000000'.$i,
                'verification_status' => 'verified',
            ]);
        }

        // By first name it would be Ana, Maria, Zed; by last name Abad, Reyes, Santos.
        $this->actingAs($this->frontDeskAccount)->get(route('frontdesk.visitor-lookup'))
            ->assertOk()
            ->assertSeeInOrder(['Abad, Zed', 'Reyes, Ana', 'Santos, Maria']);
    }

    public function test_pdl_duplicate_check_still_compares_the_natural_full_name(): void
    {
        $this->pdl->update(['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'date_of_birth' => '1990-01-01']);

        $this->assertSame('Juan Santos Dela Cruz', $this->pdl->fresh()->full_name);
        $this->assertSame(
            $this->pdl->pdl_id,
            Pdl::findSamePerson(Pdl::composeFullName('Juan', 'Santos', 'Dela Cruz'), '1990-01-01')?->pdl_id
        );
    }
}
