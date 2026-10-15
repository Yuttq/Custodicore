<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guardian Information from mobile registration (Legal Guardian visitors):
 * the guardian's name, their relationship to the visitor and a contact
 * number, shown to staff reviewing the account. Self-reported and nullable —
 * older registrations and every other relationship have none. Separate from
 * emergency_contact_*, which the visitor edits later from Personal Information.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->string('guardian_full_name', 150)->nullable()->after('relationship_hint');
            $table->string('guardian_relationship', 100)->nullable()->after('guardian_full_name');
            $table->string('guardian_contact_number', 20)->nullable()->after('guardian_relationship');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->dropColumn(['guardian_full_name', 'guardian_relationship', 'guardian_contact_number']);
        });
    }
};
