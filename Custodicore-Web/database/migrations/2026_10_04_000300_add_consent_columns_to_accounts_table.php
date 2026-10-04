<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHEN and to WHICH VERSION of the Terms & Conditions / Privacy
 * Policy (config/legal.php) each account agreed at registration.
 *
 * Nullable on purpose: accounts created before this migration (and the
 * seeded demo accounts) have no recorded consent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('consent_version', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'privacy_accepted_at', 'consent_version']);
        });
    }
};
