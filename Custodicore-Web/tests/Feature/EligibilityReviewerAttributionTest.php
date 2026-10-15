<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\EligibilityAssessment;
use App\Models\RelationshipDocument;
use App\Models\StaffProfile;
use App\Models\VisitorId;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * POST /eligibility/{assessment}/review records the signed-in officer's own
 * staff profile as reviewed_by — never a stand-in — and is refused when the
 * account has no staff profile or is not a Record Officer.
 *
 * The fixture's first staff profile is Rico P. Salcedo; reviews here are
 * made by a second Record Officer so a "first staff profile" fallback would
 * be caught.
 */
class EligibilityReviewerAttributionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private Account $reviewerAccount;
    private StaffProfile $reviewer;
    private EligibilityAssessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        $this->reviewerAccount = $this->recordOfficer('a.delacruz');
        $this->reviewer = StaffProfile::create([
            'account_id' => $this->reviewerAccount->account_id,
            'employee_number' => 'EMP-022',
            'full_name' => 'Ana M. Dela Cruz',
            'position' => 'Records Officer',
        ]);
        $this->assertNotSame($this->reviewer->staff_id, StaffProfile::first()->staff_id);

        // No verified ID yet: the request is flagged for review.
        $this->relationship->update(['relationship_type' => 'spouse']);
        $visit = app(VisitAssignmentService::class)->submitVisitorRequest(
            $this->visitor, $this->relationship, CarbonImmutable::parse('2026-10-09', 'Asia/Manila'), '09:00'
        );
        $this->assessment = $visit->eligibilityAssessment;
        $this->assertTrue($this->assessment->needsReview());
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

    private function meetRequirements(): void
    {
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'x.png',
            'verification_status' => 'verified',
        ]);
        RelationshipDocument::create([
            'relationship_id' => $this->relationship->relationship_id,
            'requirement_key' => 'marriage_certificate',
            'file_path' => 'relationship-documents/marriage_certificate.png',
            'status' => 'verified',
            'uploaded_at' => now(),
        ]);
    }

    private function review(string $decision): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('eligibility.review', $this->assessment->assessment_id), ['decision' => $decision]);
    }

    private function assertUnreviewed(): void
    {
        $assessment = $this->assessment->fresh();
        $this->assertSame('flagged_for_review', $assessment->overall_result);
        $this->assertNull($assessment->reviewed_by);
        $this->assertNull($assessment->reviewed_at);
    }

    // ---------------------------------------------------- Attribution

    public function test_eligible_review_records_the_signed_in_officer(): void
    {
        $this->meetRequirements();

        $this->actingAs($this->reviewerAccount)->review('eligible')->assertSessionHas('success');

        $assessment = $this->assessment->fresh();
        $this->assertSame('eligible', $assessment->overall_result);
        $this->assertSame($this->reviewer->staff_id, (int) $assessment->reviewed_by);
        $this->assertSame('Ana M. Dela Cruz', $assessment->reviewedBy->full_name);
        $this->assertNotNull($assessment->reviewed_at);
        $this->assertTrue(AuditLog::where('record_type', 'eligibility_assessments')
            ->where('record_id', $assessment->assessment_id)
            ->where('account_id', $this->reviewerAccount->account_id)
            ->where('description', 'like', 'Approved visit request%')
            ->exists());
    }

    public function test_rejected_review_records_the_signed_in_officer(): void
    {
        $this->actingAs($this->reviewerAccount)->review('rejected')->assertSessionHas('success');

        $assessment = $this->assessment->fresh();
        $this->assertSame('rejected', $assessment->overall_result);
        $this->assertSame($this->reviewer->staff_id, (int) $assessment->reviewed_by);
        $this->assertNotNull($assessment->reviewed_at);
        $this->assertTrue(AuditLog::where('record_type', 'eligibility_assessments')
            ->where('record_id', $assessment->assessment_id)
            ->where('account_id', $this->reviewerAccount->account_id)
            ->where('description', 'like', 'Rejected visit request%')
            ->exists());
    }

    public function test_each_officer_is_recorded_as_themselves(): void
    {
        $this->actingAs($this->recordOfficerAccount)->review('rejected')->assertSessionHas('success');

        $this->assertSame(
            (int) $this->recordOfficerAccount->staffProfile->staff_id,
            (int) $this->assessment->fresh()->reviewed_by
        );
    }

    // ------------------------------------------------------- Refusals

    public function test_record_officer_without_a_staff_profile_cannot_review(): void
    {
        $this->meetRequirements();
        $noProfile = $this->recordOfficer('no.profile');

        foreach (['eligible', 'rejected'] as $decision) {
            $this->actingAs($noProfile)->review($decision)
                ->assertSessionHas('error', 'Your account has no staff profile, so this review cannot be recorded. Ask an administrator to complete your staff profile.');
            $this->assertUnreviewed();
        }
        $this->assertFalse(AuditLog::where('record_type', 'eligibility_assessments')
            ->where('record_id', $this->assessment->assessment_id)
            ->where('action_type', 'update')
            ->exists());
    }

    public function test_other_roles_and_guests_cannot_review(): void
    {
        $this->meetRequirements();

        $this->review('eligible')->assertRedirect(route('login'));
        $this->assertUnreviewed();

        // The front desk officer has a staff profile but not the role.
        foreach ([$this->frontDeskAccount, $this->visitorAccount] as $account) {
            $this->actingAs($account)->review('eligible')->assertForbidden();
            $this->actingAs($account)->review('rejected')->assertForbidden();
        }
        $this->assertUnreviewed();
    }

    public function test_an_already_reviewed_assessment_keeps_its_reviewer(): void
    {
        $this->actingAs($this->reviewerAccount)->review('rejected')->assertSessionHas('success');

        $this->actingAs($this->recordOfficerAccount)->review('rejected')
            ->assertSessionHas('error', 'This assessment has already been reviewed.');

        $this->assertSame($this->reviewer->staff_id, (int) $this->assessment->fresh()->reviewed_by);
    }

    // ---------------------------------------------------- Concurrency

    public function test_a_stale_copy_cannot_overwrite_a_review_made_in_the_meantime(): void
    {
        $firstReviewer = $this->recordOfficerAccount->staffProfile;

        // The second officer's request loads the assessment while it is still
        // unreviewed; before it writes, the first officer's review commits.
        $raced = false;
        EligibilityAssessment::retrieved(function (EligibilityAssessment $loaded) use (&$raced, $firstReviewer) {
            if ($raced || $loaded->assessment_id !== $this->assessment->assessment_id) {
                return;
            }
            $raced = true;
            $this->assertTrue($loaded->needsReview(), 'The second request holds a stale, unreviewed copy.');
            EligibilityAssessment::whereKey($loaded->assessment_id)->update([
                'overall_result' => 'rejected',
                'reviewed_by' => $firstReviewer->staff_id,
                'reviewed_at' => now(),
            ]);
        });

        $this->actingAs($this->reviewerAccount)->review('rejected')
            ->assertSessionHas('error', 'This assessment has already been reviewed.');

        $this->assertTrue($raced);
        $assessment = $this->assessment->fresh();
        $this->assertSame('rejected', $assessment->overall_result);
        $this->assertSame((int) $firstReviewer->staff_id, (int) $assessment->reviewed_by);
        $this->assertFalse(AuditLog::where('record_type', 'eligibility_assessments')
            ->where('record_id', $assessment->assessment_id)
            ->where('account_id', $this->reviewerAccount->account_id)
            ->where('action_type', 'update')
            ->exists());
    }
}
