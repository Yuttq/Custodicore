<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Pdl;
use App\Models\StaffProfile;
use App\Models\VisitorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Two PDLs with the same name: the visitor may give the PDL's birthday at
 * registration, and Account Review shows every PDL with that name side by
 * side (birthday, PDL number, dorm, admission, linked visitors) for the
 * Record Officer to choose from. Nothing is pre-selected unless exactly one
 * PDL has the name and the birthday (if given) agrees.
 */
class SameNamePdlTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhase1Fixtures();
        $this->withoutVite();
    }

    private function pdl(string $number, string $birth, string $dorm = 'Dorm 1'): Pdl
    {
        return Pdl::create([
            'pdl_number' => $number,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'date_of_birth' => $birth,
            'gender' => 'male',
            'classification' => 'non_drug_related',
            'admission_date' => '2025-03-01',
            'custody_status' => 'active',
            'cell_block' => $dorm,
            'registered_by' => StaffProfile::first()->staff_id,
        ]);
    }

    private function pendingVisitor(?string $pdlBirth): VisitorProfile
    {
        $account = Account::create([
            'role_id' => $this->visitorRole->role_id,
            'username' => 'ana.reyes',
            'email' => 'ana.reyes@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);

        return VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => 'Ana Reyes',
            'date_of_birth' => '1992-02-02',
            'contact_number' => '09171112222',
            'verification_status' => 'pending',
            'intended_pdl_name' => 'Juan Dela Cruz',
            'intended_pdl_birth_date' => $pdlBirth,
        ]);
    }

    private function reviewPage(): string
    {
        return $this->actingAs($this->recordOfficerAccount, 'web')
            ->get(route('account-reviews.index'))->assertOk()->getContent();
    }

    public function test_pdls_sharing_the_name_are_listed_side_by_side_and_none_is_preselected(): void
    {
        $a = $this->pdl('PDL-2025-001', '1980-05-10', 'Dorm 1');
        $b = $this->pdl('PDL-2025-002', '1991-11-23', 'Dorm 4');
        $this->pendingVisitor('1991-11-23');

        $html = $this->reviewPage();

        $this->assertStringContainsString('2 PDLs share the name "Juan Dela Cruz". Choose the right one below.', $html);
        $this->assertStringContainsString('Same name: compare before choosing', $html);
        $this->assertStringContainsString('PDL-2025-001', $html);
        $this->assertStringContainsString('PDL-2025-002', $html);
        $this->assertStringContainsString('Dorm 4', $html);
        $this->assertSame(1, substr_count($html, 'Birthday matches'), 'Only the PDL whose birthday they gave is tagged.');
        $this->assertSame(1, substr_count($html, 'Different birthday'));
        $this->assertStringContainsString('data-pdl-id="'.$a->pdl_id.'"', $html);
        $this->assertStringContainsString('data-pdl-id="'.$b->pdl_id.'"', $html);
        $this->assertStringContainsString('<input type="hidden" name="pdl_id" value=""', $html, 'The officer has to choose.');
        $this->assertStringContainsString("PDL's Birthday Given (unverified)", $html);
        $this->assertStringContainsString('Nov 23, 1991', $html);
    }

    public function test_one_name_match_with_the_same_birthday_is_preselected(): void
    {
        $only = $this->pdl('PDL-2025-001', '1991-11-23');
        $this->pendingVisitor('1991-11-23');

        $html = $this->reviewPage();

        $this->assertStringContainsString('<input type="hidden" name="pdl_id" value="'.$only->pdl_id.'"', $html);
        $this->assertStringContainsString('and the birthday', $html);
        $this->assertStringNotContainsString('data-same-name', $html);
    }

    public function test_one_name_match_with_a_different_birthday_is_not_preselected(): void
    {
        $this->pdl('PDL-2025-001', '1980-05-10');
        $this->pendingVisitor('1991-11-23');

        $html = $this->reviewPage();

        $this->assertStringContainsString('<input type="hidden" name="pdl_id" value=""', $html);
        $this->assertStringContainsString('but not the birthday they gave', $html);
        $this->assertStringContainsString('Name matches, birthday does not', $html);
    }

    public function test_without_a_birthday_one_name_match_is_still_preselected(): void
    {
        $only = $this->pdl('PDL-2025-001', '1980-05-10');
        $this->pendingVisitor(null);

        $html = $this->reviewPage();

        $this->assertStringContainsString('<input type="hidden" name="pdl_id" value="'.$only->pdl_id.'"', $html);
        $this->assertStringContainsString('Not given', $html);
    }
}
