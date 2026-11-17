<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Special visiting days: a Record Officer may open visiting on a date that is
 * not one of a classification's regular days (e.g. a Monday). Such a day is
 * stored as one-day rules (effective_from = effective_to = the date) with the
 * reason here. A non-null special_reason marks the rule as special, so it is
 * left out of the weekly schedule shown to staff and visitors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_visitation_rules', function (Blueprint $table) {
            $table->string('special_reason', 500)->nullable()->after('effective_to');
        });
    }

    public function down(): void
    {
        Schema::table('facility_visitation_rules', function (Blueprint $table) {
            $table->dropColumn('special_reason');
        });
    }
};
