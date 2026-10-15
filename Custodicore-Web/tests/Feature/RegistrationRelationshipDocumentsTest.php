<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\RelationshipDocument;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GoogleIdTokenFixtures;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Relationship documents sent with the registration (one submission): they
 * are stored on the visitor until staff link the PDL, then attached to the
 * matching checklist requirements as pending for the Record Officer.
 */
class RegistrationRelationshipDocumentsTest extends TestCase
{
    use GoogleIdTokenFixtures;
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const EMAIL = 'doc.visitor@example.com';
    private const PASSWORD = 'secret-pass-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
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
            'relationshipHint' => 'Spouse',
            'relationshipType' => 'spouse',
            'contactNumber' => '09170001111',
            'acceptedTerms' => '1',
            'acceptedPrivacy' => '1',
            'consentVersion' => (string) config('legal.version'),
            'governmentIdType' => 'national_id',
            'governmentId' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
        ], $overrides);
    }

    private function file(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'image/jpeg');
    }

    private function register(array $overrides = [])
    {
        return $this->post('/api/auth/register', $this->form($overrides), ['Accept' => 'application/json']);
    }

    private function profile(string $email = self::EMAIL): VisitorProfile
    {
        return Account::where('email', $email)->firstOrFail()->visitorProfile;
    }

    private function link(VisitorProfile $visitor, string $type, ?bool $children = null)
    {
        return $this->actingAs($this->recordOfficerAccount)->post(route('visitor.relationships.store', $visitor->visitor_id), array_filter([
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => $type,
            'has_children_together' => $children === null ? null : ($children ? '1' : '0'),
        ], fn ($v) => $v !== null));
    }

    public function test_registration_stores_the_relationship_documents_with_the_government_id(): void
    {
        $this->register(['relationshipDocuments' => ['marriage_certificate' => $this->file('marriage.jpg')]])
            ->assertCreated()
            ->assertJsonPath('status', 'verification_required');

        $visitor = $this->profile();
        $this->assertCount(1, $visitor->idDocuments);

        $doc = RelationshipDocument::sole();
        $this->assertNull($doc->relationship_id);
        $this->assertSame($visitor->visitor_id, $doc->visitor_id);
        $this->assertSame('marriage_certificate', $doc->requirement_key);
        $this->assertSame('pending', $doc->status);
        $this->assertSame('marriage.jpg', $doc->original_name);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_only_documents_the_claimed_relationship_asks_for_are_accepted(): void
    {
        // A spouse is not asked for a CENOMAR.
        $this->register(['relationshipDocuments' => ['cenomar_visitor' => $this->file('c.jpg')]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('relationshipDocuments');

        // Facility-issued and unknown keys are never visitor uploads.
        $this->register([
            'relationshipType' => 'legal_counsel',
            'relationshipDocuments' => ['official_authorization' => $this->file('o.jpg')],
        ])->assertUnprocessable()->assertJsonValidationErrors('relationshipDocuments');

        // Documents need the relationship they are for.
        $this->register([
            'relationshipType' => null,
            'relationshipDocuments' => ['marriage_certificate' => $this->file('m.jpg')],
        ])->assertUnprocessable()->assertJsonValidationErrors('relationshipType');

        $this->register([
            'relationshipDocuments' => ['marriage_certificate' => UploadedFile::fake()->create('m.exe', 10, 'application/octet-stream')],
        ])->assertUnprocessable()->assertJsonValidationErrors('relationshipDocuments.marriage_certificate');

        $this->assertNull(Account::where('email', self::EMAIL)->first());
        $this->assertSame(0, RelationshipDocument::count());
    }

    public function test_a_minor_may_send_their_birth_certificate_but_an_adult_spouse_may_not(): void
    {
        $this->register(['relationshipDocuments' => ['visitor_birth_certificate' => $this->file('b.jpg')]])
            ->assertUnprocessable();

        $this->register([
            'relationshipType' => 'parent',
            'dateOfBirth' => now()->subYears(12)->format('Y-m-d'),
            'governmentId' => null,
            'governmentIdType' => null,
            'relationshipDocuments' => [
                'pdl_birth_certificate' => $this->file('pdl.jpg'),
                'visitor_birth_certificate' => $this->file('mine.jpg'),
            ],
        ])->assertCreated();

        $this->assertSame(2, RelationshipDocument::whereNull('relationship_id')->count());
    }

    public function test_registration_without_documents_still_works_for_older_app_builds(): void
    {
        $this->register(['relationshipType' => null])->assertCreated();
        $this->assertSame(0, RelationshipDocument::count());
    }

    public function test_staff_see_registration_uploads_before_linking_and_review_them_after(): void
    {
        $this->register(['relationshipDocuments' => ['marriage_certificate' => $this->file('marriage.jpg')]])->assertCreated();
        $visitor = $this->profile();
        $doc = RelationshipDocument::sole();

        // Before the link: listed for staff, but not reviewable yet.
        $this->actingAs($this->recordOfficerAccount)->get(route('visitor.show', $visitor->visitor_id))
            ->assertOk()
            ->assertSee('Submitted with registration')
            ->assertSee('Marriage Certificate')
            ->assertSee(route('relationship-documents.file', $doc->document_id), false);
        $this->get(route('account-reviews.index'))->assertOk()->assertSee('Submitted with registration');
        $this->get(route('relationship-documents.file', $doc->document_id))->assertOk();
        $this->post(route('relationship-documents.verify', $doc->document_id))->assertSessionHas('error');
        $this->assertSame('pending', $doc->fresh()->status);

        // The visitor sees it as sent, without being asked to upload it again.
        Sanctum::actingAs($visitor->account);
        $this->getJson('/api/documents')->assertOk()
            ->assertJsonPath('registrationDocuments.0.key', 'marriage_certificate')
            ->assertJsonPath('registrationDocuments.0.status', 'pending')
            ->assertJsonCount(0, 'relationships');

        // Staff link the PDL: the file joins the checklist as "Awaiting review".
        $this->link($visitor, 'spouse')->assertSessionHas('success');
        $relationship = VisitorPdlRelationship::where('visitor_id', $visitor->visitor_id)->sole();
        $this->assertSame($relationship->relationship_id, $doc->fresh()->relationship_id);

        $this->actingAs($this->recordOfficerAccount)->get(route('visitor.show', $visitor->visitor_id))
            ->assertOk()
            ->assertSee('Awaiting review')
            ->assertDontSee('Submitted with registration');

        // Approval stays with staff.
        $this->post(route('relationship-documents.verify', $doc->document_id))->assertSessionHas('success');
        $this->assertSame('verified', $doc->fresh()->status);

        Sanctum::actingAs($visitor->account);
        $this->getJson('/api/documents')->assertOk()
            ->assertJsonCount(0, 'registrationDocuments')
            ->assertJsonPath('relationships.0.requirements.1.key', 'marriage_certificate')
            ->assertJsonPath('relationships.0.requirements.1.status', 'met');
    }

    public function test_a_rejected_registration_upload_can_be_replaced_after_the_link(): void
    {
        $this->register(['relationshipDocuments' => ['marriage_certificate' => $this->file('blurry.jpg')]])->assertCreated();
        $visitor = $this->profile();
        $this->link($visitor, 'spouse');
        $doc = RelationshipDocument::sole();

        $this->post(route('relationship-documents.reject', $doc->document_id), ['rejection_reason' => 'Photo is blurry'])
            ->assertSessionHas('success');

        $relationship = VisitorPdlRelationship::where('visitor_id', $visitor->visitor_id)->sole();
        Sanctum::actingAs($visitor->account);
        $this->post("/api/relationships/{$relationship->relationship_id}/requirements/marriage_certificate", [
            'file' => $this->file('clear.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('requirements.1.status', 'pending_review');

        $this->assertSame($visitor->visitor_id, RelationshipDocument::latest('document_id')->first()->visitor_id);
    }

    public function test_files_follow_the_relationship_staff_record(): void
    {
        $this->register([
            'relationshipType' => 'live_in_partner',
            'relationshipHint' => 'Live-In Partner',
            'relationshipDocuments' => [
                'cenomar_visitor' => $this->file('c1.jpg'),
                'cenomar_pdl' => $this->file('c2.jpg'),
                'witness_statement' => $this->file('w.jpg'),
            ],
        ])->assertCreated();
        $visitor = $this->profile();

        // Linked under another type first: nothing of theirs applies.
        $this->link($visitor, 'parent');
        $relationship = VisitorPdlRelationship::where('visitor_id', $visitor->visitor_id)->sole();
        $this->assertSame(3, RelationshipDocument::whereNull('relationship_id')->count());

        // Corrected to live-in partner: both CENOMARs; the witness statement
        // waits until staff record that there are no children together.
        $this->patch(route('visitor.relationships.update', [$visitor->visitor_id, $relationship->relationship_id]), [
            'relationship_type' => 'live_in_partner',
        ])->assertSessionHas('success');
        $this->assertEqualsCanonicalizing(
            ['cenomar_visitor', 'cenomar_pdl'],
            RelationshipDocument::where('relationship_id', $relationship->relationship_id)->pluck('requirement_key')->all()
        );

        $this->patch(route('visitor.relationships.update', [$visitor->visitor_id, $relationship->relationship_id]), [
            'relationship_type' => 'live_in_partner',
            'has_children_together' => '0',
        ])->assertSessionHas('success');
        $this->assertSame(0, RelationshipDocument::whereNull('relationship_id')->count());

        $statuses = collect(\App\Services\RelationshipRequirements::for($relationship->fresh()))
            ->where('kind', 'document')->pluck('status', 'key')->all();
        $this->assertSame([
            'cenomar_visitor' => 'pending_review',
            'cenomar_pdl' => 'pending_review',
            'witness_statement' => 'pending_review',
        ], $statuses);
    }

    public function test_google_registration_accepts_the_documents_too(): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, $this->makeGoogleVerifier());

        $form = $this->form(['relationshipDocuments' => ['marriage_certificate' => $this->file('m.jpg')]]);
        unset($form['email'], $form['governmentId'], $form['governmentIdType']);

        $this->post('/api/auth/google/register', ['idToken' => $this->googleIdToken()] + $form, ['Accept' => 'application/json'])
            ->assertCreated();

        $doc = RelationshipDocument::sole();
        $this->assertSame($this->profile('google.visitor@gmail.com')->visitor_id, $doc->visitor_id);
        $this->assertNull($doc->relationship_id);
    }
}
