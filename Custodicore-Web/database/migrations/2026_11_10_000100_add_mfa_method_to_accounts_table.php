<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a staff officer does the second sign-in step (see App\Services\Auth\StaffMfaService):
 *
 *   mfa_method  'app'   = code from an authenticator app (needs mfa_secret)
 *               'email' = a 6-digit code emailed at each sign-in (no app, no mfa_secret)
 *               NULL    = not set up yet, or set up before this column existed (treated as 'app')
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'mfa_method')) {
                $table->string('mfa_method', 10)->nullable()->after('mfa_enabled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'mfa_method')) {
                $table->dropColumn('mfa_method');
            }
        });
    }
};
