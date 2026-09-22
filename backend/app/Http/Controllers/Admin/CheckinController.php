<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** Module 1.5 Visit Check-In and Check-Out (QR code-based). */
class CheckinController extends Controller
{
    public function index(): View
    {
        $checkins = [
            ['reference' => 'VIS-2026-0922-001', 'visitor' => 'Maria D. Santos', 'id_surrendered' => "Driver's License", 'check_in_time' => '09:04 AM', 'check_out_time' => null, 'status' => 'checked_in'],
            ['reference' => 'VIS-2026-0922-002', 'visitor' => 'Carlo J. Ramos', 'id_surrendered' => 'National ID', 'check_in_time' => '09:11 AM', 'check_out_time' => '11:20 AM', 'status' => 'checked_out'],
            ['reference' => 'VIS-2026-0922-003', 'visitor' => 'Fe M. Lopez', 'id_surrendered' => 'UMID', 'check_in_time' => null, 'check_out_time' => null, 'status' => 'awaiting_arrival'],
            ['reference' => 'VIS-2026-0921-019', 'visitor' => 'Dante R. Cabrera', 'id_surrendered' => null, 'check_in_time' => null, 'check_out_time' => null, 'status' => 'no_show'],
        ];

        return view('admin.checkins.index', compact('checkins'));
    }
}
