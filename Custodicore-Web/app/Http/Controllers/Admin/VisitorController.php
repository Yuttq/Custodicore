<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\VisitorProfile;
use App\Support\Sanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Support\Pages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Module 1.3 Visitor Management.
 *
 * Visitors register and get verified through the mobile app (and the
 * Records Officer module's verification actions). The Warden can search the
 * list and open a visitor to view their profile, their relationship to each
 * PDL, their visit history and their flags.
 *
 * The Warden's only write action here is switching the visitor's ACCOUNT on
 * or off (accounts.status). That is separate from verification
 * (visitor_profiles.verification_status), which this never changes: a
 * verified visitor who is deactivated and later reactivated is still verified.
 */
class VisitorController extends Controller
{
    public function index(Request $request): View
    {
        $search = Sanitizer::text($request->query('q'));

        // Clicking a summary card filters the list: ?filter=verified|pending.
        $filter = $request->query('filter');
        $filter = in_array($filter, ['verified', 'pending'], true) ? $filter : null;

        $visitors = self::rows($search, $filter);

        // The cards always count ALL visitors, whatever the search or filter.
        $verified = VisitorProfile::where('verification_status', 'verified')->count();
        $pending = VisitorProfile::where('verification_status', 'pending')->count();
        $total = VisitorProfile::count();

        $summary = [
            ['label' => 'Total Visitors', 'value' => (string) $total, 'icon' => 'visitor', 'accent' => 'info',
                'href' => route('admin.visitors.index'), 'active' => $filter === null && $search === null],
            ['label' => 'Verified', 'value' => (string) $verified, 'icon' => 'check-circle', 'accent' => 'success',
                'href' => route('admin.visitors.index', ['filter' => 'verified']), 'active' => $filter === 'verified'],
            ['label' => 'Pending Verification', 'value' => (string) $pending, 'icon' => 'clock', 'accent' => 'warning',
                'href' => route('admin.visitors.index', ['filter' => 'pending']), 'active' => $filter === 'pending'],
        ];

        $filterLabel = ['verified' => 'Verified visitors', 'pending' => 'Visitors pending verification'][$filter] ?? null;

        $visitors = Pages::of($visitors);

        return view('admin.visitors.index', [
            'visitors' => $visitors,
            'summary' => $summary,
            'search' => $search,
            'filter' => $filter,
            'filterLabel' => $filterLabel,
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
            'upcomingVisits' => $this->upcomingVisitCount($visitor),
        ]);
    }

    /**
     * PATCH admin/visitors/{visitor}/account-status  (action=deactivate|reactivate)
     *
     * Behind `reauth`: the Warden re-enters their own password. Deactivating
     * needs a reason, signs the visitor out of the app everywhere and is
     * refused at the gate (Front Desk). Visits are not cancelled.
     */
    public function setAccountStatus(Request $request, VisitorProfile $visitor): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:deactivate,reactivate'],
            'reason' => ['nullable', 'required_if:action,deactivate', 'string', 'max:200'],
        ], [
            'reason.required_if' => 'Give a reason for deactivating this account.',
        ]);

        $back = redirect()->route('admin.visitors.show', $visitor->visitor_id);

        if (! $visitor->account_id) {
            return $back->with('status', 'This visitor has no account to switch on or off.');
        }

        $target = $data['action'] === 'deactivate' ? 'inactive' : 'active';
        $reason = Sanitizer::text($data['reason'] ?? null);

        // Row lock, like Admin → Users: two Wardens acting at once are applied
        // in turn, and the sign-out always matches the status that was saved.
        $changed = DB::transaction(function () use ($visitor, $target) {
            $locked = Account::whereKey($visitor->account_id)
                ->whereHas('role', fn ($q) => $q->where('role_name', 'Visitor'))
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === $target) {
                return false;
            }

            $locked->status = $target;
            $locked->save();

            if ($target !== 'active') {
                $locked->signOutEverywhere();
            }

            return true;
        });

        if (! $changed) {
            return $back->with('status', $target === 'active'
                ? "{$visitor->full_name}'s account is already active."
                : "{$visitor->full_name}'s account is already deactivated.");
        }

        AuditLog::record(
            'update',
            'accounts',
            (int) $visitor->account_id,
            Str::limit($target === 'active'
                ? "Reactivated visitor {$visitor->full_name}'s account (password re-confirmed)"
                : "Deactivated visitor {$visitor->full_name}'s account (password re-confirmed). Reason: {$reason}", 250),
            Module::CODE_VISITOR_MANAGEMENT
        );

        if ($target === 'active') {
            return $back->with('status', "{$visitor->full_name}'s account is active again. They can sign in to the app; their verification is unchanged.");
        }

        $upcoming = $this->upcomingVisitCount($visitor);
        $note = $upcoming > 0
            ? " {$upcoming} upcoming " . Str::plural('visit', $upcoming) . ' stay booked (not cancelled); the Front Desk will refuse check-in while the account is inactive.'
            : '';

        return $back->with('status', "{$visitor->full_name}'s account is deactivated and signed out of the app.{$note}");
    }

    /** Pending or confirmed visits from today on (shown before/after deactivating). */
    private function upcomingVisitCount(VisitorProfile $visitor): int
    {
        return $visitor->visitRequests()
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', '>=', now()->toDateString()))
            ->count();
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

    private static function rows(?string $search, ?string $filter = null): array
    {
        $like = $search !== null ? Sanitizer::like($search) : null;

        // Also match a contact number typed without the spaces / dashes it was saved with.
        $digits = $search !== null ? preg_replace('/\D+/', '', $search) : '';
        $likeDigits = $digits !== '' ? '%' . $digits . '%' : null;

        return VisitorProfile::query()
            ->with(['account:account_id,status', 'relationships' => fn ($q) => $q->orderByDesc('priority_tier')->with('pdl')])
            ->when($filter, fn ($query) => $query->where('verification_status', $filter))
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
                    'photo' => $visitor->photoUrl(),
                    'contact_number' => $visitor->contact_number,
                    'related_pdl_name' => $relationship?->pdl?->full_name ?? '—',
                    'related_pdl_number' => $relationship?->pdl?->pdl_number ?? '—',
                    'more_pdls' => max(0, $visitor->relationships->count() - 1),
                    'relationship_type' => $relationship?->relationship_type ?? 'unknown_or_other',
                    'verification_status' => $visitor->verification_status,
                    'account_status' => $visitor->account?->status,
                ];
            })
            ->all();
    }
}
