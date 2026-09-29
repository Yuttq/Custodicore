<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 5.1 — the fixed policy from the brief (drug_related: Thu/Sat,
// non_drug_related: Fri/Sun; 9-11:30am & 1-4:30pm) as data, not hard-coded logic
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_visitation_rules', function (Blueprint $table) {
            $table->id('rule_id');
            $table->enum('pdl_classification', ['drug_related', 'non_drug_related']);
            $table->enum('day_of_week', ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
            $table->time('time_slot_start');
            $table->time('time_slot_end');
            $table->unsignedInteger('max_capacity');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_visitation_rules');
    }
};
