<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Concerns\ResolvesActingStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\WalkInRegistrationRequest;
use App\Models\Pdl;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\WalkInAssessment;
use App\Services\RelationshipRequirements;
use App\Services\WalkInService;
use App\Support\Sanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Walk-in visitors (no phone, or can't use the app): the Front Desk registers
 * them and follows the Record Officer's approval and walk-in assessment;
 * the approval sets up today's visit, so the desk only checks them in.
 * See WalkInService.
 */
class WalkInController extends Controller
{
    use ResolvesActingStaff;

    public function __construct(private readonly WalkInService $walkIns) {}

    private const PER_PAGE = 25;

    /** Status tabs: filter => label, hint, accent, icon and the WalkInService::status() keys it groups. */
    private const CARDS = [
        'pending' => ['Pending Approval', 'Awaiting Records review', 'warning', 'clock', ['waiting', 'linking', 'assess']],
        'approved' => ['Approved', 'Approved, not checked in yet', 'success', 'check-circle', ['booked', 'ready']],
        'rejected' => ['Rejected', 'Rejected by Records', 'danger', 'x', ['returned', 'declined']],
    ];

    /**
     * Everyone registered as a walk-in, newest first. A status tab, the
     * search (visitor, or the PDL name / number given) and the registration
     * day narrow the list; the tab counts follow the search and day.
     */
    public function index(Request $request): View
    {
        $filter = array_key_exists((string) $request->query('status'), self::CARDS) ? (string) $request->query('status') : null;
        $query = mb_substr(Sanitizer::text((string) $request->query('q', '')) ?? '', 0, 100);
        $date = $this->parseDay((string) $request->query('date', ''));

        $all = VisitorProfile::where('registered_via', 'walk_in')
            ->when($date, fn ($q) => $q->whereDate('created_at', $date))
            ->when($query !== '', function ($q) use ($query) {
                $like = Sanitizer::like($query);
                $q->where(fn ($match) => $match->where('full_name', 'like', $like)
                    ->orWhere('intended_pdl_name', 'like', $like)
                    ->orWhere('intended_pdl_number', 'like', $like));
            })
            ->with('account')
            ->latest()
            ->latest('visitor_id')
            ->get()
            ->map(fn (VisitorProfile $visitor) => ['visitor' => $visitor] + $this->walkIns->status($visitor));

        // Tabs keep the search and day; "All" includes checked-in and checked-out walk-ins.
        $keep = array_filter(['q' => $query, 'date' => $date?->format('Y-m-d')]);
        $tabs = [['label' => 'All', 'count' => $all->count(), 'accent' => 'neutral', 'href' => route('frontdesk.walk-ins.index', $keep), 'active' => $filter === null]];
        foreach (self::CARDS as $key => [$label, , $accent, , $keys]) {
            $tabs[] = [
                'label' => $label,
                'count' => $all->whereIn('key', $keys)->count(),
                'accent' => $accent,
                'href' => route('frontdesk.walk-ins.index', ['status' => $key] + $keep),
                'active' => $filter === $key,
            ];
        }

        $rows = $filter ? $all->whereIn('key', self::CARDS[$filter][4])->values() : $all;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $walkIns = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('frontdesk.walk-ins.index', [
            'walkIns' => $walkIns,
            'tabs' => $tabs,
            'filter' => $filter,
            'query' => $query,
            'date' => $date,
        ]);
    }

    /** A real Y-m-d day, or null (no day filter). */
    private function parseDay(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $day = Carbon::createFromFormat('!Y-m-d', $value);

            return $day && $day->format('Y-m-d') === $value ? $day : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The PDL with this exact PDL number (as on the Records PDL profile), for
     * the walk-in form's live check. Only the name and number are returned.
     */
    public function pdlLookup(Request $request): JsonResponse
    {
        $number = mb_strtoupper(trim((string) $request->query('number', '')));
        $pdl = $number === '' ? null : Pdl::whereRaw('UPPER(pdl_number) = ?', [$number])->first(['pdl_number', 'full_name']);

        return response()->json($pdl
            ? ['found' => true, 'pdlNumber' => $pdl->pdl_number, 'fullName' => $pdl->full_name]
            : ['found' => false]);
    }

    public function create(): View
    {
        return view('frontdesk.walk-ins.create', [
            'relationshipTypes' => collect(RelationshipRequirements::registrationTypes())
                ->mapWithKeys(fn ($type) => [$type => [
                    'label' => VisitorPdlRelationship::TYPE_LABELS[$type],
                    'description' => RelationshipRequirements::RELATIONSHIP_RULES[$type]['description'],
                    'documents' => RelationshipRequirements::RELATIONSHIP_RULES[$type]['documents'],
                    'childrenTogether' => RelationshipRequirements::RELATIONSHIP_RULES[$type]['children_together'] ?? null,
                ]]),
            'documents' => RelationshipRequirements::DOCUMENTS,
            'minorDocument' => RelationshipRequirements::MINOR_DOCUMENT,
            'idTypes' => VisitorId::TYPE_LABELS,
            'purposes' => WalkInAssessment::PURPOSES,
        ]);
    }

    public function store(WalkInRegistrationRequest $request): RedirectResponse
    {
        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->withInput()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $documents = collect($request->requiredDocumentKeys())
            ->mapWithKeys(fn ($key) => [$key => $request->file("documents.{$key}")])
            ->all();

        $visitor = $this->walkIns->register(
            $request->safe()->except(['photo', 'government_id', 'documents', 'consent', 'not_duplicate']),
            $request->file('photo'),
            $request->file('government_id'),
            $documents,
            $staffId,
        );

        return redirect()->route('frontdesk.walk-ins.show', $visitor)
            ->with('status', "{$visitor->full_name} is registered and waiting for the Records Officer's approval.");
    }

    public function show(VisitorProfile $visitor): View
    {
        $status = $this->walkIns->status($visitor);

        return view('frontdesk.walk-ins.show', [
            'visitor' => $visitor->load('account', 'idDocuments'),
            'status' => $status,
            'assessment' => $this->walkIns->assessment($visitor),
            'checks' => $this->walkIns->checks($visitor),
        ]);
    }


}
