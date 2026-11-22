<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up of accounts returned for correction (App\Services\ReturnedAccounts):
 *   return_reminded_at — when the visitor was reminded (once per return)
 *   return_closed_at   — when the returned account was closed (staff, or
 *                        automatically after a long wait). verification_status
 *                        stays 'rejected' so the mobile app sees nothing new;
 *                        a correction from the visitor reopens it.
 *   return_closed_by   — the officer who closed it (null: closed automatically)
 * All three are cleared when the account is returned again or resubmitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->timestamp('return_reminded_at')->nullable();
            $table->timestamp('return_closed_at')->nullable();
            $table->foreignId('return_closed_by')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_closed_by');
            $table->dropColumn(['return_reminded_at', 'return_closed_at']);
        });
    }
};
