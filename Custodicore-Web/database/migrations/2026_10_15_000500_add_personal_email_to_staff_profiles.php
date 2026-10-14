<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * staff_profiles.personal_email — the officer's own, always-checked inbox
 * (e.g. a Gmail address). The setup link is emailed HERE.
 *
 * It is separate from accounts.email, which stays the official sign-in
 * address (e.g. j.delacruz@bjmp.gov.ph). Nullable so staff created before
 * this column existed keep working: for them the link goes to accounts.email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->string('personal_email', 100)->nullable()->after('contact_number');
            $table->index('personal_email', 'staff_profiles_personal_email_index');
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropIndex('staff_profiles_personal_email_index');
            $table->dropColumn('personal_email');
        });
    }
};
