<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-in visitors: people without a phone, registered by the Front Desk
 * (App\Services\WalkInService). Their account has no email unless they gave
 * one, so accounts.email becomes nullable (MySQL's unique index allows any
 * number of NULLs). The profile records who registered it at the desk, and
 * a visit booked for them at the desk is marked is_walk_in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('email', 100)->nullable()->change();
        });

        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->enum('registered_via', ['app', 'walk_in'])->default('app')->after('verification_status');
            $table->foreignId('walk_in_registered_by')->nullable()->after('registered_via')
                ->constrained('staff_profiles', 'staff_id')->nullOnDelete();
        });

        Schema::table('visit_requests', function (Blueprint $table) {
            $table->boolean('is_walk_in')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->dropColumn('is_walk_in');
        });

        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('walk_in_registered_by');
            $table->dropColumn('registered_via');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('email', 100)->nullable(false)->change();
        });
    }
};
