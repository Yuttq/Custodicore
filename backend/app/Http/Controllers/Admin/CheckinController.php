<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Module 1.5 Visit Check-In and Check-Out (QR code-based).
 * Only visitors who have actually entered the facility are listed here
 * (i.e. checked in, whether or not they've checked out yet).
 *
 * Data lives in the session only (no database yet): checking a visitor in
 * or out persists for as long as your browser session lasts, and resets
 * when the session expires.
 */
class CheckinController extends Controller
{
    public const SESSION_KEY = 'checkins_rows';

    public function index(): View
    {
        $checkins = self::rows();

        $checkedIn = count(array_filter($checkins, fn ($c) => $c['status'] === 'checked_in'));

        $summary = [
            ['label' => 'Visitors Entered', 'value' => (string) count($checkins), 'icon' => 'qrcode', 'accent' => 'info'],
            ['label' => 'Currently Inside', 'value' => (string) $checkedIn, 'icon' => 'check-circle', 'accent' => 'success'],
        ];

        // Suggest names from verified registered visitors, so checking
        // someone in feels connected to Visitor Management rather than
        // requiring a name to be typed out of thin air.
        $suggestedVisitors = VisitorController::verifiedNames();

        return view('admin.checkins.index', compact('checkins', 'summary', 'suggestedVisitors'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'visitor' => ['required', 'string', 'max:120'],
        ]);

        $checkins = self::rows();
        $nextId = self::nextId($checkins);

        $checkins[] = [
            'id' => $nextId,
            'reference' => 'VIS-' . now()->format('Y-md') . '-' . str_pad((string) $nextId, 3, '0', STR_PAD_LEFT),
            'visitor' => $data['visitor'],
            'check_in_time' => now()->format('h:i A'),
            'check_out_time' => null,
            'status' => 'checked_in',
        ];

        session([self::SESSION_KEY => $checkins]);

        AuditLog::record('check_in', 'QR Check-In and Check-Out', "Checked in visitor {$data['visitor']}");

        return redirect()->route('admin.checkins.index')->with('status', "Checked in {$data['visitor']}.");
    }

    public function checkOut(int $id): RedirectResponse
    {
        $checkins = self::rows();

        foreach ($checkins as &$checkin) {
            if ($checkin['id'] === $id) {
                $checkin['status'] = 'checked_out';
                $checkin['check_out_time'] = now()->format('h:i A');
                AuditLog::record('check_out', 'QR Check-In and Check-Out', "Checked out visitor {$checkin['visitor']}");
                break;
            }
        }
        unset($checkin);

        session([self::SESSION_KEY => $checkins]);

        return redirect()->route('admin.checkins.index')->with('status', 'Visitor checked out.');
    }

    public static function rows(): array
    {
        if (! session()->has(self::SESSION_KEY)) {
            session([self::SESSION_KEY => self::defaultRows()]);
        }

        return session(self::SESSION_KEY);
    }

    private static function nextId(array $rows): int
    {
        return $rows === [] ? 1 : max(array_column($rows, 'id')) + 1;
    }

    private static function defaultRows(): array
    {
        return [
            ['id' => 1, 'reference' => 'VIS-2026-0922-001', 'visitor' => 'Maria D. Santos', 'check_in_time' => '09:04 AM', 'check_out_time' => null, 'status' => 'checked_in'],
            ['id' => 2, 'reference' => 'VIS-2026-0922-002', 'visitor' => 'Carlo J. Ramos', 'check_in_time' => '09:11 AM', 'check_out_time' => '11:20 AM', 'status' => 'checked_out'],
            ['id' => 3, 'reference' => 'VIS-2026-0921-011', 'visitor' => 'Grace T. Manalo', 'check_in_time' => '10:02 AM', 'check_out_time' => '12:35 PM', 'status' => 'checked_out'],
            ['id' => 4, 'reference' => 'VIS-2026-0921-013', 'visitor' => 'Liza P. Aquino', 'check_in_time' => '01:15 PM', 'check_out_time' => null, 'status' => 'checked_in'],
        ];
    }
}
