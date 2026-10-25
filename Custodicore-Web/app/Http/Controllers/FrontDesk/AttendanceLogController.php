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
 * for one day (today by default), filtered by search and check-in method.
 * Lets the Front Desk look back at earlier days; nothing here edits or
 * deletes a record.
 */
class AttendanceLogController extends Controller
{
    private const PER_PAGE = 25;

    /** ?method= => verification_method. */
    public const METHODS = ['qr_scan' => 'QR Scan', 'manual_override' => 'Manual'];

    public function index(Request $request): View
    {
        $date = $this->parseDay((string) $request->input('date', '')) ?? today();

        // Cleaned and capped; matched literally, so % and _ are not wildcards.
        $query = mb_substr(Sanitizer::text((string) $request->input('q', '')) ?? '', 0, 100);
        $method = array_key_exists((string) $request->input('method'), self::METHODS) ? (string) $request->input('method') : '';

        $entries = VisitCheckin::whereNotNull('check_in_time')
            ->whereDate('check_in_time', $date)
            ->when($method !== '', fn ($q) => $q->where('verification_method', $method))
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
            'date' => $date,
            'query' => $query,
            'method' => $method,
            'filtered' => $query !== '' || $method !== '' || ! $date->isToday(),
        ]);
    }

    /** A Y-m-d day that is real and not in the future, or null (then today). */
    private function parseDay(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);

            return $date && $date->format('Y-m-d') === $value && ! $date->isFuture() ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
