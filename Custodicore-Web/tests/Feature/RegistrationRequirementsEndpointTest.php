<?php

namespace Tests\Feature;

use App\Models\VisitorPdlRelationship;
use App\Services\RelationshipRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * GET /api/registration/requirements: public, read-only registration
 * guidance built from the same rules as the staff checklist.
 */
class RegistrationRequirementsEndpointTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
    }

    private function type(string $key): array
    {
        $entry = collect($this->getJson('/api/registration/requirements')->assertOk()->json('relationshipTypes'))
            ->firstWhere('key', $key);
        $this->assertNotNull($entry, $key);

        return $entry;
    }

    public function test_it_is_public_and_lists_every_relationship_type(): void
    {
        $response = $this->getJson('/api/registration/requirements')->assertOk();

        $this->assertSame(
            ['spouse', 'live_in_partner', 'parent', 'child', 'sibling', 'legal_guardian', 'extended_relative', 'legal_counsel'],
            array_column($response->json('relationshipTypes'), 'key')
        );
        // Every relationship type the system stores has rules (legacy types excluded).
        $this->assertEqualsCanonicalizing(VisitorPdlRelationship::RELATIONSHIP_TYPES, array_keys(RelationshipRequirements::RELATIONSHIP_RULES));

        $response
            ->assertJsonPath('documentSubmission', 'at_registration')
            ->assertJsonStructure([
                'documentSubmission', 'documentSubmissionNote',
                'policyStatuses' => ['confirmed', 'pending_facility_confirmation'],
                'relationshipTypes' => [[
                    'key', 'label', 'description', 'offeredAtRegistration', 'registrationOptionPolicy', 'documentPolicy',
                    'governmentId' => ['requiredForAdults', 'policy', 'requestedAtRegistration', 'registrationPolicy'],
                    'details', 'staffConfirmations',
                    'documents' => [['key', 'label', 'description', 'required', 'condition', 'providedBy', 'policy', 'submission']],
                ]],
                'minorVisitors' => ['adultAge', 'minimumAge', 'governmentIdRequired', 'policy', 'documents', 'staffConfirmations'],
            ]);
    }

    public function test_spouse_documents_keep_their_existing_keys_and_labels(): void
    {
        $spouse = $this->type('spouse');

        $this->assertSame('Spouse', $spouse['label']);
        $this->assertTrue($spouse['offeredAtRegistration']);
        $this->assertSame('confirmed', $spouse['documentPolicy']);
        $this->assertSame([[
            'key' => 'marriage_certificate',
            'label' => 'Marriage Certificate',
            'description' => 'PSA-issued marriage certificate of the visitor and the PDL.',
            'required' => true,
            'condition' => null,
            'providedBy' => 'visitor',
            'policy' => 'confirmed',
            'submission' => 'at_registration',
        ]], $spouse['documents']);
        $this->assertSame('confirmed', $spouse['governmentId']['policy']);
    }

    public function test_confirmed_and_pending_policies(): void
    {
        foreach (['spouse', 'child', 'live_in_partner'] as $key) {
            $this->assertSame('confirmed', $this->type($key)['documentPolicy'], $key);
        }
        foreach (['parent', 'sibling', 'legal_guardian', 'legal_counsel', 'extended_relative'] as $key) {
            $entry = $this->type($key);
            $this->assertSame('pending_facility_confirmation', $entry['documentPolicy'], $key);
            foreach ($entry['documents'] as $document) {
                $this->assertSame('pending_facility_confirmation', $document['policy'], "{$key}.{$document['key']}");
            }
        }

        // Registration options: sibling and extended relative are open questions.
        foreach (['sibling', 'extended_relative'] as $key) {
            $entry = $this->type($key);
            $this->assertFalse($entry['offeredAtRegistration'], $key);
            $this->assertSame('pending_facility_confirmation', $entry['registrationOptionPolicy'], $key);
        }
        foreach (['spouse', 'live_in_partner', 'parent', 'child', 'legal_guardian', 'legal_counsel'] as $key) {
            $this->assertTrue($this->type($key)['offeredAtRegistration'], $key);
        }

        // Valid ID: the interview lists it for spouse and child only.
        $this->assertSame('confirmed', $this->type('child')['governmentId']['policy']);
        $this->assertSame('pending_facility_confirmation', $this->type('parent')['governmentId']['policy']);
    }

    public function test_legal_guardian_means_the_visitor_is_the_pdls_guardian(): void
    {
        $guardian = $this->type('legal_guardian');

        $this->assertStringStartsWith("The visitor is the PDL's legal guardian.", $guardian['description']);
        $this->assertSame(['guardianship_authorization'], array_column($guardian['documents'], 'key'));
        $this->assertStringContainsString("visitor is the PDL's legal guardian", $guardian['documents'][0]['description']);
        $this->assertStringContainsString('has not yet confirmed which document', $guardian['documents'][0]['description']);
        // The guardianship document is unconfirmed; "no immediate family" is from the interview.
        $this->assertSame('pending_facility_confirmation', $guardian['documents'][0]['policy']);
        $this->assertSame([['key' => 'no_immediate_family', 'providedBy' => 'facility', 'policy' => 'confirmed']],
            array_map(fn ($c) => ['key' => $c['key'], 'providedBy' => $c['providedBy'], 'policy' => $c['policy']], $guardian['staffConfirmations']));

        // Registration asks adult guardians for a government ID, like other
        // adults; the facility has not confirmed it for guardians.
        $this->assertTrue($guardian['governmentId']['requestedAtRegistration']);
        $this->assertSame('pending_facility_confirmation', $guardian['governmentId']['registrationPolicy']);
    }

    public function test_official_authorization_is_facility_issued(): void
    {
        $relative = $this->type('extended_relative');

        $this->assertSame('official_authorization', $relative['documents'][0]['key']);
        $this->assertSame('facility', $relative['documents'][0]['providedBy']);
        // Never sent with the registration; it comes after staff link the PDL.
        $this->assertSame('after_staff_link', $relative['documents'][0]['submission']);

        $visitorProvided = collect($this->getJson('/api/registration/requirements')->json('relationshipTypes'))
            ->flatMap(fn ($t) => $t['documents'])
            ->reject(fn ($d) => $d['key'] === 'official_authorization')
            ->pluck('providedBy')->unique()->values()->all();
        $this->assertSame(['visitor'], $visitorProvided);
    }

    public function test_live_in_partner_conditional_documents(): void
    {
        $partner = $this->type('live_in_partner');

        $this->assertSame([
            ['cenomar_visitor', null],
            ['cenomar_pdl', null],
            ['child_birth_certificate', ['detail' => 'has_children_together', 'equals' => true]],
            ['witness_statement', ['detail' => 'has_children_together', 'equals' => false]],
        ], array_map(fn ($d) => [$d['key'], $d['condition']], $partner['documents']));
        $this->assertSame('has_children_together', $partner['details'][0]['key']);
        $this->assertSame('facility', $partner['details'][0]['providedBy']);
    }

    public function test_guide_matches_the_enforced_checklist_for_every_type(): void
    {
        $this->visitor->update(['date_of_birth' => '1985-03-14']); // adult

        foreach ($this->getJson('/api/registration/requirements')->json('relationshipTypes') as $entry) {
            $cases = $entry['key'] === 'live_in_partner' ? [true, false] : [null];
            foreach ($cases as $children) {
                $this->relationship->update(['relationship_type' => $entry['key'], 'has_children_together' => $children]);

                $expected = collect($entry['documents'])
                    ->filter(fn ($d) => $d['condition'] === null || $d['condition']['equals'] === $children)
                    ->pluck('key')->values()->all();
                $checklist = collect(RelationshipRequirements::for($this->relationship->fresh()));

                $this->assertSame($expected, $checklist->where('kind', 'document')->pluck('key')->values()->all(), $entry['key']);
                $this->assertSame(
                    array_column($entry['staffConfirmations'], 'key'),
                    $checklist->where('kind', 'confirmation')->pluck('key')->values()->all(),
                    $entry['key']
                );
                $this->assertSame(
                    array_column($entry['staffConfirmations'], 'policy'),
                    $checklist->where('kind', 'confirmation')->pluck('policy')->values()->all(),
                    $entry['key']
                );
                $this->assertSame([$entry['governmentId']['policy']], $checklist->where('kind', 'valid_id')->pluck('policy')->values()->all());
                foreach ($checklist->where('kind', 'document') as $item) {
                    $this->assertSame($entry['documentPolicy'], $item['policy'], "{$entry['key']}.{$item['key']}");
                }
            }
        }
    }

    public function test_filtering_by_relationship_type(): void
    {
        $this->getJson('/api/registration/requirements?relationshipType=child')
            ->assertOk()
            ->assertJsonCount(1, 'relationshipTypes')
            ->assertJsonPath('relationshipTypes.0.key', 'child')
            ->assertJsonPath('minorVisitors.minimumAge', 3);
    }

    public function test_unknown_or_legacy_relationship_type_is_rejected(): void
    {
        foreach (['friend', 'immediate_family', 'unknown_or_other', 'Spouse'] as $type) {
            $this->get('/api/registration/requirements?relationshipType=' . urlencode($type))
                ->assertStatus(422)
                ->assertJsonPath('message', 'Unknown relationship type.')
                ->assertJsonValidationErrors('relationshipType');
        }
    }

    public function test_it_reads_and_writes_nothing_in_the_database(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/registration/requirements')->assertOk();
        $this->getJson('/api/registration/requirements?relationshipType=spouse')->assertOk();

        $this->assertSame([], $queries);
    }

    public function test_it_never_discloses_pdl_records(): void
    {
        $plain = $this->getJson('/api/registration/requirements')->assertOk()->getContent();

        // PDL-ish query parameters are ignored: same answer, no lookup.
        $probe = $this->getJson('/api/registration/requirements?' . http_build_query([
            'pdlName' => $this->pdl->full_name,
            'pdlNumber' => $this->pdl->pdl_number,
        ]))->assertOk()->getContent();

        $this->assertSame($plain, $probe);
        $this->assertStringNotContainsString($this->pdl->full_name, $plain);
        $this->assertStringNotContainsString($this->pdl->pdl_number, $plain);
    }

    public function test_documents_endpoint_keeps_its_shape_and_adds_policy_metadata(): void
    {
        $this->relationship->update(['relationship_type' => 'extended_relative', 'verification_status' => 'pending']);
        Sanctum::actingAs($this->visitorAccount);

        $this->getJson('/api/documents')
            ->assertOk()
            ->assertJsonStructure(['relationships' => [[
                'id', 'relationshipType', 'relationshipLabel', 'pdlName', 'status', 'hasSupportingDocument',
                'requirements' => [['key', 'kind', 'label', 'description', 'status', 'canUpload', 'rejectionReason', 'uploadedAt', 'reviewedAt', 'policy', 'providedBy']],
            ]]])
            ->assertJsonPath('relationships.0.requirements.1.key', 'official_authorization')
            ->assertJsonPath('relationships.0.requirements.1.kind', 'document')
            // Existing upload workflow unchanged: still uploadable from the app.
            ->assertJsonPath('relationships.0.requirements.1.canUpload', true)
            ->assertJsonPath('relationships.0.requirements.1.providedBy', 'facility')
            ->assertJsonPath('relationships.0.requirements.1.policy', 'pending_facility_confirmation');
    }
}
