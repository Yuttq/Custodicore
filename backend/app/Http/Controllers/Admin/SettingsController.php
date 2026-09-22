<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** System settings + the role -> module access matrix (Module 1.1). */
class SettingsController extends Controller
{
    public function index(): View
    {
        $settings = [
            ['key' => 'visit.max_per_week', 'value' => '2', 'description' => 'Maximum visits a single visitor may attend per week'],
            ['key' => 'visit.confirmation_window_hours', 'value' => '48', 'description' => 'Hours a visitor has to confirm an assigned schedule'],
            ['key' => 'qr.expiry_minutes', 'value' => '180', 'description' => 'Minutes a generated QR code stays valid'],
            ['key' => 'schedule.slot_capacity_default', 'value' => '30', 'description' => 'Default visitor capacity per time slot'],
        ];

        $roles = [
            ['role_name' => 'System Administrator/Warden', 'access' => 'Full access — every module'],
            ['role_name' => 'Record Officer', 'access' => 'PDL, Visitor, Scheduling, Eligibility (view/create/edit); Audit & Reporting (view only)'],
            ['role_name' => 'Front Desk Officer', 'access' => 'Scheduling (view only); QR Check-In/Out (view/create/edit)'],
            ['role_name' => 'Visitor', 'access' => 'Own profile, own schedules and QR (mobile app only)'],
        ];

        return view('admin.settings.index', compact('settings', 'roles'));
    }
}
