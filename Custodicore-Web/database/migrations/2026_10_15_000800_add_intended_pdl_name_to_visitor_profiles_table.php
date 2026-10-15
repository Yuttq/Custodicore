<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name of the PDL the visitor wants to visit, as typed at mobile
 * registration. Self-reported and unverified: a search hint for the Record
 * Officer, who links the PDL by hand ("Add PDL Relationship"). Never matched
 * automatically. Nullable — the field is optional and older rows have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->string('intended_pdl_name', 150)->nullable()->after('relationship_hint');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->dropColumn('intended_pdl_name');
        });
    }
};
