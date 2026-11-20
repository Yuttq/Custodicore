<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FacilityVisitationRule;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\SystemSetting;
use App\Models\VisitorId;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Models\VisitSession;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsVisitAssignmentFixtures;
use Tests\TestCase;

/**
 * Admin → Settings → Visiting Rules: the weekly visit limit per visitor
 * (visit.max_per_week, all PDLs together). Password re-entered, 1–7,
 * audited. New bookings follow the new limit at once; visits already
 * booked are never cancelled or changed by it.
 *
 * "Now" is Wednesday 2026-10-07 10:00 Asia/Manila; Friday 2026-10-09 and
 * Sunday 2026-10-11 are in the same Monday–Sunday week.
 */
class WeeklyVisitLimitSettingTest extends TestCase
{
    use RefreshDatabase;
    use SeedsVisitAssignmentFixtures;

    private const ADMIN_PASSWORD = 'Correct-admin-pass1';
    private const FRIDAY = '2026-10-09';
    private const SUNDAY = '2026-10-11';
    private const LIMIT_MESSAGE = 'You have reached the maximum number of visits allowed for the week (Monday–Sunday) of the selected date.';

    private Account $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila'));
        $this->seedPhase1Fixtures();
        $this->withoutVite();

        FacilityVisitationRule::create([
            'pdl_classification' => 'non_drug_related',
            'day_of_week' => 'sun',
            'time_slot_start' => '09:00:00',
            'time_slot_end' => '11:30:00',
            'max_capacity' => 30,
            'effective_from' => '2024-01-01',
        ]);
        VisitorId::create([
            'visitor_id' => $this->visitor->visitor_id,
            'id_type' => 'national_id',
            'id_number' => 'ID-1',
            'file_path' => 'x.png',
            'verification_status' => 'verified',
        ]);
        $this->publishSessions('2026-10-01', '2026-10-31');

        $this->admin = Account::create([
            'role_id' => Role::where('role_name', 'System Administrator/Warden')->value('role_id'),
            'username' => 'warden.limit',
            'email' => 'warden.limit@example.com',
            'password_hash' => Hash::make(self::ADMIN_PASSWORD),
            'status' => 'active',
        ]);
        StaffProfile::create([
            'account_id' => $this->admin->account_id,
            'employee_number' => 'EMP-901',
            'first_name' => 'Ana',
            'last_name' => 'Domingo',
            'position' => 'Warden',
            'contact_number' => '09171234568',
            'personal_email' => 'warden.limit@example.com',
        ]);
    }

    // ------------------------------------------------------------ Helpers

    private function save(mixed $value, ?string $password = self::ADMIN_PASSWORD, ?Account $as = null)
    {
        return $this->actingAs($as ?? $this->admin, 'web')
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.visiting-rules'), array_merge(
                ['max_per_week' => $value],
                $password === null ? [] : ['current_password' => $password],
            ));
    }

    private function limit(): int
    {
        return app(ScheduleAvailabilityService::class)->maxVisitsPerWeek();
    }

    /** POST /api/visit-requests as the fixture visitor. */
    private function request(string $date, array $startTimes = ['09:00'])
    {
        Sanctum::actingAs($this->visitorAccount);

        return $this->postJson('/api/visit-requests', [
            'relationshipId' => $this->relationship->relationship_id,
            'date' => $date,
            'startTimes' => $startTimes,
        ]);
    }

    private function sundaySchedule(): VisitSchedule
    {
        return VisitSchedule::whereDate('schedule_date', self::SUNDAY)->where('time_slot_start', '09:00:00')->sole();
    }

    // ------------------------------------------------------ Page and saving

    public function test_settings_page_shows_the_current_limit_in_its_own_card(): void
    {
        $this->actingAs($this->admin, 'web')->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Visiting Rules')
            ->assertSee('name="max_per_week"', false)
            ->assertSee('value="2"', false)
            // Not listed again in the read-only System Settings table.
            ->assertDontSee('visit.max_per_week');
    }

    public function test_admin_changes_the_limit_and_it_is_audited(): void
    {
        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'visit.max_per_week']);

        $this->save('3')
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('status');

        $staffId = $this->admin->staffProfile->staff_id;
        $this->assertDatabaseHas('system_settings', [
            'setting_key' => 'visit.max_per_week',
            'setting_value' => '3',
            'updated_by' => $staffId,
        ]);
        $this->assertSame(3, $this->limit());

        $this->assertDatabaseHas('audit_logs', [
            'account_id' => $this->admin->account_id,
            'action_type' => 'update',
            'record_type' => 'system_settings',
            'description' => 'Changed weekly visit limit from 2 to 3 per visitor',
        ]);
        $this->assertNotNull(\App\Models\AuditLog::where('description', 'like', 'Changed weekly visit limit%')->value('created_at'));

        // A second change updates the same row and records old → new again.
        $this->save('1');
        $this->assertSame(1, SystemSetting::where('setting_key', 'visit.max_per_week')->count());
        $this->assertDatabaseHas('audit_logs', ['description' => 'Changed weekly visit limit from 3 to 1 per visitor']);
    }

    public function test_saving_the_same_value_writes_no_audit_entry(): void
    {
        $this->save('2')->assertSessionHas('status');

        $this->assertDatabaseMissing('audit_logs', ['record_type' => 'system_settings']);
    }

    public function test_values_outside_1_to_7_or_not_whole_numbers_are_refused(): void
    {
        foreach (['0', '8', '-1', '', 'abc', '2.5', '1e1'] as $bad) {
            $this->save($bad)->assertSessionHasErrors('max_per_week');
        }

        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'visit.max_per_week']);
        $this->assertSame(2, $this->limit());
    }

    public function test_both_ends_of_the_range_are_accepted(): void
    {
        $this->save('1')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->limit());

        $this->save('7')->assertSessionHasNoErrors();
        $this->assertSame(7, $this->limit());
    }

    public function test_wrong_or_missing_password_saves_nothing(): void
    {
        $this->save('3', 'wrong-password')->assertSessionHasErrors('current_password');
        $this->save('3', null)->assertSessionHasErrors('current_password');

        $this->assertSame(2, $this->limit());
        $this->assertDatabaseMissing('audit_logs', ['record_type' => 'system_settings']);
    }

    public function test_only_the_admin_can_change_it(): void
    {
        foreach ([$this->recordOfficerAccount, $this->frontDeskAccount] as $staff) {
            $this->save('5', 'password', $staff)->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->put(route('admin.settings.visiting-rules'), ['max_per_week' => '5'])->assertRedirect();

        $this->assertSame(2, $this->limit());
    }

    // ------------------------------------------------------ Enforcement

    public function test_new_limit_applies_to_visitor_requests_and_the_app_calendar_at_once(): void
    {
        $this->save('1');

        $this->request(self::FRIDAY)->assertCreated();

        Sanctum::actingAs($this->visitorAccount);
        $sunday = $this->getJson('/api/schedules/availability?from='.self::SUNDAY.'&to='.self::SUNDAY)
            ->assertOk()->json('relationships.0.days.0');
        $this->assertSame('weekly_limit', $sunday['slots'][0]['reason']);

        $this->request(self::SUNDAY)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule_id' => self::LIMIT_MESSAGE]);
        $this->assertSame(1, VisitRequest::count());

        // Raising it again lets the same request through.
        $this->save('2');
        $this->request(self::SUNDAY)->assertCreated();
    }

    public function test_new_limit_applies_to_staff_assignments(): void
    {
        $this->request(self::FRIDAY)->assertCreated();
        $this->save('1');

        try {
            app(VisitAssignmentService::class)->assign([
                'visitor_id' => $this->visitor->visitor_id,
                'pdl_id' => $this->pdl->pdl_id,
                'relationship_id' => $this->relationship->relationship_id,
                'schedule_id' => $this->sundaySchedule()->schedule_id,
            ]);
            $this->fail('A staff assignment over the weekly limit was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(self::LIMIT_MESSAGE, collect($e->errors())->flatten()->first());
        }

        $this->assertSame(1, VisitRequest::count());
    }

    public function test_lowering_the_limit_never_cancels_or_changes_existing_bookings(): void
    {
        // Two visits this week under the default limit of 2: one approved, one awaiting review.
        $friday = $this->request(self::FRIDAY)->assertCreated()->json('id');
        $sunday = $this->request(self::SUNDAY)->assertCreated()->json('id');
        $this->completeRequirements();
        app(VisitAssignmentService::class)->approveVisitorRequest(VisitRequest::findOrFail($friday));

        $snapshot = fn () => [
            'visits' => VisitRequest::orderBy('visit_request_id')
                ->get(['visit_request_id', 'status', 'schedule_id', 'cancelled_at', 'cancellation_reason', 'confirmed_at'])
                ->toArray(),
            'sessions' => VisitSession::orderBy('visit_session_id')->get(['visit_request_id', 'schedule_id'])->toArray(),
            'seats' => VisitSchedule::orderBy('schedule_id')->pluck('slots_taken', 'schedule_id')->all(),
        ];
        $before = $snapshot();
        $this->assertSame(['confirmed', 'assigned'], array_column($before['visits'], 'status'));

        $this->save('1')->assertSessionHasNoErrors();

        // Nothing changed when saving, nor after the nightly job runs.
        $this->assertSame($before, $snapshot());
        Artisan::call('visits:expire');
        $this->assertSame($before, $snapshot());

        // The Record Officer can still approve the visit that was already waiting.
        $this->assertSame('confirmed', app(VisitAssignmentService::class)
            ->approveVisitorRequest(VisitRequest::findOrFail($sunday))->status);
    }
}
