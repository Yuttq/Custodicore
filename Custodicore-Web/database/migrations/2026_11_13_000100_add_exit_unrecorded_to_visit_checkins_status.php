<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `exit_unrecorded`: a gate record a Front Desk officer closed because the
 * visitor was never checked out at the gate (staff forgot). No exit was
 * witnessed, so check_out_time, check_out_officer_id and id_returned_time
 * stay empty; who closed it, why, and where the visitor's ID is are in the
 * audit log. See CheckinCheckoutController::closeUnrecordedExit().
 */
return new class extends Migration
{
    private const BASE = ['awaiting_arrival', 'checked_in', 'checked_out', 'no_show'];

    public function up(): void
    {
        Schema::table('visit_checkins', function (Blueprint $table) {
            $table->enum('status', [...self::BASE, 'exit_unrecorded'])->default('awaiting_arrival')->change();
        });
    }

    public function down(): void
    {
        Schema::table('visit_checkins', function (Blueprint $table) {
            $table->enum('status', self::BASE)->default('awaiting_arrival')->change();
        });
    }
};
