<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use App\Services\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Government ID files live on the private disk (VisitorId::DISK) and are
 * served only through the role-checked staff routes. Files stored before the
 * move are still read from the legacy public disk until
 * `visitor-ids:move-to-private` has copied them.
 */
class GovernmentIdPrivateStorageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    /** A valid 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function idWithFile(string $path = 'visitor-ids/id.png', string $disk = 'local'): VisitorId
    {
        Storage::disk($disk)->put($path, 'id-bytes');

        return VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'N-123',
            'file_path' => $path,
            'verification_status' => 'pending',
        ]);
    }

    private function recordsUrl(VisitorId $id, ?int $visitorId = null): string
    {
        return '/visitors/'.($visitorId ?? $this->visitor->visitor_id)."/ids/{$id->visitor_id_doc_id}/file";
    }

    private function frontDeskUrl(VisitorId $id): string
    {
        return "/front-desk/visitor-lookup/{$this->visitor->visitor_id}/ids/{$id->visitor_id_doc_id}/photo";
    }

    // ------------------------------------------------------------ New uploads

    public function test_ids_uploaded_from_the_app_go_to_the_private_disk(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $this->postJson('/api/documents', [
            'documentType' => 'passport',
            'file' => UploadedFile::fake()->create('passport.pdf', 50, 'application/pdf'),
        ])->assertCreated();

        $path = VisitorId::latest('visitor_id_doc_id')->value('file_path');
        $this->assertStringStartsWith('visitor-ids/', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_walk_in_ids_go_to_the_private_disk(): void
    {
        $this->actingAs($this->frontDeskAccount, 'web')->post(route('frontdesk.walk-ins.store'), [
            'first_name' => 'Lourdes',
            'last_name' => 'Reyes',
            'date_of_birth' => '1970-05-02',
            'gender' => 'female',
            'address' => 'Brgy. San Isidro',
            // create() rather than image(): image() needs the GD extension.
            'photo' => UploadedFile::fake()->create('face.jpg', 50, 'image/jpeg'),
            'id_type' => 'national_id',
            'id_number' => 'PSN-1234-5678',
            'government_id' => UploadedFile::fake()->create('id.jpg', 50, 'image/jpeg'),
            'intended_pdl_name' => 'Example PDL',
            'intended_pdl_number' => 'PDL-TEST-001',
            'visit_purpose' => 'Family visit',
            'relationship_type' => 'spouse',
            'documents' => ['marriage_certificate' => UploadedFile::fake()->create('marriage.pdf', 50, 'application/pdf')],
            'consent' => '1',
        ])->assertSessionHasNoErrors();

        $path = VisitorId::latest('visitor_id_doc_id')->value('file_path');
        $this->assertStringStartsWith('visitor-ids/', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replacement_id_photo_taken_at_the_gate_goes_to_the_private_disk(): void
    {
        $this->travelTo($this->schedule->schedule_date->copy()->subDay()->setTime(10, 0));
        $visit = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);
        $this->travelTo($this->schedule->schedule_date->copy()->setTime(9, 30));
        Sanctum::actingAs($this->visitorAccount);
        $this->postJson("/api/schedules/{$visit->visit_request_id}/confirm")->assertOk();
        $token = $this->getJson("/api/schedules/{$visit->visit_request_id}/qr")->assertOk()->json('qrToken');

        $this->actingAs($this->frontDeskAccount, 'web')->postJson('/front-desk/checkin-checkout/confirm', [
            'qr_token' => $token,
            'id_surrendered_type' => 'passport',
            'id_match_status' => 'replaced',
            'new_id_type' => 'passport',
            'new_id_number' => 'P1234567',
            'new_id_reason' => 'Lost the old ID',
            'new_id_photo' => 'data:image/png;base64,'.self::PNG,
        ])->assertOk()->assertJsonPath('success', true);

        $path = VisitorId::where('id_number', 'P1234567')->value('file_path');
        $this->assertStringStartsWith('visitor-ids/', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ---------------------------------------------------------------- Viewing

    public function test_authorized_staff_can_view_a_private_id(): void
    {
        $id = $this->idWithFile();

        $this->actingAs($this->recordOfficerAccount, 'web')->get($this->recordsUrl($id))->assertOk();
        $this->actingAs($this->frontDeskAccount, 'web')->get($this->frontDeskUrl($id))->assertOk();
    }

    public function test_logged_out_users_and_the_wrong_role_cannot_view_an_id(): void
    {
        $id = $this->idWithFile();

        $this->get($this->recordsUrl($id))->assertRedirect(route('login'));
        $this->get($this->frontDeskUrl($id))->assertRedirect(route('login'));

        $this->actingAs($this->frontDeskAccount, 'web')->get($this->recordsUrl($id))->assertForbidden();
        $this->actingAs($this->recordOfficerAccount, 'web')->get($this->frontDeskUrl($id))->assertForbidden();
        $this->actingAs($this->visitorAccount, 'web')->get($this->recordsUrl($id))->assertForbidden();
    }

    public function test_an_id_is_only_served_through_its_own_visitor(): void
    {
        $id = $this->idWithFile();
        $other = VisitorProfile::create([
            'account_id' => Account::create([
                'role_id' => $this->visitorRole->role_id,
                'username' => 'ben.cruz',
                'email' => 'ben.cruz@example.com',
                'password_hash' => Hash::make('password'),
                'status' => 'active',
            ])->account_id,
            'full_name' => 'Ben Cruz',
            'date_of_birth' => '1992-02-02',
            'contact_number' => '09171112222',
            'verification_status' => 'pending',
        ]);

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->get($this->recordsUrl($id, $other->visitor_id))->assertNotFound();
        $this->actingAs($this->frontDeskAccount, 'web')
            ->get("/front-desk/visitor-lookup/{$other->visitor_id}/ids/{$id->visitor_id_doc_id}/photo")->assertNotFound();
    }

    public function test_front_desk_profile_links_to_a_private_id_photo(): void
    {
        $id = $this->idWithFile();

        $this->actingAs($this->frontDeskAccount, 'web')
            ->get("/front-desk/visitor-lookup/{$this->visitor->visitor_id}")
            ->assertOk()
            ->assertSee('View photo')
            ->assertSee($this->frontDeskUrl($id))
            ->assertDontSee('/storage/visitor-ids', false);
    }

    public function test_a_missing_file_is_a_404(): void
    {
        $id = VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'N-123',
            'file_path' => 'visitor-ids/gone.png',
            'verification_status' => 'pending',
        ]);

        $this->actingAs($this->recordOfficerAccount, 'web')->get($this->recordsUrl($id))->assertNotFound();
        $this->actingAs($this->frontDeskAccount, 'web')->get($this->frontDeskUrl($id))->assertNotFound();
    }

    public function test_an_id_not_yet_moved_is_still_served_from_the_legacy_public_disk(): void
    {
        $id = $this->idWithFile(disk: 'public');

        $this->assertSame('public', $id->fileDisk());
        $this->actingAs($this->recordOfficerAccount, 'web')->get($this->recordsUrl($id))->assertOk();
        $this->actingAs($this->frontDeskAccount, 'web')->get($this->frontDeskUrl($id))->assertOk();
    }

    // ------------------------------------------------------ Migration command

    public function test_dry_run_changes_nothing(): void
    {
        $id = $this->idWithFile(disk: 'public');

        $this->artisan('visitor-ids:move-to-private')->assertSuccessful();

        Storage::disk('public')->assertExists($id->file_path);
        Storage::disk('local')->assertMissing($id->file_path);
    }

    public function test_copy_keeps_the_original_and_the_reference_stays_valid(): void
    {
        $id = $this->idWithFile(disk: 'public');

        $this->artisan('visitor-ids:move-to-private', ['--copy' => true])->assertSuccessful();

        Storage::disk('public')->assertExists($id->file_path);
        $this->assertSame('id-bytes', Storage::disk('local')->get($id->file_path));
        $this->assertSame('local', $id->fresh()->fileDisk());
        $this->actingAs($this->recordOfficerAccount, 'web')->get($this->recordsUrl($id))->assertOk();
    }

    public function test_delete_removes_only_verified_originals(): void
    {
        $copied = $this->idWithFile('visitor-ids/copied.png', 'public');
        $notCopied = $this->idWithFile('visitor-ids/not-copied.png', 'public');
        $this->artisan('visitor-ids:move-to-private', ['--copy' => true])->assertSuccessful();
        Storage::disk('local')->delete($notCopied->file_path);

        $this->artisan('visitor-ids:move-to-private', ['--delete-originals' => true])->assertSuccessful();

        Storage::disk('public')->assertMissing($copied->file_path);
        Storage::disk('local')->assertExists($copied->file_path);
        // No verified private copy: the original is kept.
        Storage::disk('public')->assertExists($notCopied->file_path);
    }

    public function test_a_mismatched_copy_is_never_deleted_or_overwritten(): void
    {
        $id = $this->idWithFile(disk: 'public');
        Storage::disk('local')->put($id->file_path, 'different-bytes');

        $this->artisan('visitor-ids:move-to-private', ['--delete-originals' => true])->assertFailed();
        $this->artisan('visitor-ids:move-to-private', ['--copy' => true])->assertFailed();

        $this->assertSame('id-bytes', Storage::disk('public')->get($id->file_path));
        $this->assertSame('different-bytes', Storage::disk('local')->get($id->file_path));
    }

    public function test_copy_and_delete_cannot_run_together(): void
    {
        $id = $this->idWithFile(disk: 'public');

        $this->artisan('visitor-ids:move-to-private', ['--copy' => true, '--delete-originals' => true])->assertFailed();

        Storage::disk('public')->assertExists($id->file_path);
        Storage::disk('local')->assertMissing($id->file_path);
    }
}
