<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EligibilityAssessment;
use App\Models\VisitorFlag;
use Illuminate\View\View;

/** Module 1.6 Visitor Eligibility Assessment — read-only view here (the Records Officer module runs/reviews checks). */
class EligibilityController extends Controller
{
    public function index(): View
    {
        $assessments = EligibilityAssessment::query()
            ->with(['visitRequest.visitor', 'visitRequest.pdl'])
            ->orderByDesc('assessed_at')
            ->get()
            ->map(fn ($a) => [
                'visitor' => $a->visitRequest?->visitor?->full_name ?? '—',
                'pdl' => $a->visitRequest?->pdl?->full_name ?? '—',
                'identity_check' => $a->identity_check_result,
                'relationship_check' => $a->relationship_check_result,
                'history_check' => $a->history_check_result,
                'restriction_check' => $a->pdl_restriction_check_result,
                'overall_result' => $a->overall_result,
            ])
            ->all();

        $visitorFlags = VisitorFlag::query()
            ->with('visitor')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($f) => [
                'visitor' => $f->visitor?->full_name ?? '—',
                'flag_type' => $f->flag_type,
                'description' => $f->description,
                'status' => $f->status,
            ])
            ->all();

        $eligible = count(array_filter($assessments, fn ($a) => $a['overall_result'] === 'eligible'));
        $flagged = count(array_filter($assessments, fn ($a) => $a['overall_result'] === 'flagged_for_review'));

        $summary = [
            ['label' => 'Assessments', 'value' => (string) count($assessments), 'icon' => 'shield-check', 'accent' => 'info'],
            ['label' => 'Eligible', 'value' => (string) $eligible, 'icon' => 'check-circle', 'accent' => 'success'],
            ['label' => 'Flagged for Review', 'value' => (string) $flagged, 'icon' => 'warning', 'accent' => 'danger'],
        ];

        return view('admin.eligibility.index', compact('assessments', 'visitorFlags', 'summary'));
    }
}
