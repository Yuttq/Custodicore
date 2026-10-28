<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff two-step verification (see App\Services\Auth\StaffMfaService).
 *
 *   mfa_secret          the authenticator secret, encrypted with the app key (never plain text)
 *   mfa_enabled_at      when the officer finished setup; NULL = not set up yet
 *   mfa_last_used_step  the last 30-second step accepted, so one code cannot be used twice
 *   mfa_backup_codes    JSON list of keyed hashes of the unused backup codes (the codes themselves
 *                       are shown once and never stored)
 *
 * Visitor accounts keep these columns NULL: two-step verification is for the staff web dashboards only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'mfa_secret')) {
                $table->text('mfa_secret')->nullable();
            }
            if (! Schema::hasColumn('accounts', 'mfa_enabled_at')) {
                $table->timestamp('mfa_enabled_at')->nullable();
            }
            if (! Schema::hasColumn('accounts', 'mfa_last_used_step')) {
                $table->unsignedBigInteger('mfa_last_used_step')->nullable();
            }
            if (! Schema::hasColumn('accounts', 'mfa_backup_codes')) {
                $table->text('mfa_backup_codes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            foreach (['mfa_secret', 'mfa_enabled_at', 'mfa_last_used_step', 'mfa_backup_codes'] as $column) {
                if (Schema::hasColumn('accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
