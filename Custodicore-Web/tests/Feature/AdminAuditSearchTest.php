<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Admin Audit Trail: the one search box filters by date (day, month) or by text. */
class AdminAuditSearchTest extends TestCase
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

        foreach (['2026-09-28 09:00' => 'September entry', '2026-10-15 14:30' => 'Middle entry', '2026-10-20 08:15' => 'Today entry'] as $at => $text) {
            $log = AuditLog::record('update', 'pdl_profiles', 1, $text);
            $log->forceFill(['created_at' => $at])->save();
        }
    }

    public function test_a_date_or_month_in_the_search_box_filters_by_date(): void
    {
        $this->get('/admin/audit?q=2026-10-15')->assertOk()
            ->assertSee('Middle entry')->assertDontSee('Today entry')->assertDontSee('September entry')
            ->assertSee('1 of 3 entries match');

        $this->get('/admin/audit?q=October 2026')->assertOk()
            ->assertSee('Middle entry')->assertSee('Today entry')->assertDontSee('September entry');
    }

    public function test_plain_text_searches_descriptions_and_shows_24_hour_times(): void
    {
        $this->get('/admin/audit?q=Today')->assertOk()
            ->assertSee('Today entry')->assertDontSee('Middle entry')
            ->assertSee('2026-10-20 08:15');
    }
}
