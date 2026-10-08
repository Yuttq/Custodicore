<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every admin page sits behind 'auth' + 'role:System Administrator/Warden'
     * and reads from the database, so a signed-in Warden account on a
     * migrated (empty) database should get 200 on each of them.
     */
    public function test_dashboard_and_module_pages_render(): void
    {
        $adminRole = Role::create(['role_name' => 'System Administrator/Warden', 'description' => 'Admin']);
        $admin = Account::create([
            'role_id' => $adminRole->role_id,
            'username' => 'warden',
            'email' => 'warden@bjmp.gov.ph',
            'password_hash' => Hash::make('password'),
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'web');

        foreach ([
            'admin.dashboard',
            'admin.users.index',
            'admin.pdls.index',
            'admin.visitors.index',
            'admin.schedules.index',
            'admin.checkins.index',
            'admin.audit.index',
            'admin.settings.index',
        ] as $routeName) {
            $response = $this->get(route($routeName));
            $response->assertOk();
        }
    }
}
