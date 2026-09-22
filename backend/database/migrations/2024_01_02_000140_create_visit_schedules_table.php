<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 5.2 — a concrete, dated slot generated from a rule
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_schedules', function (Blueprint $table) {
            $table->id('schedule_id');
            $table->foreignId('rule_id')->constrained('facility_visitation_rules', 'rule_id');
            $table->date('schedule_date');
            $table->time('time_slot_start');
            $table->time('time_slot_end');
            $table->unsignedInteger('max_capacity');
            $table->unsignedInteger('slots_taken')->default(0);
            $table->enum('status', ['open', 'full', 'closed'])->default('open');

            $table->unique(['schedule_date', 'time_slot_start', 'rule_id']);
            $table->index(['schedule_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_schedules');
    }
};
