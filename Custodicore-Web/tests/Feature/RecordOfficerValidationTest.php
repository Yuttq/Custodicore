<?php

namespace Tests\Feature;

use App\Models\CellBlock;
use App\Models\Pdl;
use App\Models\PdlRestriction;
use App\Models\VisitorId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Input and state validation on Record Officer actions, plus the
 * "Are you sure?" popups on their forms.
 */
class RecordOfficerValidationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;
    use MakesTestImages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Storage::fake('local');
        $this->actingAs($this->recordOfficerAccount, 'web');
        CellBlock::create(['name' => 'Dorm 1', 'capacity' => 10, 'designation' => 'any']);
    }

    private function newPdl(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'date_of_birth' => '1991-05-05',
            'gender' => 'male',
            'classification' => 'drug_related',
            'admission_date' => now()->subMonth()->toDateString(),
            'cell_block' => 'Dorm 1',
            'photo' => $this->fakePhoto(),
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // PDL photo
    // -----------------------------------------------------------------
    public function test_photo_is_required_and_must_be_a_picture(): void
    {
        $this->post('/pdls', $this->newPdl(['photo' => null]))->assertSessionHasErrors(['photo' => 'Please add a photo of the PDL.']);
        $this->post('/pdls', $this->newPdl(['photo' => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf')]))
            ->assertSessionHasErrors('photo');
        $this->post('/pdls', $this->newPdl(['photo' => UploadedFile::fake()->create('big.png', 6000, 'image/png')]))
            ->assertSessionHasErrors('photo');

        $this->assertSame(1, Pdl::count());
        $this->assertSame([], Storage::disk('local')->allFiles('pdl-photos'));
    }

    public function test_photo_is_stored_privately_and_shown_to_staff(): void
    {
        $this->post('/pdls', $this->newPdl())->assertSessionHasNoErrors();
        $pdl = Pdl::where('full_name', 'Juan Dela Cruz')->firstOrFail();

        Storage::disk('local')->assertExists($pdl->photo_path);
        $this->get("/pdls/{$pdl->pdl_id}/photo")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/pdls')->assertSee($pdl->photoUrl(), false);
        $this->get("/pdls/{$pdl->pdl_id}")->assertSee('pdl-photo-lg pdl-photo-img', false);

        // Not a public file: other roles can't fetch it.
        $this->actingAs($this->frontDeskAccount, 'web')->get("/pdls/{$pdl->pdl_id}/photo")->assertForbidden();
    }

    public function test_replacing_the_photo_removes_the_old_file(): void
    {
        $this->post('/pdls', $this->newPdl())->assertSessionHasNoErrors();
        $pdl = Pdl::where('full_name', 'Juan Dela Cruz')->firstOrFail();
        $old = $pdl->photo_path;

        $this->put("/pdls/{$pdl->pdl_id}", [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'date_of_birth' => '1991-05-05',
            'gender' => 'male', 'classification' => 'drug_related',
            'admission_date' => $pdl->admission_date->toDateString(), 'cell_block' => 'Dorm 1',
            'custody_status' => 'active', 'current_password' => 'password',
            'photo' => $this->fakePhoto('new.png'),
        ])->assertSessionHasNoErrors();

        $new = $pdl->fresh()->photo_path;
        $this->assertNotSame($old, $new);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($new);
    }

    public function test_profile_shows_details_read_only_until_edit_is_opened(): void
    {
        $this->pdl->update(['cell_block' => 'Dorm 1']);

        $this->get("/pdls/{$this->pdl->pdl_id}")
            ->assertOk()
            ->assertSee('Edit Profile')
            ->assertSee('<dl class="pdl-details" data-profile-view >', false)
            ->assertSee('data-profile-edit hidden', false)
            ->assertSeeInOrder(['Cell/Block', 'Dorm 1']);

        // After a failed save the form comes back open, with the officer's input.
        $this->from("/pdls/{$this->pdl->pdl_id}")
            ->put("/pdls/{$this->pdl->pdl_id}", ['first_name' => 'Ex4mple', 'current_password' => 'password'])
            ->assertRedirect("/pdls/{$this->pdl->pdl_id}");
        $this->get("/pdls/{$this->pdl->pdl_id}")
            ->assertDontSee('data-profile-edit hidden', false)
            ->assertSee('value="Ex4mple"', false);
    }

    public function test_pdls_without_a_photo_show_initials(): void
    {
        $this->get('/pdls')->assertOk()->assertSee('>' . $this->pdl->initials() . '<', false);
        $this->get("/pdls/{$this->pdl->pdl_id}/photo")->assertNotFound();
    }

    private function profileUpdate(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Example',
            'last_name' => 'PDL',
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01',
            'custody_status' => 'released',
            'current_password' => 'password',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // PDL profile
    // -----------------------------------------------------------------
    public function test_pdl_names_reject_numbers_and_symbols(): void
    {
        $this->post('/pdls', $this->newPdl(['first_name' => 'Juan2', 'last_name' => '@Cruz']))
            ->assertSessionHasErrors(['first_name', 'last_name']);

        // Accents, dots, apostrophes and dashes are fine.
        $this->post('/pdls', $this->newPdl(['first_name' => 'José Ma.', 'last_name' => "O'Neil-Peña"]))
            ->assertSessionHasNoErrors();
    }

    public function test_pdl_dates_must_make_sense(): void
    {
        $this->post('/pdls', $this->newPdl(['date_of_birth' => now()->addDay()->toDateString()]))
            ->assertSessionHasErrors('date_of_birth');
        $this->post('/pdls', $this->newPdl(['admission_date' => now()->addDay()->toDateString()]))
            ->assertSessionHasErrors('admission_date');
        $this->post('/pdls', $this->newPdl(['date_of_birth' => '2010-01-01', 'admission_date' => '2009-01-01']))
            ->assertSessionHasErrors('admission_date');

        $this->assertSame(1, Pdl::count()); // only the fixture PDL
    }

    public function test_pdl_must_be_an_adult_on_admission(): void
    {
        $this->post('/pdls', $this->newPdl(['date_of_birth' => now()->subYears(17)->toDateString()]))
            ->assertSessionHasErrors(['date_of_birth' => 'A PDL must be at least 18 years old on the admission date.']);
    }

    public function test_same_person_cannot_be_registered_twice(): void
    {
        $this->post('/pdls', $this->newPdl())->assertSessionHasNoErrors();

        $this->post('/pdls', $this->newPdl())->assertSessionHasErrors('first_name');
        $this->assertSame(1, Pdl::where('full_name', 'Juan Dela Cruz')->count());
    }

    public function test_deceased_pdl_cannot_be_moved_back_into_custody(): void
    {
        $this->pdl->update(['custody_status' => 'deceased']);

        $this->put("/pdls/{$this->pdl->pdl_id}", $this->profileUpdate(['custody_status' => 'active', 'cell_block' => 'Dorm 1']))
            ->assertSessionHasErrors('custody_status');
        $this->assertSame('deceased', $this->pdl->fresh()->custody_status);
    }

    // -----------------------------------------------------------------
    // PDL records
    // -----------------------------------------------------------------
    public function test_legal_record_case_number_is_unique_per_pdl(): void
    {
        $url = "/pdls/{$this->pdl->pdl_id}/legal-records";
        $this->post($url, ['case_number' => 'CR-2024-001', 'offense' => 'Theft'])->assertSessionHasNoErrors();
        $this->post($url, ['case_number' => 'CR-2024-001', 'offense' => 'Theft'])->assertSessionHasErrors('case_number');
        $this->post($url, ['case_number' => 'CR#2024', 'offense' => 'Theft'])->assertSessionHasErrors('case_number');
    }

    public function test_disciplinary_incident_must_be_during_custody(): void
    {
        $url = "/pdls/{$this->pdl->pdl_id}/disciplinary-records";
        $valid = ['description' => 'Fight in the dorm during lights out.'];

        $this->post($url, $valid + ['incident_date' => '2023-12-31'])->assertSessionHasErrors('incident_date'); // before admission
        $this->post($url, $valid + ['incident_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('incident_date');
        $this->post($url, ['incident_date' => '2024-06-01', 'description' => 'Fight'])->assertSessionHasErrors('description');
        $this->post($url, $valid + ['incident_date' => '2024-06-01'])->assertSessionHasNoErrors();
    }

    public function test_restriction_rules_and_lifting(): void
    {
        $url = "/pdls/{$this->pdl->pdl_id}/restrictions";
        $valid = ['restriction_type' => 'quarantine', 'start_date' => now()->toDateString(), 'reason' => 'Medical quarantine'];

        $this->post($url, ['start_date' => '2023-01-01'] + $valid)->assertSessionHasErrors('start_date');
        $this->post($url, $valid)->assertSessionHasNoErrors();
        // A second active restriction of the same type is refused.
        $this->post($url, $valid)->assertSessionHasErrors('restriction_type');

        $restriction = PdlRestriction::firstOrFail();
        $liftUrl = "/pdls/{$this->pdl->pdl_id}/restrictions/{$restriction->restriction_id}/lift";
        $this->patch($liftUrl)->assertSessionHas('success');
        $this->patch($liftUrl)->assertSessionHas('error');
    }

    public function test_restrictions_only_for_pdls_in_custody(): void
    {
        $this->pdl->update(['custody_status' => 'released']);

        $this->post("/pdls/{$this->pdl->pdl_id}/restrictions", [
            'restriction_type' => 'quarantine', 'start_date' => now()->toDateString(), 'reason' => 'Medical quarantine',
        ])->assertSessionHas('error');
        $this->assertSame(0, PdlRestriction::count());
    }

    // -----------------------------------------------------------------
    // Visitors
    // -----------------------------------------------------------------
    public function test_rejecting_a_visitor_needs_a_reason(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);

        $this->post("/visitors/{$this->visitor->visitor_id}/reject", [])->assertSessionHasErrors('rejection_reason');
        $this->assertSame('pending', $this->visitor->fresh()->verification_status);
    }

    public function test_id_and_flag_actions_cannot_repeat(): void
    {
        $id = VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id, 'id_type' => 'national_id', 'id_number' => 'X1',
            'file_path' => 'visitor-ids/x.png', 'verification_status' => 'verified',
        ]);
        $this->post("/visitors/{$this->visitor->visitor_id}/ids/{$id->visitor_id_doc_id}/verify")->assertSessionHas('error');

        $flagUrl = "/visitors/{$this->visitor->visitor_id}/flags";
        $flag = ['flag_type' => 'rule_violation', 'description' => 'Brought a phone into the visiting area.'];
        $this->post($flagUrl, $flag)->assertSessionHasNoErrors();
        $this->post($flagUrl, $flag)->assertSessionHasErrors('flag_type');
        $this->post($flagUrl, ['flag_type' => 'denied_visit', 'description' => 'short'])->assertSessionHasErrors('description');
    }

    public function test_pages_render_with_flags_and_legal_records(): void
    {
        // created_at on these tables used to come back as a plain string and
        // crash ->format() in the views.
        $this->post("/visitors/{$this->visitor->visitor_id}/flags", ['flag_type' => 'rule_violation', 'description' => 'Brought a phone into the visiting area.']);
        $this->post("/pdls/{$this->pdl->pdl_id}/legal-records", ['case_number' => 'CR-2024-001', 'offense' => 'Theft']);

        $this->get("/visitors/{$this->visitor->visitor_id}")->assertOk()->assertSee(now()->format('M d, Y'));
        $this->get("/pdls/{$this->pdl->pdl_id}")->assertOk()->assertSee('CR-2024-001');
    }

    public function test_cell_block_name_format(): void
    {
        $this->post('/cell-blocks', ['name' => 'Dorm <b>', 'capacity' => 5, 'designation' => 'any'])->assertSessionHasErrors('name');
        $this->post('/cell-blocks', ['name' => 'Block A - 102 (F)', 'capacity' => 5, 'designation' => 'female'])->assertSessionHasNoErrors();
    }

    // -----------------------------------------------------------------
    // Confirmation popups
    // -----------------------------------------------------------------
    public function test_forms_ask_for_confirmation(): void
    {
        $this->get('/pdls/create')->assertOk()->assertSee('data-confirm="Register this PDL?', false)->assertSee('id="cc-confirm"', false);

        PdlRestriction::create([
            'pdl_id' => $this->pdl->pdl_id, 'restriction_type' => 'quarantine', 'status' => 'active',
            'start_date' => now()->toDateString(), 'reason' => 'Medical', 'imposed_by' => $this->pdl->registered_by,
        ]);
        $this->get("/pdls/{$this->pdl->pdl_id}")
            ->assertOk()
            ->assertSee('data-confirm-title="Lift restriction"', false)
            ->assertSee('data-confirm-title="Add restriction"', false)
            ->assertSee('name="_method" value="PATCH"', false);

        $this->get("/visitors/{$this->visitor->visitor_id}")
            ->assertOk()
            ->assertSee('data-confirm-title="Reject relationship"', false)
            ->assertSee('data-confirm-title="Flag visitor"', false);
    }
}
