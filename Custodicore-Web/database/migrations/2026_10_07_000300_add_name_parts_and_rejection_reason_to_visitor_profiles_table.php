<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - first_name / last_name: the separate First Name / Last Name the mobile
 *   registration collects (full_name is still stored and still used
 *   everywhere). Nullable: older profiles only have full_name.
 * - rejection_reason: the visitor-facing reason staff give when rejecting
 *   a visitor's submitted information/documents. Cleared on approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('full_name');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('rejection_reason', 500)->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'rejection_reason']);
        });
    }
};
