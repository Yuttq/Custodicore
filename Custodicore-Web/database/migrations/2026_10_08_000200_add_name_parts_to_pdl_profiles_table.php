<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate First / Middle (optional) / Last Name for PDLs, as the Record
 * Officer's register and edit forms now collect them. full_name is still
 * stored — the Pdl model rebuilds it from these parts on save — and is what
 * every list, search and mobile screen keeps reading.
 *
 * Nullable and not backfilled: older PDLs only have full_name, and guessing
 * where a name splits ("Juan Dela Cruz") would store wrong data. The edit
 * form pre-fills a best guess for the officer to confirm instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdl_profiles', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('full_name');
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('last_name', 100)->nullable()->after('middle_name');
        });
    }

    public function down(): void
    {
        Schema::table('pdl_profiles', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'middle_name', 'last_name']);
        });
    }
};
