<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\VisitCheckin;
use App\Support\Sanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Attendance Log — read-only list of every gate entry (check-in / check-out)
 * for one day. Lets the Front Desk look back at earlier days; nothing here
 * edits or deletes a record.
 */
class AttendanceLogController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $date = $this->selectedDate((string) $request->input('date', ''));

        // Cleaned and capped; matched literally, so % and _ are not wildcards.
        $query = mb_substr(Sanitizer::text((string) $request->input('q', '')) ?? '', 0, 100);

        $dayEntries = VisitCheckin::whereNotNull('check_in_time')
            ->whereDate('check_in_time', $date);

        // Day totals ignore the search box so they always describe the whole day.
        $total = (clone $dayEntries)->count();
        $stillInside = (clone $dayEntries)->where('status', 'checked_in')->count();

        $stats = [
            ['label' => 'Gate Entries', 'value' => (string) $total, 'hint' => 'Check-ins on this day', 'accent' => 'info', 'icon' => 'qrcode'],
            ['label' => 'Checked Out', 'value' => (string) ($total - $stillInside), 'hint' => 'Left the facility', 'accent' => 'success', 'icon' => 'check-circle'],
            ['label' => $date->isToday() ? 'Still Inside' : 'Never Checked Out', 'value' => (string) $stillInside, 'hint' => $date->isToday() ? 'Not yet checked out' : 'No check-out recorded', 'accent' => $stillInside > 0 && ! $date->isToday() ? 'danger' : 'warning', 'icon' => 'calendar'],
        ];

        $entries = $dayEntries
            ->when($query !== '', function ($q) use ($query) {
                $like = Sanitizer::like($query);
                $q->where(fn ($match) => $match
                    ->whereHas('visitRequest', fn ($visit) => $visit->where(fn ($people) => $people
                        ->whereHas('visitor', fn ($v) => $v->where('full_name', 'like', $like))
                        ->orWhereHas('pdl', fn ($p) => $p->where('full_name', 'like', $like)->orWhere('pdl_number', 'like', $like))))
                    ->orWhereHas('checkInOfficer', fn ($o) => $o->where('full_name', 'like', $like))
                    ->orWhereHas('checkOutOfficer', fn ($o) => $o->where('full_name', 'like', $like)));
            })
            ->with(['visitRequest.visitor', 'visitRequest.pdl', 'checkInOfficer', 'checkOutOfficer'])
            ->orderByDesc('check_in_time')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('frontdesk.attendance-log', [
            'entries' => $entries,
            'stats' => $stats,
            'date' => $date,
            'query' => $query,
        ]);
    }

    /** The requested day, or today when it is missing, malformed or in the future. */
    private function selectedDate(string $value): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                $date = Carbon::createFromFormat('!Y-m-d', $value);
                if ($date && $date->format('Y-m-d') === $value && ! $date->isFuture()) {
                    return $date;
                }
            } catch (\Throwable) {
                // Fall through to today.
            }
        }

        return today();
    }
}
