<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional Middle Name and Suffix (Jr., Sr., II, III, IV, V) for visitors,
 * so visitor names follow the same Last / First / Middle / Suffix standard
 * as PDLs and staff. The VisitorProfile model keeps full_name (natural
 * order, read by the mobile app and every match/duplicate check) in sync.
 *
 * Nullable and not backfilled: older profiles keep their full_name as is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('suffix', 20)->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->dropColumn(['middle_name', 'suffix']);
        });
    }
};
