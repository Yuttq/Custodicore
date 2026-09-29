<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    /**
     * Every admin page currently renders from sample data in its
     * controller, so these routes should respond 200 with no database
     * connection configured.
     */
    public function test_dashboard_and_module_pages_render(): void
    {
        foreach ([
            'admin.dashboard',
            'admin.users.index',
            'admin.pdls.index',
            'admin.visitors.index',
            'admin.schedules.index',
            'admin.checkins.index',
            'admin.eligibility.index',
            'admin.audit.index',
            'admin.settings.index',
        ] as $routeName) {
            $response = $this->get(route($routeName));
            $response->assertOk();
        }
    }
}
