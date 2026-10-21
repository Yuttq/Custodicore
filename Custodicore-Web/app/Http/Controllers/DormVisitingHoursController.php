<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CellBlock;
use App\Models\FacilityVisitationRule;
use App\Models\Module;
use App\Services\VisitSlotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A dorm's own visiting schedule (weekly days + hours + visitors per
 * session). Stored as facility_visitation_rules rows with cell_block_id, so
 * the existing booking engine (slots, capacity, weekly limit) uses them as-is.
 * While a dorm has hours in effect, its PDLs follow them instead of the
 * classification days.
 */
class DormVisitingHoursController extends Controller
{
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/'; // 24-hour HH:MM

    public function index(CellBlock $cellBlock)
    {
        $rules = $this->activeRules($cellBlock);
        $pdlCount = $cellBlock->pdls()->where('custody_status', CellBlock::OCCUPYING_STATUS)->count();

        return view('cell-blocks.visiting-hours', compact('cellBlock', 'rules', 'pdlCount'));
    }

    public function store(Request $request, CellBlock $cellBlock)
    {
        $data = $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*' => [Rule::in(array_keys(FacilityVisitationRule::DAY_LABELS))],
            'start' => ['required', 'regex:' . self::TIME],
            'end' => ['required', 'regex:' . self::TIME],
            'max_capacity' => ['required', 'integer', 'min:' . VisitSlotService::MIN_CAPACITY, 'max:' . VisitSlotService::MAX_CAPACITY],
        ], [
            'days.required' => 'Choose at least one visiting day.',
            'start.regex' => 'Use 24-hour time for the start, e.g. 08:00 or 13:30.',
            'end.regex' => 'Use 24-hour time for the end, e.g. 11:30 or 16:30.',
        ]);

        if ($data['end'] <= $data['start']) {
            return back()->withInput()->withErrors(['end' => 'The end time must be after the start time.']);
        }

        $existing = $this->activeRules($cellBlock);
        $session = VisitSlotService::sessionOf($data['start']);
        foreach (array_unique($data['days']) as $day) {
            foreach ($existing->where('day_of_week', $day) as $rule) {
                $label = FacilityVisitationRule::DAY_LABELS[$day];
                if ($data['start'] < substr((string) $rule->time_slot_end, 0, 5) && $data['end'] > substr((string) $rule->time_slot_start, 0, 5)) {
                    return back()->withInput()->withErrors(['start' => "{$label} already has visiting hours {$rule->hoursLabel()} that overlap these."]);
                }
                // The booking engine allows one morning and one afternoon session per day.
                if (VisitSlotService::sessionOf((string) $rule->time_slot_start) === $session) {
                    return back()->withInput()->withErrors(['start' => "{$label} already has a {$session} session ({$rule->hoursLabel()}). Each day can have one morning and one afternoon session."]);
                }
            }
        }

        DB::transaction(function () use ($cellBlock, $data) {
            foreach (array_unique($data['days']) as $day) {
                FacilityVisitationRule::create([
                    'cell_block_id' => $cellBlock->cell_block_id,
                    'pdl_classification' => null,
                    'day_of_week' => $day,
                    'time_slot_start' => $data['start'] . ':00',
                    'time_slot_end' => $data['end'] . ':00',
                    'max_capacity' => (int) $data['max_capacity'],
                    'effective_from' => now()->toDateString(),
                ]);
            }
        });

        $dayNames = collect($data['days'])->unique()->map(fn ($d) => FacilityVisitationRule::DAY_LABELS[$d])->join(', ');
        $this->audit($cellBlock, "Added visiting hours for {$cellBlock->name}: {$dayNames} {$data['start']}–{$data['end']} ({$data['max_capacity']} visitors per session)");

        return redirect()->route('cell-blocks.visiting-hours', $cellBlock->cell_block_id)
            ->with('success', "Visiting hours added for {$cellBlock->name}.");
    }

    public function destroy(CellBlock $cellBlock, FacilityVisitationRule $rule)
    {
        abort_unless((int) $rule->cell_block_id === (int) $cellBlock->cell_block_id, 404);

        // Don't strand visits that are already booked in these hours.
        // Counted through visit_sessions: a whole-day visit links its later
        // sessions there, not through visit_requests.schedule_id.
        $booked = DB::table('visit_sessions as vs')
            ->join('visit_schedules as s', 's.schedule_id', '=', 'vs.schedule_id')
            ->join('visit_requests as vr', 'vr.visit_request_id', '=', 'vs.visit_request_id')
            ->where('s.rule_id', $rule->rule_id)
            ->whereDate('s.schedule_date', '>=', now()->toDateString())
            ->whereIn('vr.status', ['assigned', 'pending_confirmation', 'confirmed'])
            ->distinct()
            ->count('s.schedule_id');
        if ($booked > 0) {
            return back()->with('error', "These hours have {$booked} upcoming visiting " . \Illuminate\Support\Str::plural('session', $booked)
                . ' with booked visits. Reject or cancel those visits first, or close the sessions in Manage Visitor Slots.');
        }

        $label = FacilityVisitationRule::DAY_LABELS[$rule->day_of_week] . ' ' . $rule->hoursLabel();

        // Hours already used by past visits are retired (kept for history), others deleted.
        if ($rule->schedules()->exists()) {
            $rule->update(['effective_to' => now()->subDay()->toDateString()]);
        } else {
            $rule->delete();
        }

        $this->audit($cellBlock, "Removed visiting hours for {$cellBlock->name}: {$label}");

        return back()->with('success', "Removed {$label} from {$cellBlock->name}'s visiting hours.");
    }

    private function activeRules(CellBlock $cellBlock)
    {
        $order = array_keys(FacilityVisitationRule::DAY_LABELS);

        return $cellBlock->visitationRules()
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))
            ->get()
            ->sortBy(fn ($r) => [array_search($r->day_of_week, $order, true), (string) $r->time_slot_start])
            ->values();
    }

    private function audit(CellBlock $cellBlock, string $description): void
    {
        try {
            AuditLog::record('update', 'cell_blocks', $cellBlock->cell_block_id, $description, Module::CODE_VISIT_SCHEDULING);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Audit log write failed: ' . $e->getMessage());
        }
    }
}
