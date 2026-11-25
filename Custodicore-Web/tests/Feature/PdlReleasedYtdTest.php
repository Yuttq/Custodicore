<?php

namespace Tests\Feature;

use App\Models\Pdl;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * "Released YTD" on PDL Records: PDLs released since January 1 of this
 * year, counted from released_at (set when the status becomes released),
 * so a later edit to an old release does not count it again.
 */
class PdlReleasedYtdTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();
    }

    private function pdl(string $number): Pdl
    {
        return Pdl::create([
            'pdl_number' => $number, 'first_name' => 'Test', 'last_name' => $number,
            'date_of_birth' => '1990-01-01', 'gender' => 'male', 'classification' => 'non_drug_related',
            'admission_date' => '2024-01-01', 'custody_status' => 'active',
            'registered_by' => StaffProfile::first()->staff_id,
        ]);
    }

    private function releasedYtd(): int
    {
        return $this->actingAs($this->recordOfficerAccount, 'web')
            ->get(route('pdl.index'))->assertOk()->viewData('stats')['released_ytd'];
    }

    public function test_released_at_is_set_on_release_and_cleared_on_readmission(): void
    {
        $pdl = $this->pdl('REL-1');
        $this->assertNull($pdl->released_at);

        $pdl->update(['custody_status' => 'released']);
        $this->assertSame('2026-11-25', $pdl->fresh()->released_at->toDateString());

        $this->travel(2)->days();
        $pdl->update(['alias' => 'Bong']); // a later edit keeps the release date
        $this->assertSame('2026-11-25', $pdl->fresh()->released_at->toDateString());

        $pdl->update(['custody_status' => 'active']);
        $this->assertNull($pdl->fresh()->released_at);
    }

    public function test_released_ytd_counts_only_releases_since_january_first(): void
    {
        $this->travelTo(CarbonImmutable::parse('2025-12-30 10:00:00', 'Asia/Manila'));
        $lastYear = $this->pdl('REL-2025');
        $lastYear->update(['custody_status' => 'released']);

        $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00', 'Asia/Manila'));
        $thisYear = $this->pdl('REL-2026');
        $thisYear->update(['custody_status' => 'released']);

        // Editing last year's release this year does not count it again.
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', 'Asia/Manila'));
        $lastYear->update(['alias' => 'Edited']);

        $this->assertSame(1, $this->releasedYtd());
        $this->actingAs($this->recordOfficerAccount, 'web')->get(route('pdl.index'))
            ->assertSee('Released YTD')->assertSee('since Jan 1, 2026');
    }

    public function test_the_backfill_takes_the_release_date_from_the_audit_log(): void
    {
        $pdl = $this->pdl('OLD-REL');
        Pdl::withoutEvents(fn () => Pdl::whereKey($pdl->pdl_id)->update(['custody_status' => 'released', 'released_at' => null]));
        \App\Models\AuditLog::record('update', 'pdl_profiles', $pdl->pdl_id, 'Custody status: active → released');
        \App\Models\AuditLog::where('record_id', $pdl->pdl_id)->update(['created_at' => '2026-02-14 09:00:00']);

        $migration = require database_path('migrations/2026_11_25_000300_add_released_at_to_pdl_profiles.php');
        $migration->down();
        $migration->up();

        $this->assertSame('2026-02-14', $pdl->fresh()->released_at->toDateString());
        $this->assertSame(1, $this->releasedYtd());
    }
}
