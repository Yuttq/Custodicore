<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Visitor photo: taken in the mobile app (POST /api/me/photo or with
 * registration), stored privately, shown to staff on the visitor's pages.
 */
class VisitorPhotoTest extends TestCase
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
}
