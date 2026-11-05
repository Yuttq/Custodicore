<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The relationship the visitor picked in the app at registration (spouse,
 * parent, ...). Account Review pre-fills it so one Approve can link the PDL
 * without the officer asking again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->string('intended_relationship_type', 40)->nullable()->after('intended_pdl_name');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->dropColumn('intended_relationship_type');
        });
    }
};
