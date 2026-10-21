<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\VisitorProfile;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\GoogleIdTokenFixtures;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Visitor photo: taken in the mobile app (required with registration, or
 * POST /api/me/photo), stored privately, shown to staff on the visitor's pages.
 */
class VisitorPhotoTest extends TestCase
{
    use GoogleIdTokenFixtures;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;
    use MakesTestImages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_visitor_uploads_their_photo_and_sees_it_on_their_profile(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);
        Sanctum::actingAs($this->visitorAccount);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('hasPhoto', false)->assertJsonPath('photoUrl', null);

        $this->post('/api/me/photo', ['photo' => $this->fakePhoto()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('hasPhoto', true);

        $path = $this->visitor->fresh()->photo_path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('visitor-photos/', $path);

        Sanctum::actingAs($this->visitorAccount->fresh()); // a new app request loads the account fresh
        $this->getJson('/api/me')->assertJsonPath('hasPhoto', true)->assertJsonPath('photoUrl', url('/api/me/photo'));
        $this->get('/api/me/photo')->assertOk()->assertHeader('Content-Type', 'image/png');

        // Retaking replaces the old file.
        $this->post('/api/me/photo', ['photo' => $this->fakePhoto('again.png')], ['Accept' => 'application/json'])->assertCreated();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_photo_must_be_a_picture(): void
    {
        Sanctum::actingAs($this->visitorAccount);
        $this->post('/api/me/photo', ['photo' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->post('/api/me/photo', [], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_photo_is_locked_after_the_account_is_approved(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);
        Sanctum::actingAs($this->visitorAccount);
        $this->post('/api/me/photo', ['photo' => $this->fakePhoto()], ['Accept' => 'application/json'])->assertCreated();

        $this->visitor->update(['verification_status' => 'verified']);
        $this->post('/api/me/photo', ['photo' => $this->fakePhoto('new.png')], ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_staff_see_the_photo_and_other_visitors_cannot_fetch_it(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);
        Sanctum::actingAs($this->visitorAccount);
        $this->post('/api/me/photo', ['photo' => $this->fakePhoto()], ['Accept' => 'application/json']);
        $this->app['auth']->forgetGuards();

        $visitor = $this->visitor->fresh();
        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->get("/visitors/{$visitor->visitor_id}/photo")->assertOk();
        $this->get("/visitors/{$visitor->visitor_id}")->assertOk()->assertSee($visitor->photoUrl(), false);
        $this->get('/account-reviews')->assertOk()->assertSee('Visitor Photo')->assertSee($visitor->photoUrl(), false);

        $this->actingAs($this->frontDeskAccount, 'web')->get("/visitors/{$visitor->visitor_id}/photo")->assertOk();

        // A visitor's web session can't use the staff photo link.
        $this->actingAs($this->visitorAccount, 'web')->get("/visitors/{$visitor->visitor_id}/photo")->assertForbidden();
    }

    public function test_missing_photo_shows_initial_and_hint(): void
    {
        $this->visitor->update(['verification_status' => 'pending']);
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->get("/visitors/{$this->visitor->visitor_id}")->assertOk()->assertSee('no photo yet');
        $this->get('/account-reviews')->assertSee('No photo yet. The visitor takes it in the CustodiCore app');
        $this->get("/visitors/{$this->visitor->visitor_id}/photo")->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Required photo at registration (password and Google)
    // ---------------------------------------------------------------

    private function registration(array $overrides = []): array
    {
        return array_filter(array_merge([
            'fullName' => 'Ana Reyes',
            'email' => 'photo.visitor@example.com',
            'password' => 'Secret-pass-1',
            'password_confirmation' => 'Secret-pass-1',
            'dateOfBirth' => '1992-02-02',
            'gender' => 'female',
            'intendedPdlName' => 'Pedro Santos Reyes',
            'acceptedTerms' => '1',
            'acceptedPrivacy' => '1',
            'consentVersion' => (string) config('legal.version'),
            'photo' => $this->fakePhoto(),
        ], $overrides), fn ($value) => $value !== null);
    }

    private function assertNoPhotoFiles(): void
    {
        $this->assertSame([], Storage::disk('local')->allFiles('visitor-photos'));
    }

    public static function badPhotos(): array
    {
        return [
            'missing' => [null, 'Please take a photo of yourself.'],
            'not a picture' => ['pdf', 'The photo must be a JPG, PNG or WEBP picture.'],
            'over 5 MB' => ['oversized', 'The photo is too large. Maximum size is 5 MB.'],
        ];
    }

    private function badPhoto(?string $kind): ?UploadedFile
    {
        return match ($kind) {
            null => null,
            'pdf' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'),
            // A real PNG padded past 5 MB, so only the size rule can reject it.
            'oversized' => (function () {
                $path = $this->fakePhoto('big.png')->getPathname();
                file_put_contents($path, str_repeat("\0", 5 * 1024 * 1024), FILE_APPEND);

                return new UploadedFile($path, 'big.png', 'image/png', null, true);
            })(),
        };
    }

    #[DataProvider('badPhotos')]
    public function test_registration_is_refused_without_a_valid_photo(?string $kind, string $message): void
    {
        Mail::fake();
        $accounts = Account::count();

        $this->post('/api/auth/register', $this->registration(['photo' => $this->badPhoto($kind)]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo' => $message]);

        $this->assertSame($accounts, Account::count());
        $this->assertNoPhotoFiles();
        Mail::assertNothingSent();
    }

    #[DataProvider('badPhotos')]
    public function test_google_registration_is_refused_without_a_valid_photo(?string $kind, string $message): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());
        $accounts = Account::count();

        $body = ['idToken' => $this->googleIdToken()] + $this->registration(['email' => null, 'photo' => $this->badPhoto($kind)]);
        $this->post('/api/auth/google/register', $body, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo' => $message])
            ->assertJsonMissingPath('token');

        $this->assertSame($accounts, Account::count());
        $this->assertNoPhotoFiles();
    }

    public function test_registration_photo_is_stored_on_the_new_profile_for_staff(): void
    {
        Mail::fake();

        $this->post('/api/auth/register', $this->registration(), ['Accept' => 'application/json'])->assertCreated();

        $profile = Account::where('email', 'photo.visitor@example.com')->first()->visitorProfile;
        $this->assertStringStartsWith('visitor-photos/', $profile->photo_path);
        $this->assertNotNull($profile->photo_uploaded_at);
        $this->assertSame([$profile->photo_path], Storage::disk('local')->allFiles('visitor-photos'));

        $this->actingAs($this->recordOfficerAccount, 'web');
        $this->get("/visitors/{$profile->visitor_id}/photo")->assertOk();
        // Account Review shows the photo above the Government ID for comparison.
        $this->get('/account-reviews')->assertOk()
            ->assertSeeInOrder(['Visitor Photo', $profile->photoUrl(), 'Government ID'], false);
    }

    public function test_google_registration_stores_the_photo(): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());

        $body = ['idToken' => $this->googleIdToken()] + $this->registration(['email' => null]);
        $this->post('/api/auth/google/register', $body, ['Accept' => 'application/json'])->assertCreated();

        $profile = Account::where('email', 'google.visitor@gmail.com')->first()->visitorProfile;
        $this->assertSame([$profile->photo_path], Storage::disk('local')->allFiles('visitor-photos'));
    }

    public function test_a_failed_registration_leaves_no_photo_file(): void
    {
        Mail::fake();
        VisitorProfile::creating(function () {
            throw new RuntimeException('Simulated profile failure.');
        });

        $this->post('/api/auth/register', $this->registration(), ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertSame(0, Account::where('email', 'photo.visitor@example.com')->count());
        $this->assertNoPhotoFiles();
    }
}
