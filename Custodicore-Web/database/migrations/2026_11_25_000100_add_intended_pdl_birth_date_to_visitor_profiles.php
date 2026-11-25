<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * intended_pdl_birth_date — the PDL's date of birth as the visitor gave it
 * at registration (optional, unverified). With the name, it tells apart PDLs
 * who share a name when the Record Officer links the PDL; it never links one
 * by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->date('intended_pdl_birth_date')->nullable()->after('intended_pdl_name');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->dropColumn('intended_pdl_birth_date');
        });
    }
};
