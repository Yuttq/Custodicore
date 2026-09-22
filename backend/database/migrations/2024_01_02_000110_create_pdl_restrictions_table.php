<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 4.4 — what the Module 1.6 eligibility check reads
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdl_restrictions', function (Blueprint $table) {
            $table->id('restriction_id');
            $table->foreignId('pdl_id')->constrained('pdl_profiles', 'pdl_id')->cascadeOnDelete();
            $table->enum('restriction_type', [
                'disciplinary', 'quarantine', 'court_order', 'transfer_pending',
                'legal_prohibition', 'victim_related', 'restraining_order',
            ]);
            $table->enum('status', ['active', 'lifted'])->default('active');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('reason', 255);
            $table->foreignId('imposed_by')->constrained('staff_profiles', 'staff_id');
            $table->timestamps();

            $table->index(['pdl_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdl_restrictions');
    }
};
