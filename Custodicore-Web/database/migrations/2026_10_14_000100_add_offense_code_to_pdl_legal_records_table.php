<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which listed offense a case is for (App\Support\Offenses), or 'other' with
 * the officer's own wording in `offense`. The PDL's classification is derived
 * from these codes. Nullable: older cases only have free-text `offense`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdl_legal_records', function (Blueprint $table) {
            $table->string('offense_code', 40)->nullable()->after('case_number');
            $table->index('offense_code');
        });
    }

    public function down(): void
    {
        Schema::table('pdl_legal_records', function (Blueprint $table) {
            $table->dropIndex(['offense_code']);
            $table->dropColumn('offense_code');
        });
    }
};
