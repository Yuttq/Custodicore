<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Name suffix (Jr., Sr., II, III, IV, V) for PDLs, so a father and son with
 * the same name and the duplicate-person check (name + date of birth) can
 * tell them apart. The Pdl model adds it to the end of full_name on save.
 *
 * Nullable and not backfilled: older records keep their full_name as is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdl_profiles', function (Blueprint $table) {
            $table->string('suffix', 20)->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('pdl_profiles', function (Blueprint $table) {
            $table->dropColumn('suffix');
        });
    }
};
