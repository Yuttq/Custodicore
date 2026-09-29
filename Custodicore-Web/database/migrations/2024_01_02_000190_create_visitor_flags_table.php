<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 6.2 — the visitor-history record the "history check" reads from
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_flags', function (Blueprint $table) {
            $table->id('flag_id');
            $table->foreignId('visitor_id')->constrained('visitor_profiles', 'visitor_id')->cascadeOnDelete();
            $table->enum('flag_type', [
                'denied_visit', 'disciplinary_issue', 'officer_conflict',
                'rule_violation', 'prohibited_item_attempt', 'other',
            ]);
            $table->text('description');
            $table->foreignId('related_visit_request_id')->nullable()->constrained('visit_requests', 'visit_request_id')->nullOnDelete();
            $table->foreignId('flagged_by')->constrained('staff_profiles', 'staff_id');
            $table->enum('status', ['active', 'resolved'])->default('active');
            $table->foreignId('resolved_by')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_flags');
    }
};
