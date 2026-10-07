<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email ownership for visitor accounts (Phase 2). NULL = the visitor has
 * not yet proven they control the address and cannot sign in to the app.
 *
 * Separate from visitor_profiles.verification_status (staff review of the
 * visitor's information and documents) — the two are never combined.
 *
 * Every account that already exists when this runs is backfilled as
 * verified (from its created_at): they were created before email
 * verification existed and must keep signing in. Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });

        DB::table('accounts')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
