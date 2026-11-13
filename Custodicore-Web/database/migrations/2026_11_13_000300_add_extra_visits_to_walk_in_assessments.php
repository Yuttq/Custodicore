<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More days the Front Desk picked for the same walk-in at registration,
 * besides requested_date / requested_start_times: a list of
 * {date, start_times}. Approving the walk-in books each of them too
 * (App\Services\WalkInService::assess()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walk_in_assessments', function (Blueprint $table) {
            $table->json('extra_visits')->nullable()->after('requested_start_times');
        });
    }

    public function down(): void
    {
        Schema::table('walk_in_assessments', function (Blueprint $table) {
            $table->dropColumn('extra_visits');
        });
    }
};
