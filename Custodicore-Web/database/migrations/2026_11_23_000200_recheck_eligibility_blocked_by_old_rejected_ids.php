<?php

use App\Models\EligibilityAssessment;
use App\Models\VisitRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * One-time data fix. The identity check used to fail a visit request when
 * the visitor had ANY rejected ID on file, even an old one replaced by an
 * approved ID. It now looks at the current ID only
 * (EligibilityAssessment::assessFor). This re-runs the check for the visit
 * requests still waiting for review that were blocked by it, so they can be
 * approved. Nothing else changes; re-running it is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        $visits = VisitRequest::awaitingReview()
            ->whereHas('eligibilityAssessment', fn ($q) => $q->where('identity_check_result', 'rejected'))
            ->get();

        foreach ($visits as $visit) {
            try {
                EligibilityAssessment::assessFor($visit);
            } catch (\Throwable $e) {
                Log::warning("Eligibility re-check failed for visit request #{$visit->visit_request_id}: " . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: the checks simply reflect the current IDs.
    }
};
