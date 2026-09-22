<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** Module 1.6 Visitor Eligibility Assessment — flagged / manual-review queue. */
class EligibilityController extends Controller
{
    public function index(): View
    {
        $assessments = [
            ['visitor' => 'Liza P. Aquino', 'pdl' => 'Jerome S. Villareal', 'identity_check' => 'pass', 'relationship_check' => 'requires_review', 'history_check' => 'clean', 'restriction_check' => 'no_restriction', 'overall_result' => 'flagged_for_review'],
            ['visitor' => 'Dante R. Cabrera', 'pdl' => 'Vicente A. Torres', 'identity_check' => 'flagged', 'relationship_check' => 'requires_review', 'history_check' => 'clean', 'restriction_check' => 'no_restriction', 'overall_result' => 'flagged_for_review'],
            ['visitor' => 'Fe M. Lopez', 'pdl' => 'Bea L. Santiago', 'identity_check' => 'pass', 'relationship_check' => 'pass', 'history_check' => 'has_prior_violations', 'restriction_check' => 'no_restriction', 'overall_result' => 'flagged_for_review'],
            ['visitor' => 'Maria D. Santos', 'pdl' => 'Ramon G. Bautista', 'identity_check' => 'pass', 'relationship_check' => 'pass', 'history_check' => 'clean', 'restriction_check' => 'no_restriction', 'overall_result' => 'eligible'],
        ];

        $visitorFlags = [
            ['visitor' => 'Dante R. Cabrera', 'flag_type' => 'rule_violation', 'description' => 'Attempted to bring prohibited item during previous visit', 'status' => 'active'],
            ['visitor' => 'Fe M. Lopez', 'flag_type' => 'denied_visit', 'description' => 'Denied entry on 2026-07-02 — expired ID', 'status' => 'resolved'],
        ];

        return view('admin.eligibility.index', compact('assessments', 'visitorFlags'));
    }
}
