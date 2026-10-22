<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which cases a dorm is meant for (drug-related / non-drug-related; null =
 * any). Guidance only: overcrowded dorms take either classification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cell_blocks', function (Blueprint $table) {
            $table->string('intended_classification', 30)->nullable()->after('designation');
        });
    }

    public function down(): void
    {
        Schema::table('cell_blocks', function (Blueprint $table) {
            $table->dropColumn('intended_classification');
        });
    }
};
