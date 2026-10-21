<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VisitCheckin;
use App\Support\Pages;
use App\Support\Sanitizer;
use App\Support\SimplePdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Module 1.5 Visit Check-In and Check-Out (QR code-based) — read-only.
 *
 * Actual check-in/out happens at the gate (Front Desk module scanning a
 * visitor's QR — see \App\Http\Controllers\FrontDesk\CheckinCheckoutController).
 * This admin view only lists the real visit_checkins rows; the admin
 * cannot check anyone in or out manually. The list can be searched, limited
 * to a date range, and exported as a PDF (same filters, all matching rows).
 */
class CheckinController extends Controller
{
    public function index(Request $request): View
    {
        [$search, $from, $to] = $this->filters($request);

        $checkins = $this->rows($search, $from, $to);

        $checkedIn = count(array_filter($checkins, fn ($c) => $c['status'] === 'checked_in'));

        $summary = [
            ['label' => 'Visitors Entered', 'value' => (string) count($checkins), 'icon' => 'qrcode', 'accent' => 'info'],
            ['label' => 'Currently Inside', 'value' => (string) $checkedIn, 'icon' => 'check-circle', 'accent' => 'success'],
        ];

        // Kept for the view's sake even though nothing on this read-only
        // page lets you pick a name anymore — harmless if the view no
        // longer uses it.
        $suggestedVisitors = VisitorController::verifiedNames();

        $filtered = $search !== null || $from || $to;
        $checkins = Pages::of($checkins);

        return view('admin.checkins.index', compact('checkins', 'summary', 'suggestedVisitors', 'search', 'from', 'to', 'filtered'));
    }

    /** PDF of every row matching the page's filters (24-hour times). */
    public function export(Request $request): Response
    {
        [$search, $from, $to] = $this->filters($request);
        $rows = $this->rows($search, $from, $to);

        $inside = count(array_filter($rows, fn ($c) => $c['status'] === 'checked_in'));

        $range = match (true) {
            $from && $to => "{$from} to {$to}",
            (bool) $from => "from {$from}",
            (bool) $to => "up to {$to}",
            default => 'All dates',
        };
        $meta = [
            'Date range: ' . $range . ($search !== null ? '   ·   Search: "' . $search . '"' : ''),
            'Visitors entered: ' . count($rows) . '   ·   Currently inside: ' . $inside . '   ·   Times are in 24-hour format',
        ];

        $columns = [
            ['label' => 'Date', 'width' => 90],
            ['label' => 'Reference', 'width' => 160],
            ['label' => 'Visitor', 'width' => 220],
            ['label' => 'Check-In', 'width' => 80],
            ['label' => 'Check-Out', 'width' => 90],
            ['label' => 'Status', 'width' => 120],
        ];

        $body = array_map(fn ($c) => [
            $c['date'],
            $c['reference'],
            $c['visitor'],
            $c['check_in_time'] ?? '—',
            $c['check_out_time'] ?? 'Still inside',
            ucwords(str_replace('_', ' ', (string) $c['status'])),
        ], $rows);

        $pdf = SimplePdf::table('Check-In / Check-Out Log', $meta, $columns, $body, 'CustodiCore · Admin · Check-In / Check-Out');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="checkin-checkout-' . now()->format('Y-m-d_Hi') . '.pdf"',
        ]);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} search, from, to ("YYYY-MM-DD") */
    private function filters(Request $request): array
    {
        $search = Sanitizer::text($request->query('q'));
        $from = $this->dateParam($request->query('from'));
        $to = $this->dateParam($request->query('to'));
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$search, $from, $to];
    }

    /** Every matching gate record, newest first. */
    private function rows(?string $search, ?string $from, ?string $to): array
    {
        $needle = $search !== null ? mb_strtolower($search) : null;

        return VisitCheckin::query()
            ->with('visitRequest.visitor')
            ->whereNotNull('check_in_time')
            ->when($from, fn ($q) => $q->whereDate('check_in_time', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('check_in_time', '<=', $to))
            ->orderByDesc('check_in_time')
            ->get()
            ->map(fn ($c) => [
                'date' => $c->check_in_time?->format('Y-m-d'),
                'reference' => 'VIS-' . ($c->visitRequest?->created_at?->format('Y-md') ?? now()->format('Y-md')) . '-' . str_pad((string) $c->visit_request_id, 3, '0', STR_PAD_LEFT),
                'visitor' => $c->visitRequest?->visitor?->full_name ?? 'Unknown',
                'check_in_time' => $c->check_in_time?->format('H:i'),
                'check_out_time' => $c->check_out_time?->format('H:i'),
                'status' => $c->status,
            ])
            ->when($needle, fn ($rows) => $rows->filter(
                fn ($r) => str_contains(mb_strtolower($r['reference']), $needle)
                    || str_contains(mb_strtolower($r['visitor']), $needle)
            ))
            ->values()
            ->all();
    }

    /** "YYYY-MM-DD" if it's a real calendar date, otherwise null. */
    private function dateParam(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }
}
