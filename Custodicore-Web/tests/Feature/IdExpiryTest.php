<?php

namespace Tests\Feature;

use App\Models\VisitorId;
use App\Models\VisitRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * The Record Officer records when a visitor's ID expires (or that it has no
 * expiry date). An ID that has expired by the visit date blocks booking;
 * Visitor Records flags IDs that expired or expire within 30 days.
 *
 * "Now" is Wednesday 2026-10-07; the fixture's bookable session is Friday
 * 2026-10-09 09:00.
 */
class IdExpiryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const FRIDAY = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();
    }

    private function currentId(): VisitorId
    {
        return $this->visitor->fresh()->currentGovernmentId();
    }

    private function book()
    {
        Sanctum::actingAs($this->visitorAccount);

        return $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => self::FRIDAY,
            'startTime' => '09:00',
        ]);
    }

    // ------------------------------------------------------------ States

    public function test_expiry_states_and_labels(): void
    {
        $id = $this->currentId();
        $this->assertSame('unknown', $id->expiryState());
        $this->assertSame('Expiry not recorded', $id->expiryLabel());

        $id->update(['no_expiry' => true]);
        $this->assertSame('none', $id->fresh()->expiryState());
        $this->assertSame('No expiry date', $id->fresh()->expiryLabel());

        $id->update(['no_expiry' => false, 'expiry_date' => '2026-10-06']);
        $this->assertSame('expired', $id->fresh()->expiryState());
        $this->assertSame('Expired Oct 6, 2026', $id->fresh()->expiryLabel());

        $id->update(['expiry_date' => '2026-10-07']);
        $this->assertSame('soon', $id->fresh()->expiryState(), 'Valid through its expiry day.');

        $id->update(['expiry_date' => '2026-11-06']);
        $this->assertSame('soon', $id->fresh()->expiryState());
        $this->assertStringContainsString('(30 days left)', $id->fresh()->expiryLabel());

        $id->update(['expiry_date' => '2028-03-04']);
        $this->assertSame('valid', $id->fresh()->expiryState());
        $this->assertSame('Expires Mar 4, 2028', $id->fresh()->expiryLabel());
    }

    // ------------------------------------------------- Recording the expiry

    public function test_record_officer_records_the_expiry_date(): void
    {
        $id = $this->currentId();

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->post(route('visitor.ids.expiry', [$this->visitor->visitor_id, $id->visitor_id_doc_id]), ['id_expiry_date' => '2028-03-04'])
            ->assertSessionHas('success', 'ID expiry saved: Expires Mar 4, 2028.');

        $this->assertSame('2028-03-04', $id->fresh()->expiry_date->toDateString());
        $this->assertFalse($id->fresh()->no_expiry);
        $this->assertTrue(\App\Models\AuditLog::where('record_type', 'visitor_ids')->where('record_id', $id->visitor_id_doc_id)
            ->where('description', 'like', 'Recorded%expiry%Expires Mar 4, 2028')->exists());
    }

    public function test_no_expiry_date_clears_a_date(): void
    {
        $id = $this->currentId();
        $id->update(['expiry_date' => '2028-03-04']);

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->post(route('visitor.ids.expiry', [$this->visitor->visitor_id, $id->visitor_id_doc_id]), ['id_no_expiry' => '1', 'id_expiry_date' => '2028-03-04'])
            ->assertSessionHas('success');

        $this->assertTrue($id->fresh()->no_expiry);
        $this->assertNull($id->fresh()->expiry_date);
    }

    public function test_a_date_or_no_expiry_is_required(): void
    {
        $id = $this->currentId();

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->post(route('visitor.ids.expiry', [$this->visitor->visitor_id, $id->visitor_id_doc_id]), [])
            ->assertSessionHasErrors(['id_expiry_date' => 'Enter the expiry date printed on the ID, or tick "No expiry date".']);

        $this->assertSame('unknown', $id->fresh()->expiryState());
    }

    public function test_other_roles_cannot_record_an_expiry(): void
    {
        $id = $this->currentId();

        $this->actingAs($this->frontDeskAccount, 'web')
            ->post(route('visitor.ids.expiry', [$this->visitor->visitor_id, $id->visitor_id_doc_id]), ['id_expiry_date' => '2028-03-04'])
            ->assertForbidden();

        $this->assertNull($id->fresh()->expiry_date);
    }

    public function test_visitor_page_shows_the_expiry_and_the_form(): void
    {
        $this->currentId()->update(['expiry_date' => '2026-10-01']);

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->get(route('visitor.show', $this->visitor->visitor_id))->assertOk()
            ->assertSee('<th>Expiry</th>', false)
            ->assertSee('Expired Oct 1, 2026')
            ->assertSee('Cannot book visits until a new ID is approved.')
            ->assertSee('Change expiry');
    }

    public function test_scan_id_button_reads_the_ids_own_picture(): void
    {
        $id = $this->currentId();
        $src = route('visitor.ids.file', [$this->visitor->visitor_id, $id->visitor_id_doc_id]);

        $this->actingAs($this->recordOfficerAccount, 'web')
            ->get(route('visitor.show', $this->visitor->visitor_id))->assertOk()
            ->assertSee('data-id-scan data-src="' . $src . '"', false)
            ->assertSee('>Scan ID</button>', false)
            ->assertSee('js/id-expiry-scan.js', false);
    }

    // ------------------------------------------------------------ Booking

    public function test_an_id_expired_before_the_visit_date_blocks_booking(): void
    {
        $this->currentId()->update(['expiry_date' => '2026-10-08']); // the day before Friday's visit

        $this->book()->assertUnprocessable()
            ->assertJsonPath('errors.id.0', 'Your National ID expired on Oct 8, 2026. Upload a new ID before booking.');
        $this->assertSame(0, VisitRequest::count());
    }

    public function test_an_id_valid_on_the_visit_day_books(): void
    {
        $this->currentId()->update(['expiry_date' => self::FRIDAY]);

        $this->book()->assertCreated()->assertJsonPath('status', 'confirmed');
    }

    public function test_ids_without_an_expiry_or_not_recorded_book(): void
    {
        $this->book()->assertCreated(); // not recorded: not blocked

        VisitRequest::query()->delete();
        \App\Models\VisitSchedule::query()->update(['slots_taken' => 0]);
        $this->currentId()->update(['no_expiry' => true]);
        $this->book()->assertCreated();
    }

    // ------------------------------------------------------ Visitor Records

    public function test_visitor_records_flags_expired_and_expiring_ids(): void
    {
        $this->actingAs($this->recordOfficerAccount, 'web');

        $this->currentId()->update(['expiry_date' => '2026-10-20']);
        $this->get(route('visitor.index'))->assertOk()->assertSee('ID expires soon')->assertDontSee('ID expired<', false);

        $this->currentId()->update(['expiry_date' => '2026-10-01']);
        $this->get(route('visitor.index'))->assertOk()->assertSee('ID expired');

        $this->currentId()->update(['expiry_date' => '2028-03-04']);
        $this->get(route('visitor.index'))->assertOk()->assertDontSee('ID expire');
    }
}
