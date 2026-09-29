<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        $settings = [
            ['key' => 'qr.expiry_minutes', 'value' => SystemSetting::value('qr.expiry_minutes', '180'), 'description' => 'Minutes a generated QR code stays valid'],
            ['key' => 'visit.confirmation_window_hours', 'value' => SystemSetting::value('visit.confirmation_window_hours', '48'), 'description' => 'Hours a visitor has to confirm an assigned schedule'],
        ];

        return view('frontdesk.settings', compact('settings'));
    }
}
