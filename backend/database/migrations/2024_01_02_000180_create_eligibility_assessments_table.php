<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 6.1 — one row per visit request, the four checks from Module 1.6
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eligibility_assessments', function (Blueprint $table) {
            $table->id('assessment_id');
            $table->foreignId('visit_request_id')->unique()->constrained('visit_requests', 'visit_request_id')->cascadeOnDelete();
            $table->enum('identity_check_result', ['pass', 'flagged', 'rejected']);
            $table->enum('relationship_check_result', ['pass', 'requires_review']);
            $table->enum('history_check_result', ['clean', 'has_prior_violations']);
            $table->enum('pdl_restriction_check_result', ['no_restriction', 'restricted']);
            $table->enum('overall_result', ['eligible', 'flagged_for_review', 'rejected']);
            $table->string('flagged_reason', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('assessed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eligibility_assessments');
    }
};
