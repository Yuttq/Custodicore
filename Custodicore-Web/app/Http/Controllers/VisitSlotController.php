<?php

namespace App\Http\Controllers;

use App\Models\FacilityVisitationRule;
use App\Models\VisitSchedule;
use App\Models\VisitSession;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use App\Services\VisitSlotService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Record Officer — Manage Visitor Slots: create dated visiting sessions, set
 * their capacity, open/close them to new bookings, see their bookings, and
 * set each weekly session's default capacity. The rules live in
 * VisitSlotService; this controller only validates input shape.
 */
class VisitSlotController extends Controller
{
    /** Visit statuses as staff read them on the bookings list. */
    public const STATUS_LABELS = [
        'assigned' => 'Awaiting review',
        'pending_confirmation' => 'Awaiting visitor confirmation',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'no_show' => 'No-show',
        'declined' => 'Declined by visitor',
        'cancelled' => 'Cancelled',
    ];

    private const DAY_ORDER = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(
        private VisitSlotService $slots,
        private ScheduleAvailabilityService $availability
    ) {
    }

    public function index(Request $request): View
    {
        $today = $this->availability->now()->toDateString();
        $from = $this->dateFilter($request->query('from')) ?? $today;
        $to = $this->dateFilter($request->query('to'));
        $classification = in_array($request->query('classification'), ['drug_related', 'non_drug_related'], true)
            ? $request->query('classification') : '';
        $status = in_array($request->query('status'), ['open', 'full', 'closed'], true)
            ? $request->query('status') : '';

        $schedules = VisitSchedule::query()
            ->with('rule')
            ->whereDate('schedule_date', '>=', $from)
            ->when($to, fn ($q) => $q->whereDate('schedule_date', '<=', $to))
            ->when($classification, fn ($q) => $q->whereHas('rule', fn ($r) => $r->where('pdl_classification', $classification)))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('schedule_date')
            ->orderBy('time_slot_start')
            ->get();

        $summaries = $this->slots->summaries($schedules);
        $editable = $schedules->mapWithKeys(fn (VisitSchedule $s) => [$s->schedule_id => $this->slots->isEditable($s)])->all();

        $stats = [
            'sessions' => $schedules->count(),
            'capacity' => array_sum(array_column($summaries, 'capacity')),
            'booked' => array_sum(array_column($summaries, 'booked')),
            'remaining' => array_sum(array_column($summaries, 'remaining')),
        ];

        $rules = FacilityVisitationRule::query()
            ->get()
            ->sortBy(fn (FacilityVisitationRule $r) => [
                $r->pdl_classification,
                array_search($r->day_of_week, self::DAY_ORDER, true),
                (string) $r->time_slot_start,
            ])
            ->values();

        $filters = compact('from', 'to', 'classification', 'status');

        return view('visitor-slots.index', compact('schedules', 'summaries', 'editable', 'stats', 'rules', 'filters', 'today'));
    }

    public function show(VisitSchedule $visitSchedule): View
    {
        $visitSchedule->load('rule');
        $summary = $this->slots->summaries(collect([$visitSchedule]))[$visitSchedule->schedule_id];
        $editable = $this->slots->isEditable($visitSchedule);

        $bookings = VisitSession::query()
            ->where('schedule_id', $visitSchedule->schedule_id)
            ->with(['visitRequest.visitor', 'visitRequest.pdl', 'visitRequest.sessionSchedules'])
            ->get()
            ->pluck('visitRequest')
            ->filter()
            ->sortBy(fn ($visit) => [
                in_array($visit->status, VisitAssignmentService::CAPACITY_STATUSES, true) ? 0 : 1,
                $visit->visit_request_id,
            ])
            ->values();

        return view('visitor-slots.show', [
            'schedule' => $visitSchedule,
            'summary' => $summary,
            'editable' => $editable,
            'bookings' => $bookings,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'schedule_date' => ['required', 'date_format:Y-m-d'],
            'pdl_classification' => ['required', Rule::in(['drug_related', 'non_drug_related'])],
            'session' => ['required', Rule::in(VisitSlotService::SESSIONS)],
            'max_capacity' => $this->capacityRules(),
            'is_open' => ['nullable', 'boolean'],
        ], $this->messages());

        try {
            $schedule = $this->slots->create(
                $validated['pdl_classification'],
                CarbonImmutable::createFromFormat('Y-m-d', $validated['schedule_date'], $this->availability->timezone())->startOfDay(),
                $validated['session'],
                (int) $validated['max_capacity'],
                $request->boolean('is_open')
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('visitor-slots.show', $schedule->schedule_id)
            ->with('success', "Visiting session created with {$schedule->max_capacity} slots.");
    }

    public function update(Request $request, VisitSchedule $visitSchedule): RedirectResponse
    {
        $validated = $request->validate([
            'max_capacity' => $this->capacityRules(),
            'is_open' => ['nullable', 'boolean'],
        ], $this->messages());

        try {
            $schedule = $this->slots->update($visitSchedule, (int) $validated['max_capacity'], $request->boolean('is_open'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('visitor-slots.show', $schedule->schedule_id)
            ->with('success', "Session updated: capacity {$schedule->max_capacity}, "
                .($schedule->status === 'closed' ? 'closed to new bookings.' : 'open to bookings'.($schedule->status === 'full' ? ' (currently full).' : '.')));
    }

    public function updateStatus(Request $request, VisitSchedule $visitSchedule): RedirectResponse
    {
        $validated = $request->validate([
            'is_open' => ['required', 'boolean'],
        ]);

        try {
            $schedule = $this->slots->setOpen($visitSchedule, (bool) $validated['is_open']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', $schedule->status === 'closed'
            ? 'Session closed. Existing bookings are kept; no new bookings will be accepted.'
            : 'Session reopened to new bookings.');
    }

    public function updateRule(Request $request, FacilityVisitationRule $rule): RedirectResponse
    {
        $validated = $request->validate([
            'max_capacity' => $this->capacityRules(),
        ], $this->messages());

        try {
            $rule = $this->slots->updateRuleCapacity($rule, (int) $validated['max_capacity']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('visitor-slots.index')
            ->with('success', 'Default capacity for '.VisitSlotService::ruleLabel($rule)." set to {$rule->max_capacity}. "
                .'Sessions that already exist keep their own capacity.');
    }

    private function capacityRules(): array
    {
        return ['required', 'integer', 'min:'.VisitSlotService::MIN_CAPACITY, 'max:'.VisitSlotService::MAX_CAPACITY];
    }

    private function messages(): array
    {
        return [
            'max_capacity.required' => 'Enter the number of visitor slots.',
            'max_capacity.integer' => 'Capacity must be a whole number.',
            'max_capacity.min' => 'Capacity must be at least :min. To stop new bookings, close the session instead.',
            'max_capacity.max' => 'Capacity cannot be more than :max.',
            'schedule_date.date_format' => 'Enter the visiting date as YYYY-MM-DD.',
        ];
    }

    private function dateFilter(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
