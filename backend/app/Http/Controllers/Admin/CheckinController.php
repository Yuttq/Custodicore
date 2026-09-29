<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VisitCheckin;
use Illuminate\View\View;

/**
 * Module 1.5 Visit Check-In and Check-Out (QR code-based) — read-only.
 *
 * Actual check-in/out happens at the gate (Front Desk module scanning a
 * visitor's QR — see \App\Http\Controllers\FrontDesk\CheckinCheckoutController).
 * This admin view only lists the real visit_checkins rows; the admin
 * cannot check anyone in or out manually.
 */
class CheckinController extends Controller
{
    public function index(): View
    {
        $checkins = VisitCheckin::query()
            ->with('visitRequest.visitor')
            ->whereNotNull('check_in_time')
            ->orderByDesc('check_in_time')
            ->get()
            ->map(fn ($c) => [
                'reference' => 'VIS-' . ($c->visitRequest?->created_at?->format('Y-md') ?? now()->format('Y-md')) . '-' . str_pad((string) $c->visit_request_id, 3, '0', STR_PAD_LEFT),
                'visitor' => $c->visitRequest?->visitor?->full_name ?? 'Unknown',
                'check_in_time' => $c->check_in_time?->format('h:i A'),
                'check_out_time' => $c->check_out_time?->format('h:i A'),
                'status' => $c->status,
            ])
            ->all();

        $checkedIn = count(array_filter($checkins, fn ($c) => $c['status'] === 'checked_in'));

        $summary = [
            ['label' => 'Visitors Entered', 'value' => (string) count($checkins), 'icon' => 'qrcode', 'accent' => 'info'],
            ['label' => 'Currently Inside', 'value' => (string) $checkedIn, 'icon' => 'check-circle', 'accent' => 'success'],
        ];

        // Kept for the view's sake even though nothing on this read-only
        // page lets you pick a name anymore — harmless if the view no
        // longer uses it.
        $suggestedVisitors = VisitorController::verifiedNames();

        return view('admin.checkins.index', compact('checkins', 'summary', 'suggestedVisitors'));
    }
}
