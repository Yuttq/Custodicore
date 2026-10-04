<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the visitor's self-reported relationship hint from mobile registration.
 * This is NOT a real visitor↔PDL relationship — staff create/verify those later
 * once a specific PDL is known (see visitor_pdl_relationships).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->string('relationship_hint', 100)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->dropColumn('relationship_hint');
        });
    }
};
