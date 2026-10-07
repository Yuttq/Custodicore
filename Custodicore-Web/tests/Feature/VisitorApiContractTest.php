<?php

namespace Tests\Feature;

use App\Models\VisitorPdlRelationship;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

class VisitorApiContractTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
    }

    public function test_login_and_logout_still_work(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'email' => 'maria.santos@example.com',
            'password' => 'password',
        ]);

        $login->assertOk()
            ->assertJsonPath('user.email', 'maria.santos@example.com')
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'fullName', 'role', 'verificationStatus']]);

        $token = $login->json('token');

        $logout = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout');

        $logout->assertOk()->assertJson(['ok' => true]);
    }

    public function test_me_returns_authenticated_visitor(): void
    {
        Sanctum::actingAs($this->visitorAccount);

        $response = $this->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('email', 'maria.santos@example.com')
            ->assertJsonPath('fullName', 'Maria D. Santos')
            ->assertJsonPath('role', 'Visitor')
            ->assertJsonPath('verificationStatus', 'verified');
    }

    public function test_registration_accepts_agreed_fields_without_creating_relationship(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'fullName' => 'Juan Dela Cruz',
            'email' => 'juan.dela.cruz@example.com',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'dateOfBirth' => '1995-06-15',
            'gender' => 'female',
            'address' => 'Quezon City',
            'relationshipHint' => 'sibling',
            'contactNumber' => '09170001111',
            'acceptedTerms' => true,
            'acceptedPrivacy' => true,
            'consentVersion' => (string) config('legal.version'),
        ]);

        // Phase 2: no session — the visitor must verify their email first.
        $response->assertCreated()
            ->assertJsonPath('status', 'verification_required')
            ->assertJsonPath('email', 'juan.dela.cruz@example.com')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        $this->assertDatabaseHas('visitor_profiles', [
            'full_name' => 'Juan Dela Cruz',
            'verification_status' => 'pending',
            'gender' => 'female',
            'address' => 'Quezon City',
            'relationship_hint' => 'sibling',
        ]);

        // Only the fixture relationship should exist (Maria), not one for Juan.
        $this->assertSame(1, VisitorPdlRelationship::count());
    }

    /** A valid registration body with the given gender (null = omitted). */
    private function registrationWithGender(?string $gender): array
    {
        return array_filter([
            'fullName' => 'Ana Reyes',
            'email' => 'ana.reyes@example.com',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'dateOfBirth' => '1992-02-02',
            'gender' => $gender,
            'contactNumber' => '09170002222',
            'acceptedTerms' => true,
            'acceptedPrivacy' => true,
            'consentVersion' => (string) config('legal.version'),
        ], fn ($value) => $value !== null);
    }

    public static function acceptedGenderProvider(): array
    {
        return ['male' => ['male'], 'female' => ['female']];
    }

    #[DataProvider('acceptedGenderProvider')]
    public function test_registration_accepts_male_and_female(string $gender): void
    {
        $this->postJson('/api/auth/register', $this->registrationWithGender($gender))
            ->assertCreated();

        $this->assertDatabaseHas('visitor_profiles', ['full_name' => 'Ana Reyes', 'gender' => $gender]);
    }

    public static function rejectedGenderProvider(): array
    {
        return [
            'other' => ['other'],
            'arbitrary value' => ['robot'],
            'prefer not to say' => ['Prefer not to say'],
            'missing' => [null],
        ];
    }

    #[DataProvider('rejectedGenderProvider')]
    public function test_registration_rejects_any_other_gender(?string $gender): void
    {
        $this->postJson('/api/auth/register', $this->registrationWithGender($gender))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gender');

        $this->assertDatabaseMissing('accounts', ['email' => 'ana.reyes@example.com']);
    }

    public function test_visits_list_returns_only_authenticated_visitor_visits(): void
    {
        $own = app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);

        Sanctum::actingAs($this->visitorAccount);

        $response = $this->getJson('/api/visits');

        $response->assertOk()
            ->assertJsonCount(1, 'visits')
            ->assertJsonPath('visits.0.id', (string) $own->visit_request_id)
            ->assertJsonPath('visits.0.status', 'pending_confirmation')
            ->assertJsonPath('visits.0.visitType', 'regular')
            ->assertJsonStructure([
                'visits' => [[
                    'id', 'scheduleId', 'scheduledAt', 'endAt', 'dateDisplay',
                    'timeLabel', 'pdlName', 'facility', 'referenceNumber',
                    'visitType', 'status', 'cancellationReason',
                ]],
            ]);
    }

    public function test_visits_endpoint_does_not_leak_other_visitors(): void
    {
        app(VisitAssignmentService::class)->assign([
            'visitor_id' => $this->visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_id' => $this->relationship->relationship_id,
            'schedule_id' => $this->schedule->schedule_id,
        ]);

        // Authenticate as Record Officer — no visitor profile
        Sanctum::actingAs($this->recordOfficerAccount);

        $response = $this->getJson('/api/visits');
        $response->assertForbidden();
    }
}
