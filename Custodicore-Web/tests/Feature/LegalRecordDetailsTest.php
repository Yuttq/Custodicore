<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CellBlock;
use App\Models\CourtHearing;
use App\Models\Pdl;
use App\Models\PdlLegalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesTestImages;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Legal records: case status, date filed, court hearings (next + past) and
 * conviction (date + sentence), on Register PDL, Add Legal Record and Update.
 */
class LegalRecordDetailsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;
    use MakesTestImages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();
        Storage::fake('local');
        CellBlock::create(['name' => 'Dorm 1', 'capacity' => 20, 'designation' => 'any']);
        $this->actingAs($this->recordOfficerAccount, 'web');
    }

    private function register(array $overrides = [])
    {
        return $this->post('/pdls', array_merge([
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'date_of_birth' => '1991-05-05', 'gender' => 'male',
            'admission_date' => '2026-01-10', 'photo' => $this->fakePhoto(), 'cell_block' => 'Dorm 1',
            'case_number' => 'CR-2026-0100', 'offense_code' => 'theft', 'court' => 'RTC Branch 12',
        ], $overrides));
    }

    private function registeredRecord(): PdlLegalRecord
    {
        return Pdl::where('full_name', 'Juan Dela Cruz')->firstOrFail()->legalRecords()->firstOrFail();
    }

    public function test_registration_saves_status_date_filed_and_next_hearing(): void
    {
        $this->register([
            'case_status' => 'Pending trial', 'date_filed' => '2026-01-08',
            'next_hearing_at' => '2026-11-05T09:30', 'hearing_notes' => 'Arraignment',
        ])->assertSessionHasNoErrors();

        $record = $this->registeredRecord();
        $this->assertSame('Pending trial', $record->case_status);
        $this->assertSame('2026-01-08', $record->date_filed->toDateString());
        $this->assertNull($record->convicted_on);
        $hearing = $record->hearings()->sole();
        $this->assertSame('2026-11-05 09:30', $hearing->hearing_at->format('Y-m-d H:i'));
        $this->assertSame('Arraignment', $hearing->notes);

        $this->get("/pdls/{$record->pdl_id}")->assertOk()
            ->assertSee('Pending trial')
            ->assertSee('Nov 5, 2026')
            ->assertSee('Filed Jan 8, 2026');
    }

    public function test_status_defaults_to_pending_trial(): void
    {
        $this->register()->assertSessionHasNoErrors();
        $this->assertSame('Pending trial', $this->registeredRecord()->case_status);
    }

    public function test_conviction_needs_a_date_and_a_sentence(): void
    {
        $this->register(['case_status' => 'Convicted'])->assertSessionHasErrors('convicted_on');
        $this->register(['case_status' => 'Convicted', 'convicted_on' => '2026-09-01'])
            ->assertSessionHasErrors(['sentence_years' => 'Enter the sentence (years, months and/or days).']);
        $this->register(['case_status' => 'Convicted', 'date_filed' => '2026-05-01', 'convicted_on' => '2026-04-01', 'sentence_years' => 2])
            ->assertSessionHasErrors(['convicted_on' => 'The date of conviction cannot be before the date the case was filed.']);
        $this->assertSame(0, Pdl::where('full_name', 'Juan Dela Cruz')->count());
    }

    public function test_convicted_case_shows_sentence_and_estimated_release(): void
    {
        $this->register([
            'case_status' => 'Convicted', 'convicted_on' => '2026-09-01',
            'sentence_years' => 2, 'sentence_months' => 6,
        ])->assertSessionHasNoErrors();

        $record = $this->registeredRecord();
        $this->assertSame('2 years, 6 months', $record->sentenceLabel());
        // Admission Jan 10, 2026 + 2 years 6 months.
        $this->assertSame('2028-07-10', $record->estimatedRelease()->toDateString());
        $this->get("/pdls/{$record->pdl_id}")->assertSee('2 years, 6 months')->assertSee('Est. release Jul 10, 2028');
    }

    public function test_past_hearings_are_refused_and_kept_as_history(): void
    {
        $this->register(['next_hearing_at' => '2026-10-01T09:00'])->assertSessionHasErrors('next_hearing_at');

        $this->register(['next_hearing_at' => '2026-10-29T09:00'])->assertSessionHasNoErrors();
        $record = $this->registeredRecord();

        // After the hearing date it moves to the history.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'Asia/Manila'));
        $this->get("/pdls/{$record->pdl_id}")->assertSee('None set')->assertSee('1 past hearing');
    }

    public function test_update_records_a_conviction_and_the_next_hearing_and_logs_it(): void
    {
        $this->register()->assertSessionHasNoErrors();
        $record = $this->registeredRecord();

        $this->patch(route('pdl.legal-records.update', [$record->pdl_id, $record->legal_record_id]), [
            'case_status' => 'Convicted, on appeal', 'convicted_on' => '2026-10-01', 'sentence_years' => 4,
            'next_hearing_at' => '2026-12-01T13:00', 'remarks' => 'Appeal filed with the CA',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $record->refresh();
        $this->assertSame('Convicted, on appeal', $record->case_status);
        $this->assertSame('4 years', $record->sentenceLabel());
        $this->assertSame(1, $record->hearings()->count());
        $this->assertTrue(AuditLog::where('record_type', 'pdl_legal_records')
            ->where('description', 'like', '%status Pending trial → Convicted, on appeal%')
            ->where('description', 'like', '%next hearing Dec 1, 2026 13:00%')
            ->exists());

        // Acquitted: the conviction is cleared and no hearing is added.
        $this->patch(route('pdl.legal-records.update', [$record->pdl_id, $record->legal_record_id]), [
            'case_status' => 'Acquitted', 'convicted_on' => '2026-10-01', 'sentence_years' => 4, 'next_hearing_at' => '2026-12-15T09:00',
        ])->assertSessionHasNoErrors();
        $record->refresh();
        $this->assertNull($record->convicted_on);
        $this->assertNull($record->sentenceLabel());
        $this->assertSame(1, CourtHearing::count());
    }

    public function test_update_only_touches_the_pdls_own_record(): void
    {
        $this->register()->assertSessionHasNoErrors();
        $record = $this->registeredRecord();

        $this->patch(route('pdl.legal-records.update', [$this->pdl->pdl_id, $record->legal_record_id]), ['case_status' => 'Dismissed'])
            ->assertNotFound();
        $this->assertSame('Pending trial', $record->fresh()->case_status);
    }
}
