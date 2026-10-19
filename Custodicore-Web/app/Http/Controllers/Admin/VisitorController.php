<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VisitorProfile;
use App\Support\Sanitizer;
use Illuminate\Http\Request;
use App\Support\Pages;
use Illuminate\View\View;

/**
 * Module 1.3 Visitor Management — read-only.
 *
 * Visitors register and get verified through the mobile app (and the
 * Records Officer module's verification actions). The Warden can search the
 * list and open a visitor to VIEW their profile, their relationship to each
 * PDL, their visit history and their flags. Nothing here writes anything.
 */
class VisitorController extends Controller
{
    public function index(Request $request): View
    {
        $search = Sanitizer::text($request->query('q'));

        $visitors = self::rows($search);

        $verified = VisitorProfile::where('verification_status', 'verified')->count();
        $pending = VisitorProfile::where('verification_status', 'pending')->count();
        $total = VisitorProfile::count();

        $summary = [
            ['label' => 'Total Visitors', 'value' => (string) $total, 'icon' => 'visitor', 'accent' => 'info'],
            ['label' => 'Verified', 'value' => (string) $verified, 'icon' => 'check-circle', 'accent' => 'success'],
            ['label' => 'Pending Verification', 'value' => (string) $pending, 'icon' => 'clock', 'accent' => 'warning'],
        ];

        $visitors = Pages::of($visitors);

        return view('admin.visitors.index', [
            'visitors' => $visitors,
            'summary' => $summary,
            'search' => $search,
            'totalVisitors' => $total,
        ]);
    }

    /** View one visitor: profile, relationship to each PDL, visit history, flags. */
    public function show(VisitorProfile $visitor): View
    {
        $visitor->load([
            'account',
            'relationships' => fn ($q) => $q->orderByDesc('priority_tier')->with('pdl'),
            'flags' => fn ($q) => $q->orderByDesc('created_at')->with(['flaggedBy', 'resolvedBy']),
            'visitRequests' => fn ($q) => $q->with(['pdl', 'schedule', 'sessionSchedules'])->orderByDesc('visit_request_id')->limit(50),
        ]);

        return view('admin.visitors.show', [
            'visitor' => $visitor,
            'activeFlags' => $visitor->flags->filter->isActive()->count(),
        ]);
    }

    /**
     * Names of verified visitors — kept as a static helper since
     * Admin\CheckinController's view historically referenced it, though
     * check-in/out is now fully read-only and driven by real gate scans.
     */
    public static function verifiedNames(): array
    {
        return VisitorProfile::where('verification_status', 'verified')->pluck('full_name')->all();
    }

    private static function rows(?string $search): array
    {
        $like = $search !== null ? Sanitizer::like($search) : null;

        // Also match a contact number typed without the spaces / dashes it was saved with.
        $digits = $search !== null ? preg_replace('/\D+/', '', $search) : '';
        $likeDigits = $digits !== '' ? '%' . $digits . '%' : null;

        return VisitorProfile::query()
            ->with(['relationships' => fn ($q) => $q->orderByDesc('priority_tier')->with('pdl')])
            ->when($like, function ($query) use ($like, $likeDigits) {
                $query->where(function ($q) use ($like, $likeDigits) {
                    $q->where('full_name', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('contact_number', 'like', $like)
                        ->orWhereHas('account', function ($a) use ($like) {
                            $a->where('email', 'like', $like);
                        })
                        ->orWhereHas('relationships', function ($r) use ($like) {
                            $r->whereHas('pdl', function ($p) use ($like) {
                                $p->where(function ($w) use ($like) {
                                    $w->where('full_name', 'like', $like)->orWhere('pdl_number', 'like', $like);
                                });
                            });
                        });

                    if ($likeDigits !== null) {
                        $q->orWhereRaw("REPLACE(REPLACE(REPLACE(contact_number, ' ', ''), '-', ''), '+', '') like ?", [$likeDigits]);
                    }
                });
            })
            ->orderBy('full_name')
            ->get()
            ->map(function ($visitor) {
                $relationship = $visitor->relationships->first();

                return [
                    'id' => $visitor->visitor_id,
                    'full_name' => $visitor->full_name,
                    'contact_number' => $visitor->contact_number,
                    'related_pdl_name' => $relationship?->pdl?->full_name ?? '—',
                    'related_pdl_number' => $relationship?->pdl?->pdl_number ?? '—',
                    'more_pdls' => max(0, $visitor->relationships->count() - 1),
                    'relationship_type' => $relationship?->relationship_type ?? 'unknown_or_other',
                    'verification_status' => $visitor->verification_status,
                ];
            })
            ->all();
    }
}
