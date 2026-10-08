<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Pdl;
use App\Models\RelationshipDocument;
use App\Models\StaffProfile;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Record Officer registers a visitor↔PDL relationship (pending), then
 * verifies it with the existing Verify action.
 */
class VisitorRelationshipCreationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private Pdl $otherPdl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();

        $this->otherPdl = Pdl::create([
            'pdl_number' => 'PDL-TEST-002',
            'full_name' => 'Second PDL',
            'date_of_birth' => '1988-05-05',
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2024-02-01',
            'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);
    }

    private function storeUrl(): string
    {
        return route('visitor.relationships.store', $this->visitor->visitor_id);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'pdl_id' => $this->otherPdl->pdl_id,
            'relationship_type' => 'spouse',
        ], $overrides);
    }

    /** Valid ID + verified marriage certificate: everything a spouse needs. */
    private function meetSpouseRequirements(VisitorPdlRelationship $relationship): void
    {
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-REQ-1',
            'file_path' => 'visitor-ids/x.png',
            'verification_status' => 'verified',
        ]);
        RelationshipDocument::create([
            'relationship_id' => $relationship->relationship_id,
            'requirement_key' => 'marriage_certificate',
            'file_path' => 'relationship-documents/m.png',
            'status' => 'verified',
            'uploaded_at' => now(),
        ]);
    }

    public function test_record_officer_can_create_a_pending_relationship(): void
    {
        $response = $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post($this->storeUrl(), $this->validPayload());

        $response->assertRedirect(route('visitor.show', $this->visitor->visitor_id));
        $response->assertSessionHas('success');
        $response->assertSessionHasNoErrors();

        $relationship = VisitorPdlRelationship::where('visitor_id', $this->visitor->visitor_id)
            ->where('pdl_id', $this->otherPdl->pdl_id)
            ->first();

        $this->assertNotNull($relationship);
        $this->assertSame('pending', $relationship->verification_status);
        $this->assertSame('spouse', $relationship->relationship_type);
        $this->assertSame('high_priority', $relationship->priority_tier);
        $this->assertNull($relationship->verified_by);
        $this->assertNull($relationship->verified_at);

        $this->assertTrue(AuditLog::where('record_type', 'visitor_pdl_relationships')
            ->where('record_id', $relationship->relationship_id)
            ->where('action_type', 'create')
            ->exists());
    }

    public function test_new_relationship_shows_as_pending_with_verify_button(): void
    {
        $this->actingAs($this->recordOfficerAccount)->post($this->storeUrl(), $this->validPayload());
        $relationship = VisitorPdlRelationship::where('pdl_id', $this->otherPdl->pdl_id)->firstOrFail();

        $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.show', $this->visitor->visitor_id))
            ->assertOk()
            ->assertSee('Second PDL')
            ->assertSee('PENDING')
            ->assertSee(route('visitor.relationships.verify', [$this->visitor->visitor_id, $relationship->relationship_id]), false);
    }

    public function test_show_page_offers_only_unrelated_pdls_in_add_form(): void
    {
        $response = $this->actingAs($this->recordOfficerAccount)
            ->get(route('visitor.show', $this->visitor->visitor_id))
            ->assertOk()
            ->assertSee(route('visitor.relationships.store', $this->visitor->visitor_id), false);

        $pdls = $response->viewData('relatablePdls');
        $this->assertSame([$this->otherPdl->pdl_id], $pdls->pluck('pdl_id')->all());
    }

    public function test_nonexistent_pdl_is_rejected(): void
    {
        $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post($this->storeUrl(), $this->validPayload(['pdl_id' => 999999]))
            ->assertSessionHasErrors('pdl_id');

        $this->assertSame(1, VisitorPdlRelationship::count());
    }

    public function test_invalid_relationship_type_is_rejected(): void
    {
        $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post($this->storeUrl(), $this->validPayload(['relationship_type' => 'friend']))
            ->assertSessionHasErrors('relationship_type');

        $this->assertSame(1, VisitorPdlRelationship::count());
    }

    public function test_priority_tier_follows_bjmp_priority_and_ignores_input(): void
    {
        $this->actingAs($this->recordOfficerAccount)
            ->post($this->storeUrl(), $this->validPayload(['relationship_type' => 'extended_relative', 'priority_tier' => 'high_priority']))
            ->assertSessionHasNoErrors();

        $this->assertSame('requires_verification', VisitorPdlRelationship::where('pdl_id', $this->otherPdl->pdl_id)->value('priority_tier'));
    }

    public function test_verify_is_blocked_until_requirements_are_met(): void
    {
        $this->actingAs($this->recordOfficerAccount)->post($this->storeUrl(), $this->validPayload());
        $relationship = VisitorPdlRelationship::where('pdl_id', $this->otherPdl->pdl_id)->firstOrFail();

        $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.relationships.verify', [$this->visitor->visitor_id, $relationship->relationship_id]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Marriage Certificate'));

        $this->assertSame('pending', $relationship->fresh()->verification_status);
    }

    public function test_duplicate_visitor_pdl_relationship_is_rejected(): void
    {
        // The fixture visitor is already related to $this->pdl.
        $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post($this->storeUrl(), $this->validPayload(['pdl_id' => $this->pdl->pdl_id]))
            ->assertSessionHasErrors('pdl_id');

        $this->assertSame(1, VisitorPdlRelationship::count());
        $this->assertSame('verified', $this->relationship->fresh()->verification_status);
    }

    public function test_same_pdl_can_be_related_to_a_different_visitor(): void
    {
        // Uniqueness is per visitor — another visitor's relationship to the
        // PDL must not block this one.
        $otherAccount = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'jose.cruz',
            'email' => 'jose.cruz@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
        $otherVisitor = VisitorProfile::create([
            'account_id' => $otherAccount->account_id,
            'full_name' => 'Jose Cruz',
            'date_of_birth' => '1980-01-01',
            'gender' => 'male',
            'contact_number' => '09170000000',
            'verification_status' => 'verified',
        ]);
        VisitorPdlRelationship::create([
            'visitor_id' => $otherVisitor->visitor_id,
            'pdl_id' => $this->otherPdl->pdl_id,
            'relationship_type' => 'extended_relative',
            'priority_tier' => 'requires_verification',
        ]);

        $this->actingAs($this->recordOfficerAccount)
            ->post($this->storeUrl(), $this->validPayload())
            ->assertSessionHasNoErrors();

        $this->assertSame(2, VisitorPdlRelationship::where('visitor_id', $this->visitor->visitor_id)->count());
    }

    public function test_non_record_officers_cannot_create_relationships(): void
    {
        foreach ([$this->frontDeskAccount, $this->visitorAccount] as $account) {
            $this->actingAs($account)
                ->post($this->storeUrl(), $this->validPayload())
                ->assertForbidden();
        }

        $this->assertSame(1, VisitorPdlRelationship::count());
    }

    public function test_guest_cannot_create_relationships(): void
    {
        $this->post($this->storeUrl(), $this->validPayload())->assertRedirect();

        $this->assertSame(1, VisitorPdlRelationship::count());
    }

    public function test_existing_verify_action_works_after_creation(): void
    {
        $this->actingAs($this->recordOfficerAccount)->post($this->storeUrl(), $this->validPayload());
        $relationship = VisitorPdlRelationship::where('pdl_id', $this->otherPdl->pdl_id)->firstOrFail();
        $this->meetSpouseRequirements($relationship);

        $this->actingAs($this->recordOfficerAccount)
            ->from(route('visitor.show', $this->visitor->visitor_id))
            ->post(route('visitor.relationships.verify', [$this->visitor->visitor_id, $relationship->relationship_id]))
            ->assertSessionHas('success');

        $relationship->refresh();
        $this->assertSame('verified', $relationship->verification_status);
        $this->assertNotNull($relationship->verified_by);
        $this->assertNotNull($relationship->verified_at);
    }

    public function test_created_then_verified_relationship_unlocks_mobile_availability(): void
    {
        // A visitor with no relationship yet (Daniel's situation).
        $this->relationship->delete();

        $this->actingAs($this->visitorAccount, 'sanctum')
            ->getJson('/api/schedules/availability')
            ->assertOk()
            ->assertJsonPath('relationships', [])
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'verified PDL relationship'));

        $this->actingAs($this->recordOfficerAccount, 'web')->post($this->storeUrl(), $this->validPayload());
        $relationship = VisitorPdlRelationship::where('pdl_id', $this->otherPdl->pdl_id)->firstOrFail();

        // Still pending — availability stays locked.
        $this->actingAs($this->visitorAccount, 'sanctum')
            ->getJson('/api/schedules/availability')
            ->assertJsonPath('relationships', []);

        $this->meetSpouseRequirements($relationship);
        $this->actingAs($this->recordOfficerAccount, 'web')
            ->post(route('visitor.relationships.verify', [$this->visitor->visitor_id, $relationship->relationship_id]));

        $this->actingAs($this->visitorAccount, 'sanctum')
            ->getJson('/api/schedules/availability')
            ->assertOk()
            ->assertJsonPath('message', null)
            ->assertJsonPath('relationships.0.pdlId', (string) $this->otherPdl->pdl_id);
    }
}
