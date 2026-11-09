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
 * them (or finds them), selects the PDL and a session Records opened, and
 * follows the Record Officer's decision; approving books that session, so
 * the desk only checks them in and out. See WalkInService.
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
     * Phase 1: PDLs for the walk-in form's search. Every word typed must be
     * in the name, alias or PDL number, so partial names work. Only what the
     * desk needs to pick the right person is returned.
     */
    public function pdlSearch(Request $request): JsonResponse
    {
        $words = collect(preg_split('/\s+/u', mb_strtolower(mb_substr(Sanitizer::text((string) $request->query('q', '')) ?? '', 0, 100))))
            ->filter(fn ($word) => mb_strlen($word) >= 2)
            ->take(5)
            ->values();
        if ($words->isEmpty()) {
            return response()->json(['results' => []]);
        }

        $pdls = Pdl::query()
            ->with('activeRestrictions')
            ->where(function ($q) use ($words) {
                $words->each(fn ($word) => $q->where(function ($match) use ($word) {
                    $like = '%'.addcslashes($word, '%_\\').'%';
                    $match->whereRaw('LOWER(full_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(alias) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(pdl_number) LIKE ?', [$like]);
                }));
            })
            ->orderBy('full_name')
            ->limit(10)
            ->get();

        return response()->json(['results' => $pdls->map(fn (Pdl $pdl) => [
            'id' => $pdl->pdl_id,
            'name' => $pdl->full_name,
            'alias' => $pdl->alias,
            'number' => $pdl->pdl_number,
            'status' => $pdl->custody_status,
            'visitable' => $pdl->custody_status === 'active' && $pdl->activeRestrictions->isEmpty(),
        ])->values()]);
    }

    /**
     * Phase 2: the dates and sessions Records opened for a PDL, with seats
     * left and why a session cannot be booked. With `visitor` (a registered
     * walk-in), that visitor's booking rules apply too.
     */
    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pdl_id' => ['required', 'integer'],
            'visitor' => ['nullable', 'integer'],
            'relationship_type' => ['nullable', 'string', 'max:50'],
        ]);
        $pdl = Pdl::findOrFail($data['pdl_id']);
        $visitor = isset($data['visitor'])
            ? VisitorProfile::where('registered_via', 'walk_in')->findOrFail($data['visitor'])
            : null;

        return response()->json($this->walkIns->openSlots($pdl, $visitor, $data['relationship_type'] ?? null));
    }

    /**
     * The registration form, with a search of visitors already on record
     * (`q`): a returning walk-in gets a new arrival instead of a second
     * registration, and an app visitor books in the app.
     */
    public function create(Request $request): View
    {
        $query = mb_substr(Sanitizer::text((string) $request->query('q', '')) ?? '', 0, 100);
        $words = collect(preg_split('/\s+/u', $query))->filter(fn ($word) => mb_strlen($word) >= 2)->take(5);
        $existing = $words->isNotEmpty()
            ? VisitorProfile::query()
                ->where(fn ($q) => $words->each(fn ($word) => $q->where('full_name', 'like', Sanitizer::like($word))))
                ->orderBy('full_name')
                ->limit(10)
                ->get()
                ->map(fn (VisitorProfile $visitor) => [
                    'visitor' => $visitor,
                    'status' => $visitor->isWalkIn() ? $this->walkIns->status($visitor) : null,
                ])
            : collect();

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
            'search' => $query,
            'searched' => $words->isNotEmpty(),
            'existing' => $existing,
            'selectedPdl' => old('pdl_id') ? Pdl::find((int) old('pdl_id')) : null,
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
        $assessment = $this->walkIns->assessment($visitor);
        $canChangeSlot = $assessment && ! $assessment->isDecided() && $assessment->visit_request_id === null;

        return view('frontdesk.walk-ins.show', [
            'visitor' => $visitor->load('account', 'idDocuments'),
            'status' => $status,
            'assessment' => $assessment,
            'checks' => $this->walkIns->checks($visitor),
            'canRecordArrival' => in_array($status['key'], WalkInService::NEW_ARRIVAL_STATUSES, true),
            'canChangeSlot' => $canChangeSlot,
            'slotPdl' => $canChangeSlot ? $this->walkIns->arrivalPdl($visitor, $assessment) : null,
            // PDLs the visitor is approved to visit, for a new arrival.
            'arrivalPdls' => $visitor->relationships()->with('pdl')->get()
                ->filter(fn (VisitorPdlRelationship $relationship) => $relationship->isVerified() && $relationship->pdl)
                ->map(fn (VisitorPdlRelationship $relationship) => $relationship->pdl)
                ->values(),
            'purposes' => WalkInAssessment::PURPOSES,
        ]);
    }

    /**
     * A registered walk-in came back to visit: records the new arrival (its
     * purpose, PDL and the date and session chosen) for the Record Officer's
     * decision, without registering again.
     */
    public function storeArrival(Request $request, VisitorProfile $visitor): RedirectResponse
    {
        abort_unless($visitor->isWalkIn(), 404);

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $request->merge(['visit_purpose' => Sanitizer::text($request->input('visit_purpose'))]);
        $data = $request->validate([
            'visit_purpose' => ['required', 'string', 'max:150'],
            'pdl_id' => ['required', 'integer'],
            ...self::SLOT_RULES,
        ], [
            'visit_purpose.required' => 'Enter the purpose of the visit.',
            'pdl_id.*' => 'Choose the PDL to visit.',
            ...self::SLOT_MESSAGES,
        ]);

        $this->walkIns->newArrival(
            $visitor, $data['visit_purpose'], (int) $data['pdl_id'],
            $data['requested_date'], $data['requested_start_times'], $staffId,
        );

        return redirect()->route('frontdesk.walk-ins.show', $visitor)
            ->with('status', "New arrival recorded. {$visitor->full_name} is waiting for the Records Officer's decision.");
    }

    /** Changes the date and session of an arrival still waiting for the Records decision. */
    public function updateSlot(Request $request, VisitorProfile $visitor): RedirectResponse
    {
        abort_unless($visitor->isWalkIn(), 404);

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $data = $request->validate(self::SLOT_RULES, self::SLOT_MESSAGES);
        $assessment = $this->walkIns->changeRequestedVisit($visitor, $data['requested_date'], $data['requested_start_times'], $staffId);

        return redirect()->route('frontdesk.walk-ins.show', $visitor)
            ->with('status', "Visit set to {$assessment->requestedVisitLabel()}.");
    }

    private const SLOT_RULES = [
        'requested_date' => ['required', 'date_format:Y-m-d'],
        'requested_start_times' => ['required', 'array', 'min:1', 'max:4'],
        'requested_start_times.*' => ['date_format:H:i'],
    ];

    private const SLOT_MESSAGES = [
        'requested_date.*' => 'Choose a visit date from the sessions Records opened.',
        'requested_start_times.*' => 'Choose a session for the visit.',
    ];
}
