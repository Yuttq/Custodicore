<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A visit (visit_requests row) is one visitor's visit to one PDL on one day.
 * It may cover one or both of that day's sessions (morning 09:00–11:30,
 * afternoon 13:00–16:30); each session is a visit_schedules row and is what
 * consumes capacity. visit_requests.schedule_id can only name one schedule,
 * so the sessions are listed here. visit_requests.schedule_id stays as the
 * visit's first session (every existing date/ordering query relies on it)
 * and is always also listed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_sessions', function (Blueprint $table) {
            $table->id('visit_session_id');
            $table->foreignId('visit_request_id')->constrained('visit_requests', 'visit_request_id')->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('visit_schedules', 'schedule_id');

            $table->unique(['visit_request_id', 'schedule_id']);
            $table->index('schedule_id');
        });

        // Every existing visit is a single-session visit.
        DB::statement(
            'INSERT INTO visit_sessions (visit_request_id, schedule_id) SELECT visit_request_id, schedule_id FROM visit_requests'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_sessions');
    }
};
