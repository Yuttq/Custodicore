<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Admin Audit Trail: filter entries by date range (with or without search). */
class AdminAuditDateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));

        Module::create(['module_code' => Module::CODE_AUDIT_REPORTING, 'module_name' => 'Audit Trail and Basic Reporting']);
        $role = Role::create(['role_name' => 'System Administrator/Warden', 'description' => 'Admin']);
        $this->actingAs(Account::create([
            'role_id' => $role->role_id, 'username' => 'warden', 'email' => 'warden@bjmp.gov.ph',
            'password_hash' => Hash::make('password'), 'status' => 'active',
        ]), 'web');

        foreach (['2026-10-01 09:00' => 'Old entry', '2026-10-15 14:30' => 'Middle entry', '2026-10-20 08:15' => 'Today entry'] as $at => $text) {
            $log = AuditLog::record('update', 'pdl_profiles', 1, $text);
            $log->forceFill(['created_at' => $at])->save();
        }
    }

    public function test_filters_by_date_range(): void
    {
        $this->get('/admin/audit?from=2026-10-10&to=2026-10-16')->assertOk()
            ->assertSee('Middle entry')->assertDontSee('Old entry')->assertDontSee('Today entry')
            ->assertSee('1 of 3 entries match');

        $this->get('/admin/audit?from=2026-10-15')->assertSee('Middle entry')->assertSee('Today entry')->assertDontSee('Old entry');
        $this->get('/admin/audit?to=2026-10-01')->assertSee('Old entry')->assertDontSee('Middle entry');
    }

    public function test_date_range_combines_with_search_and_shows_24_hour_times(): void
    {
        $this->get('/admin/audit?q=entry&from=2026-10-20&to=2026-10-20')->assertOk()
            ->assertSee('Today entry')->assertDontSee('Middle entry')
            ->assertSee('2026-10-20 08:15');
    }

    public function test_reversed_or_invalid_dates_are_handled(): void
    {
        // Reversed range is swapped, not empty.
        $this->get('/admin/audit?from=2026-10-16&to=2026-10-10')->assertSee('Middle entry')->assertDontSee('Old entry');
        // Invalid dates are ignored.
        $this->get('/admin/audit?from=2026-02-31&to=garbage')->assertOk()->assertSee('Old entry')->assertSee('Today entry');
    }
}
