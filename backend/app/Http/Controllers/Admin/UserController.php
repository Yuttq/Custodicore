<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Module 1.1 User Management — accounts table (roles: System
 * Administrator/Warden, Record Officer, Front Desk Officer, Visitor).
 */
class UserController extends Controller
{
    public function index(): View
    {
        $accounts = [
            ['employee_number' => 'EMP-001', 'full_name' => 'Warden Ana R. Domingo', 'role_name' => 'System Administrator/Warden', 'email' => 'a.domingo@bjmp.gov.ph', 'status' => 'active', 'last_login_at' => '2026-09-22 07:58'],
            ['employee_number' => 'EMP-014', 'full_name' => 'Rico P. Salcedo', 'role_name' => 'Record Officer', 'email' => 'r.salcedo@bjmp.gov.ph', 'status' => 'active', 'last_login_at' => '2026-09-22 08:02'],
            ['employee_number' => 'EMP-022', 'full_name' => 'Grace T. Manalo', 'role_name' => 'Record Officer', 'email' => 'g.manalo@bjmp.gov.ph', 'status' => 'active', 'last_login_at' => '2026-09-21 17:40'],
            ['employee_number' => 'EMP-031', 'full_name' => 'Noel D. Fernandez', 'role_name' => 'Front Desk Officer', 'email' => 'n.fernandez@bjmp.gov.ph', 'status' => 'active', 'last_login_at' => '2026-09-22 09:00'],
            ['employee_number' => 'EMP-037', 'full_name' => 'Jhoana C. Reyes', 'role_name' => 'Front Desk Officer', 'email' => 'j.reyes@bjmp.gov.ph', 'status' => 'inactive', 'last_login_at' => '2026-08-30 15:11'],
        ];

        return view('admin.users.index', compact('accounts'));
    }
}
