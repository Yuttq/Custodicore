<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\VisitCheckin;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Support\Sanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class VisitorLookupController extends Controller
{
    /** Shortest search worth running; one letter matches almost everyone. */
    private const MIN_QUERY = 2;

    /** Visit statuses that still lead to a gate visit. */
    private const UPCOMING_STATUSES = ['assigned', 'pending_confirmation', 'confirmed'];

    public function index(Request $request): View
    {
        // Cleaned (no tags / extra spaces) and capped; matched literally, so % and _ are not wildcards.
        $query = mb_substr(Sanitizer::text((string) $request->input('q', '')) ?? '', 0, 100);
        $tooShort = $query !== '' && mb_strlen($query) < self::MIN_QUERY;

        $visitors = collect();
        if ($query !== '' && ! $tooShort) {
            // A phone number matches however it was typed: 0917 123 4567, 09171234567, +63 917...
            $digits = preg_replace('/\D+/', '', $query) ?? '';
            $phoneTail = strlen($digits) >= 7 ? substr($digits, -10) : null;

            $visitors = VisitorProfile::query()
                ->where(function ($q) use ($query, $phoneTail) {
                    $q->where('full_name', 'like', Sanitizer::like($query))
                        ->orWhere('contact_number', 'like', Sanitizer::like($query));

                    if ($phoneTail) {
                        $q->orWhereRaw(
                            "REPLACE(REPLACE(REPLACE(contact_number, ' ', ''), '-', ''), '+', '') LIKE ?",
                            ['%'.$phoneTail]
                        );
                    }
                })
                ->with(['relationships.pdl', 'activeFlags'])
                ->orderBy('full_name')
                ->take(20)
                ->get();
        }

        return view('frontdesk.visitor-lookup', compact('visitors', 'query', 'tooShort'));
    }

    /**
     * Read-only visitor profile for the gate: identity, approved PDLs, IDs on
     * file, today's visit, upcoming visits, gate history and flags.
     */
    public function show(Request $request, VisitorProfile $visitor): View
    {
        $visitor->load([
            'account',
            'relationships.pdl',
            'idDocuments' => fn ($q) => $q->orderByDesc('uploaded_at'),
            'flags' => fn ($q) => $q->orderByDesc('created_at'),
        ]);

        $visits = VisitRequest::where('visitor_id', $visitor->visitor_id)
            ->with(['pdl', 'schedule', 'sessionSchedules', 'checkins'])
            ->get()
            ->sortBy(fn (VisitRequest $v) => $v->schedule?->schedule_date)
            ->values();

        $todayVisits = $visits
            ->filter(fn (VisitRequest $v) => $v->schedule?->schedule_date?->isToday())
            ->map(fn (VisitRequest $v) => [
                'visit' => $v,
                'gate' => $this->gateStatus($v),
                'sessions' => $this->sessionsLabel($v),
            ])
            ->values();

        $upcomingVisits = $visits
            ->filter(fn (VisitRequest $v) => $v->schedule?->schedule_date?->isAfter(today())
                && in_array($v->status, self::UPCOMING_STATUSES, true))
            ->map(fn (VisitRequest $v) => [
                'visit' => $v,
                'sessions' => $this->sessionsLabel($v),
            ])
            ->values();

        $gateHistory = VisitCheckin::whereHas('visitRequest', fn ($q) => $q->where('visitor_id', $visitor->visitor_id))
            ->with(['visitRequest.pdl', 'checkInOfficer', 'checkOutOfficer'])
            ->orderByDesc('check_in_time')
            ->take(15)
            ->get();

        $stats = [
            'totalVisits' => $visits->whereIn('status', ['completed'])->count(),
            'gateEntries' => VisitCheckin::whereHas('visitRequest', fn ($q) => $q->where('visitor_id', $visitor->visitor_id))->count(),
            'noShows' => $visits->where('status', 'no_show')->count(),
            'lastVisit' => $gateHistory->first()?->check_in_time,
        ];

        $age = $visitor->date_of_birth ? Carbon::parse($visitor->date_of_birth)->age : null;

        // Back to the same search results.
        $backQuery = Sanitizer::text((string) $request->input('q', ''));

        return view('frontdesk.visitor-profile', compact(
            'visitor', 'todayVisits', 'upcomingVisits', 'gateHistory', 'stats', 'age', 'backQuery'
        ));
    }

    /** The uploaded ID picture, so the officer can compare it with the visitor at the gate. */
    public function idPhoto(VisitorProfile $visitor, VisitorId $idDocument)
    {
        abort_unless($idDocument->visitor_id === $visitor->visitor_id, 404);
        $disk = $idDocument->fileDisk();
        abort_unless($disk !== null, 404);

        return Storage::disk($disk)->response($idDocument->file_path);
    }

    /** Where the visitor is right now for this visit: label + chip status. */
    private function gateStatus(VisitRequest $visit): array
    {
        $active = $visit->checkins->firstWhere('status', 'checked_in');
        if ($active) {
            return ['label' => 'Inside since '.Carbon::parse($active->check_in_time)->format('H:i'), 'status' => 'checked_in'];
        }

        $last = $visit->checkins->sortByDesc('check_out_time')->first();
        if ($last?->check_out_time) {
            return ['label' => 'Left at '.Carbon::parse($last->check_out_time)->format('H:i'), 'status' => 'checked_out'];
        }

        return match ($visit->status) {
            'confirmed' => ['label' => 'Expected, not yet arrived', 'status' => 'awaiting_arrival'],
            'pending_confirmation' => ['label' => 'Visitor has not confirmed yet', 'status' => 'pending_confirmation'],
            'assigned' => ['label' => 'Awaiting Records Officer review', 'status' => 'assigned'],
            default => ['label' => ucwords(str_replace('_', ' ', $visit->status)), 'status' => $visit->status],
        };
    }

    /** "09:00 – 11:30" (or both sessions of a whole-day visit). */
    private function sessionsLabel(VisitRequest $visit): string
    {
        $sessions = $visit->sessionSchedules->isNotEmpty()
            ? $visit->sessionSchedules
            : collect([$visit->schedule])->filter();

        return $sessions
            ->map(fn ($s) => Carbon::parse($s->time_slot_start)->format('H:i').' – '.Carbon::parse($s->time_slot_end)->format('H:i'))
            ->implode(' · ') ?: '—';
    }
}
