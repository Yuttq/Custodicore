<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visitor's own photo, taken in the mobile app when they create their
 * account. Staff compare it with the government ID during Account Review and
 * see it on the visitor's profile and at the front desk. Private "local" disk
 * (like PDL and staff photos), served only through authenticated routes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable()->after('relationship_hint');
            $table->timestamp('photo_uploaded_at')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->dropColumn(['photo_path', 'photo_uploaded_at']);
        });
    }
};
