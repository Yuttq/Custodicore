<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CellBlock;
use App\Models\Pdl;
use App\Models\PdlDisciplinaryRecord;
use App\Models\PdlLegalRecord;
use App\Models\PdlRestriction;
use App\Models\RelationshipDocument;
use App\Models\StaffProfile;
use App\Models\VisitorFlag;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Record Officer actions that record a staff ID (verified_by, reviewed_by,
 * flagged_by, registered_by, ...) record the signed-in officer's own staff
 * profile, and are refused without changing anything when the account has
 * no staff profile — never attributed to the first staff_profiles row.
 *
 * The fixture's first staff profile is Rico P. Salcedo; the actions here
 * are made by a second Record Officer so a "first profile" fallback would be
 * caught. Front desk check-in/out is in FrontDeskStaffAttributionTest.
 */
class StaffAttributionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;
    use MakesTestImages;

    private const NO_STAFF_PROFILE = 'Your account has no staff profile, so this action cannot be recorded. Ask an administrator to complete your staff profile.';

    private Account $officerAccount;
    private StaffProfile $officer;
    private int $visitorCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Mail::fake();
        Storage::fake('local');
        CellBlock::create(['name' => 'Dorm 1', 'capacity' => 10, 'designation' => 'any']);

        $this->officerAccount = $this->recordOfficer('a.delacruz');
        $this->officer = StaffProfile::create([
            'account_id' => $this->officerAccount->account_id,
            'employee_number' => 'EMP-022',
            'full_name' => 'Ana M. Dela Cruz',
            'position' => 'Records Officer',
        ]);
        $this->assertNotSame($this->officer->staff_id, StaffProfile::first()->staff_id);
    }

    private function recordOfficer(string $username): Account
    {
        return Account::create([
            'role_id' => $this->recordOfficerRole->role_id,
            'username' => $username,
            'email' => "{$username}@bjmp.gov.ph",
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);
    }

    private function newVisitor(string $status = 'pending'): VisitorProfile
    {
        $n = ++$this->visitorCount;
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => "visitor{$n}",
            'email' => "visitor{$n}@example.com",
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);

        return VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => "Visitor {$n}",
            'date_of_birth' => '1985-03-14',
            'gender' => 'female',
            'contact_number' => '0917000000' . $n,
            'verification_status' => $status,
        ]);
    }

    private function idDocument(VisitorProfile $visitor, string $status): VisitorId
    {
        return VisitorId::create([
            'visitor_id' => $visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-' . $visitor->visitor_id,
            'file_path' => 'x.png',
            'verification_status' => $status,
        ]);
    }

    private function pendingRelationship(VisitorProfile $visitor): VisitorPdlRelationship
    {
        return VisitorPdlRelationship::create([
            'visitor_id' => $visitor->visitor_id,
            'pdl_id' => $this->pdl->pdl_id,
            'relationship_type' => 'spouse',
            'priority_tier' => VisitorPdlRelationship::priorityTierFor('spouse'),
            'verification_status' => 'pending',
        ]);
    }

    private function document(VisitorPdlRelationship $relationship, string $status): RelationshipDocument
    {
        return RelationshipDocument::create([
            'relationship_id' => $relationship->relationship_id,
            'requirement_key' => 'marriage_certificate',
            'file_path' => 'relationship-documents/marriage_certificate.png',
            'status' => $status,
            'uploaded_at' => now(),
        ]);
    }

    /**
     * Every Record Officer action that records a staff ID. Each entry:
     *   setup()          builds the records the action needs → context
     *   send(ctx)        performs the request as the current user
     *   attributed(ctx)  the staff IDs the action wrote (null when absent)
     *   state(ctx)       a snapshot that must not change on refusal
     *
     * @return array<string, array{setup: callable, send: callable, attributed: callable, state: callable}>
     */
    private function actions(): array
    {
        return [
            'approve visitor account' => [
                'setup' => function () {
                    $visitor = $this->newVisitor();
                    $this->idDocument($visitor, 'verified');

                    return $visitor;
                },
                'send' => fn (VisitorProfile $v) => $this->post(route('visitor.approve', $v->visitor_id)),
                'attributed' => fn (VisitorProfile $v) => [$v->fresh()->verified_by],
                'state' => fn (VisitorProfile $v) => $v->fresh()->only(['verification_status', 'verified_by', 'verified_at']),
            ],
            'return visitor account' => [
                'setup' => fn () => $this->newVisitor(),
                'send' => fn (VisitorProfile $v) => $this->post(route('visitor.reject', $v->visitor_id), ['rejection_reason' => 'The ID photo is blurry.']),
                'attributed' => fn (VisitorProfile $v) => [$v->fresh()->verified_by],
                'state' => fn (VisitorProfile $v) => $v->fresh()->only(['verification_status', 'verified_by', 'rejection_reason']),
            ],
            'verify ID' => [
                'setup' => fn () => $this->idDocument($this->newVisitor(), 'pending'),
                'send' => fn (VisitorId $id) => $this->post(route('visitor.ids.verify', [$id->visitor_id, $id->visitor_id_doc_id])),
                'attributed' => fn (VisitorId $id) => [$id->fresh()->verified_by],
                'state' => fn (VisitorId $id) => $id->fresh()->only(['verification_status', 'verified_by', 'verified_at']),
            ],
            'reject ID' => [
                'setup' => fn () => $this->idDocument($this->newVisitor(), 'pending'),
                'send' => fn (VisitorId $id) => $this->post(route('visitor.ids.reject', [$id->visitor_id, $id->visitor_id_doc_id])),
                'attributed' => fn (VisitorId $id) => [$id->fresh()->verified_by],
                'state' => fn (VisitorId $id) => $id->fresh()->only(['verification_status', 'verified_by', 'verified_at']),
            ],
            'verify relationship' => [
                'setup' => function () {
                    $visitor = $this->newVisitor('verified');
                    $this->idDocument($visitor, 'verified');
                    $relationship = $this->pendingRelationship($visitor);
                    $this->document($relationship, 'verified');

                    return $relationship;
                },
                'send' => fn (VisitorPdlRelationship $r) => $this->post(route('visitor.relationships.verify', [$r->visitor_id, $r->relationship_id])),
                'attributed' => fn (VisitorPdlRelationship $r) => [$r->fresh()->verified_by],
                'state' => fn (VisitorPdlRelationship $r) => $r->fresh()->only(['verification_status', 'verified_by', 'verified_at']),
            ],
            'reject relationship' => [
                'setup' => fn () => $this->pendingRelationship($this->newVisitor('verified')),
                'send' => fn (VisitorPdlRelationship $r) => $this->post(route('visitor.relationships.reject', [$r->visitor_id, $r->relationship_id])),
                'attributed' => fn (VisitorPdlRelationship $r) => [$r->fresh()->verified_by],
                'state' => fn (VisitorPdlRelationship $r) => $r->fresh()->only(['verification_status', 'verified_by', 'verified_at']),
            ],
            'verify relationship document' => [
                'setup' => fn () => $this->document($this->pendingRelationship($this->newVisitor('verified')), 'pending'),
                'send' => fn (RelationshipDocument $d) => $this->post(route('relationship-documents.verify', $d->document_id)),
                'attributed' => fn (RelationshipDocument $d) => [$d->fresh()->reviewed_by],
                'state' => fn (RelationshipDocument $d) => $d->fresh()->only(['status', 'reviewed_by', 'reviewed_at']),
            ],
            'reject relationship document' => [
                'setup' => fn () => $this->document($this->pendingRelationship($this->newVisitor('verified')), 'pending'),
                'send' => fn (RelationshipDocument $d) => $this->post(route('relationship-documents.reject', $d->document_id), ['rejection_reason' => 'The certificate is cut off.']),
                'attributed' => fn (RelationshipDocument $d) => [$d->fresh()->reviewed_by],
                'state' => fn (RelationshipDocument $d) => $d->fresh()->only(['status', 'reviewed_by', 'rejection_reason']),
            ],
            'officer confirmation' => [
                'setup' => fn () => $this->pendingRelationship($this->newVisitor('verified')),
                'send' => fn (VisitorPdlRelationship $r) => $this->post(route('visitor.relationships.confirm', [$r->visitor_id, $r->relationship_id, 'no_immediate_family'])),
                'attributed' => fn (VisitorPdlRelationship $r) => [$r->fresh()->officer_confirmations['no_immediate_family']['by'] ?? null],
                'state' => fn (VisitorPdlRelationship $r) => $r->fresh()->only(['officer_confirmations']),
            ],
            'flag visitor' => [
                'setup' => fn () => $this->newVisitor('verified'),
                'send' => fn (VisitorProfile $v) => $this->post(route('visitor.flags.store', $v->visitor_id), [
                    'flag_type' => 'rule_violation',
                    'description' => 'Tried to bring food past the gate.',
                ]),
                'attributed' => fn (VisitorProfile $v) => [VisitorFlag::where('visitor_id', $v->visitor_id)->value('flagged_by')],
                'state' => fn (VisitorProfile $v) => ['flags' => VisitorFlag::where('visitor_id', $v->visitor_id)->count()],
            ],
            'resolve flag' => [
                'setup' => fn () => VisitorFlag::create([
                    'visitor_id' => $this->newVisitor('verified')->visitor_id,
                    'flag_type' => 'rule_violation',
                    'description' => 'Tried to bring food past the gate.',
                    'flagged_by' => StaffProfile::first()->staff_id,
                    'status' => 'active',
                ]),
                'send' => fn (VisitorFlag $f) => $this->post(route('visitor.flags.resolve', [$f->visitor_id, $f->flag_id])),
                'attributed' => fn (VisitorFlag $f) => [$f->fresh()->resolved_by],
                'state' => fn (VisitorFlag $f) => $f->fresh()->only(['status', 'resolved_by', 'resolved_at']),
            ],
            'register PDL' => [
                'setup' => fn () => null,
                'send' => fn () => $this->post('/pdls', [
                    'first_name' => 'Juan',
                    'last_name' => 'Dela Cruz',
                    'date_of_birth' => '1991-05-05',
                    'gender' => 'male',
                    'admission_date' => now()->subMonth()->toDateString(),
                    'cell_block' => 'Dorm 1',
                    'photo' => $this->fakePhoto(),
                    'case_number' => 'CR-2026-0142',
                    'offense_code' => 'theft',
                    'court' => 'RTC Branch 12',
                ]),
                'attributed' => function () {
                    $pdl = Pdl::where('full_name', 'Juan Dela Cruz')->first();

                    return [$pdl?->registered_by, $pdl?->legalRecords()->value('recorded_by')];
                },
                'state' => fn () => [
                    'pdls' => Pdl::count(),
                    'legal_records' => PdlLegalRecord::count(),
                    'photos' => count(Storage::disk('local')->allFiles('pdl-photos')),
                ],
            ],
            'add legal record' => [
                'setup' => fn () => null,
                'send' => fn () => $this->post("/pdls/{$this->pdl->pdl_id}/legal-records", ['case_number' => 'CR-9', 'offense_code' => 'theft']),
                'attributed' => fn () => [PdlLegalRecord::where('case_number', 'CR-9')->value('recorded_by')],
                'state' => fn () => ['legal_records' => PdlLegalRecord::count(), 'classification' => $this->pdl->fresh()->classification],
            ],
            'add disciplinary record' => [
                'setup' => fn () => null,
                'send' => fn () => $this->post("/pdls/{$this->pdl->pdl_id}/disciplinary-records", [
                    'incident_date' => now()->toDateString(),
                    'description' => 'Fight in the dormitory area.',
                ]),
                'attributed' => fn () => [PdlDisciplinaryRecord::where('pdl_id', $this->pdl->pdl_id)->value('recorded_by')],
                'state' => fn () => ['disciplinary_records' => PdlDisciplinaryRecord::count()],
            ],
            'add restriction' => [
                'setup' => fn () => null,
                'send' => fn () => $this->post("/pdls/{$this->pdl->pdl_id}/restrictions", [
                    'restriction_type' => 'quarantine',
                    'start_date' => now()->toDateString(),
                    'reason' => 'Medical isolation',
                ]),
                'attributed' => fn () => [PdlRestriction::where('pdl_id', $this->pdl->pdl_id)->value('imposed_by')],
                'state' => fn () => ['restrictions' => PdlRestriction::count()],
            ],
        ];
    }

    // ---------------------------------------------------- Attribution

    public function test_every_action_records_the_signed_in_officer_not_the_first_profile(): void
    {
        $first = StaffProfile::first()->staff_id;

        foreach ($this->actions() as $name => $action) {
            $context = $action['setup']();

            $this->actingAs($this->officerAccount)->from('/dashboard');
            $action['send']($context)->assertSessionHasNoErrors()->assertSessionMissing('error');

            foreach ($action['attributed']($context) as $staffId) {
                $this->assertSame($this->officer->staff_id, $staffId === null ? null : (int) $staffId, "{$name}: recorded the wrong officer.");
                $this->assertNotSame($first, (int) $staffId, "{$name}: fell back to the first staff profile.");
            }
        }
    }

    public function test_each_officer_is_recorded_as_themselves(): void
    {
        $rico = $this->recordOfficerAccount->staffProfile;

        $ricoId = $this->idDocument($this->newVisitor(), 'pending');
        $anaId = $this->idDocument($this->newVisitor(), 'pending');

        $this->actingAs($this->recordOfficerAccount)
            ->post(route('visitor.ids.verify', [$ricoId->visitor_id, $ricoId->visitor_id_doc_id]))->assertSessionHas('success');
        $this->actingAs($this->officerAccount)
            ->post(route('visitor.ids.verify', [$anaId->visitor_id, $anaId->visitor_id_doc_id]))->assertSessionHas('success');

        $this->assertSame((int) $rico->staff_id, (int) $ricoId->fresh()->verified_by);
        $this->assertSame($this->officer->staff_id, (int) $anaId->fresh()->verified_by);
    }

    public function test_audit_entries_name_the_acting_account(): void
    {
        $visitor = $this->newVisitor();
        $this->idDocument($visitor, 'verified');
        $relationship = $this->pendingRelationship($this->newVisitor('verified'));

        $this->actingAs($this->officerAccount);
        $this->post(route('visitor.approve', $visitor->visitor_id))->assertSessionHas('success');
        $this->post(route('visitor.relationships.reject', [$relationship->visitor_id, $relationship->relationship_id]))->assertSessionHas('success');

        foreach ([['visitor_profiles', $visitor->visitor_id], ['visitor_pdl_relationships', $relationship->relationship_id]] as [$type, $id]) {
            $entry = AuditLog::where('record_type', $type)->where('record_id', $id)->sole();
            $this->assertSame($this->officerAccount->account_id, (int) $entry->account_id);
        }
    }

    // ------------------------------------------------------- Refusals

    public function test_an_officer_without_a_staff_profile_is_refused_and_nothing_changes(): void
    {
        $noProfile = $this->recordOfficer('no.profile');
        $auditCount = AuditLog::count();

        foreach ($this->actions() as $name => $action) {
            $context = $action['setup']();
            $before = $action['state']($context);

            $this->actingAs($noProfile)->from('/dashboard');
            $action['send']($context)->assertRedirect('/dashboard')->assertSessionHas('error', self::NO_STAFF_PROFILE);

            $this->assertEquals($before, $action['state']($context), "{$name}: changed state although it was refused.");
        }

        $this->assertSame($auditCount, AuditLog::count(), 'A refused action wrote an audit entry.');
    }

    // ------------------------------------------------ Role restrictions

    public function test_other_roles_and_guests_still_cannot_use_record_officer_actions(): void
    {
        foreach ($this->actions() as $name => $action) {
            $context = $action['setup']();
            $before = $action['state']($context);

            $this->app['auth']->forgetGuards();
            $action['send']($context)->assertRedirect(route('login'));

            // The front desk officer has a staff profile but not the role.
            foreach ([$this->frontDeskAccount, $this->visitorAccount] as $account) {
                $this->actingAs($account);
                $action['send']($context)->assertForbidden();
            }

            $this->assertEquals($before, $action['state']($context), "{$name}: changed state for an unauthorized user.");
        }
    }

    // --------------------------------------------- Existing behaviour

    public function test_reviews_still_change_the_records_they_review(): void
    {
        $this->actingAs($this->officerAccount);

        $visitor = $this->newVisitor();
        $this->idDocument($visitor, 'verified');
        $this->post(route('visitor.approve', $visitor->visitor_id))->assertSessionHas('success', 'Visitor approved. The visitor has been notified.');
        $this->assertSame('verified', $visitor->fresh()->verification_status);
        $this->assertNotNull($visitor->fresh()->verified_at);

        $returned = $this->newVisitor();
        $this->post(route('visitor.reject', $returned->visitor_id), ['rejection_reason' => 'The ID photo is blurry.'])->assertSessionHas('success');
        $this->assertSame('rejected', $returned->fresh()->verification_status);
        $this->assertSame('The ID photo is blurry.', $returned->fresh()->rejection_reason);

        $id = $this->idDocument($this->newVisitor(), 'pending');
        $this->post(route('visitor.ids.reject', [$id->visitor_id, $id->visitor_id_doc_id]))->assertSessionHas('success', 'ID document rejected.');
        $this->assertSame('rejected', $id->fresh()->verification_status);

        // Verification is still gated on the checklist.
        $unmet = $this->pendingRelationship($this->newVisitor('verified'));
        $this->post(route('visitor.relationships.verify', [$unmet->visitor_id, $unmet->relationship_id]))
            ->assertSessionHas('error', fn ($m) => str_starts_with($m, 'This relationship cannot be verified yet.'));
        $this->assertSame('pending', $unmet->fresh()->verification_status);
        $this->assertNull($unmet->fresh()->verified_by);

        $relationship = $this->pendingRelationship($this->newVisitor('verified'));
        $this->post(route('visitor.relationships.reject', [$relationship->visitor_id, $relationship->relationship_id]))->assertSessionHas('success', 'Relationship rejected.');
        $this->assertSame('rejected', $relationship->fresh()->verification_status);
        $this->assertNotNull($relationship->fresh()->verified_at);
    }
}
