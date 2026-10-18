<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * staff_profiles.photo_path — the officer's own profile picture, stored on the
 * private "local" disk (storage/app/private/staff-photos) and served only to
 * signed-in staff. NULL = no picture, the sidebar shows initials instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable()->after('personal_email');
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
