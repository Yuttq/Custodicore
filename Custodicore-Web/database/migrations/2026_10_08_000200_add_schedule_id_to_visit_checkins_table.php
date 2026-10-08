<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A whole-day visit is entered twice: morning check-in, midday exit, then
 * afternoon re-entry and final check-out. visit_checkins held one row per
 * visit (unique visit_request_id) with a single check-in/check-out pair, so
 * the second entry would overwrite the first. Each gate record now belongs to
 * one session of the visit: unique (visit_request_id, schedule_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_checkins', function (Blueprint $table) {
            $table->foreignId('schedule_id')->nullable()->after('visit_request_id')
                ->constrained('visit_schedules', 'schedule_id');
        });

        // Existing check-ins were for the visit's only session.
        DB::statement(
            'UPDATE visit_checkins SET schedule_id = (SELECT visit_requests.schedule_id FROM visit_requests WHERE visit_requests.visit_request_id = visit_checkins.visit_request_id)'
        );

        Schema::table('visit_checkins', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_id')->nullable(false)->change();
            // Added before the old unique index is dropped: MySQL needs an
            // index led by visit_request_id for its foreign key.
            $table->unique(['visit_request_id', 'schedule_id']);
        });

        Schema::table('visit_checkins', function (Blueprint $table) {
            $table->dropUnique(['visit_request_id']);
        });
    }

    public function down(): void
    {
        Schema::table('visit_checkins', function (Blueprint $table) {
            $table->unique('visit_request_id');
        });

        Schema::table('visit_checkins', function (Blueprint $table) {
            $table->dropUnique(['visit_request_id', 'schedule_id']);
            $table->dropConstrainedForeignId('schedule_id');
        });
    }
};
