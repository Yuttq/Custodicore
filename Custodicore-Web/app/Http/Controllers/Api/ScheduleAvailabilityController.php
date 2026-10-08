<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VisitorPdlRelationship;
use App\Services\ScheduleAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/schedules/availability — read-only visit-slot availability for
 * the approved visitor's own verified PDL relationships (mobile Home
 * calendar). Selecting a slot here books nothing; the visit request itself
 * is POST /api/visit-requests. See ScheduleAvailabilityService for the slot rules.
 */
class ScheduleAvailabilityController extends Controller
{
    public function __construct(private ScheduleAvailabilityService $availability)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'relationshipId' => ['nullable', 'integer', 'min:1'],
        ]);

        $visitor = $request->user()?->visitorProfile;
        abort_unless($visitor, 403, 'This account has no visitor profile.');

        $tz = $this->availability->timezone();
        $today = $this->availability->now()->startOfDay();

        $from = $request->filled('from')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->input('from'), $tz)->startOfDay()
            : $today;
        $to = $request->filled('to')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->input('to'), $tz)->startOfDay()
            : $from->addDays((int) config('visitation.availability_days', 30));

        $maxRange = (int) config('visitation.max_range_days', 62);
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }
        if ($from->diffInDays($to) > $maxRange) {
            throw ValidationException::withMessages(['to' => "Please request at most {$maxRange} days at a time."]);
        }

        if ($request->filled('relationshipId')) {
            // Only the visitor's own relationship — anyone else's is "not found".
            $relationship = VisitorPdlRelationship::where('relationship_id', $request->integer('relationshipId'))
                ->where('visitor_id', $visitor->visitor_id)
                ->first();
            abort_unless($relationship, 404, 'Relationship not found.');

            if (! $relationship->isVerified()) {
                throw ValidationException::withMessages([
                    'relationshipId' => 'This relationship has not been verified yet. Availability is shown only for verified PDL relationships.',
                ]);
            }
        }

        $relationships = $this->availability->verifiedRelationships($visitor);
        if (isset($relationship)) {
            $relationships = $relationships->where('relationship_id', $relationship->relationship_id)->values();
        }

        $results = $this->availability->forRelationships($visitor, $relationships, $from, $to);

        return response()->json([
            'timezone' => $tz,
            'today' => $today->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'message' => $results === []
                ? 'A verified PDL relationship is required to view visit availability. Your relationship to the PDL must be verified by facility staff first.'
                : null,
            'relationships' => $results,
        ]);
    }
}
