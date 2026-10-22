<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VisitCheckin;
use App\Support\DateSearch;
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
 * cannot check anyone in or out manually. One search box finds records by a
 * date, month or year ("2026-10-20", "October 2026", "2026"), a time, or a
 * reference / visitor name, and the PDF export uses the same search.
 */
class CheckinController extends Controller
{
    public function index(Request $request): View
    {
        $search = Sanitizer::text($request->query('q'));
        $parsed = DateSearch::parse($search);

        $checkins = $this->rows($parsed);

        $checkedIn = count(array_filter($checkins, fn ($c) => $c['status'] === 'checked_in'));

        $summary = [
            ['label' => 'Visitors Entered', 'value' => (string) count($checkins), 'icon' => 'qrcode', 'accent' => 'info'],
            ['label' => 'Currently Inside', 'value' => (string) $checkedIn, 'icon' => 'check-circle', 'accent' => 'success'],
        ];

        // Kept for the view's sake even though nothing on this read-only
        // page lets you pick a name anymore — harmless if the view no
        // longer uses it.
        $suggestedVisitors = VisitorController::verifiedNames();

        $filtered = $search !== null;
        $dateLabel = $parsed['label'];
        $checkins = Pages::of($checkins);

        return view('admin.checkins.index', compact('checkins', 'summary', 'suggestedVisitors', 'search', 'filtered', 'dateLabel'));
    }

    /** PDF of every row matching the page's filters (24-hour times). */
    public function export(Request $request): Response
    {
        $search = Sanitizer::text($request->query('q'));
        $parsed = DateSearch::parse($search);
        $rows = $this->rows($parsed);

        $inside = count(array_filter($rows, fn ($c) => $c['status'] === 'checked_in'));

        $meta = [
            'Showing: ' . ($search === null ? 'All records' : ($parsed['label'] ?? 'Search "' . $search . '"')),
            'Visitors entered: ' . count($rows) . '   ·   Currently inside: ' . $inside . '   ·   Times are in 24-hour format',
        ];

        $columns = [
            ['label' => 'Date', 'width' => 66],
            ['label' => 'Reference', 'width' => 115],
            ['label' => 'Visitor', 'width' => 120],
            ['label' => 'PDL', 'width' => 125],
            ['label' => 'Purpose', 'width' => 140],
            ['label' => 'Check-In', 'width' => 52],
            ['label' => 'Check-Out', 'width' => 62],
            ['label' => 'Status', 'width' => 80],
        ];

        $body = array_map(fn ($c) => [
            $c['date'],
            $c['reference'],
            $c['visitor'],
            $c['pdl'],
            $c['purpose'],
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

    /** Every matching gate record, newest first. */
    private function rows(array $parsed): array
    {
        $needle = $parsed['text'] !== null ? mb_strtolower($parsed['text']) : null;

        return VisitCheckin::query()
            ->with(['visitRequest.visitor', 'visitRequest.pdl', 'visitRequest.relationship'])
            ->whereNotNull('check_in_time')
            ->when($parsed['active'], fn ($q) => DateSearch::apply($q, 'check_in_time', $parsed))
            ->orderByDesc('check_in_time')
            ->get()
            ->map(fn ($c) => [
                'date' => $c->check_in_time?->format('Y-m-d'),
                'reference' => 'VIS-' . ($c->visitRequest?->created_at?->format('Y-md') ?? now()->format('Y-md')) . '-' . str_pad((string) $c->visit_request_id, 3, '0', STR_PAD_LEFT),
                'visitor' => $c->visitRequest?->visitor?->full_name ?? 'Unknown',
                'pdl' => $c->visitRequest?->pdl?->full_name ?? '—',
                // There is no separate "purpose" field on a visit request, so it is
                // worked out from the visitor's relationship to the PDL.
                'purpose' => $c->visitRequest?->relationship?->relationship_type === 'legal_counsel'
                    ? 'Legal consultation'
                    : 'Family visit',
                'check_in_time' => $c->check_in_time?->format('H:i'),
                'check_out_time' => $c->check_out_time?->format('H:i'),
                'status' => $c->status,
            ])
            ->when($needle, fn ($rows) => $rows->filter(
                fn ($r) => str_contains(mb_strtolower($r['reference']), $needle)
                    || str_contains(mb_strtolower($r['visitor']), $needle)
                    || str_contains(mb_strtolower($r['pdl']), $needle)
            ))
            ->values()
            ->all();
    }
}
